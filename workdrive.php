<?php
/* =============================================================
   workdrive.php — Zoho WorkDrive read access (own token cache)
   -------------------------------------------------------------
   Mints a WorkDrive access token from your WorkDrive refresh
   token (separate from the Books token in data/token.json, so
   Books stays untouched), lists every file in a folder, and
   extracts the numeric invoice tokens from messy filenames.

   Reuses config.php keys already in your app:
     client_id, client_secret, accounts_url, api_domain
   Plus two NEW keys you add to config.php:
     workdrive_refresh_token, workdrive_folder_id
   (No workdrive_api_domain needed — derived from api_domain.)
   ============================================================= */

if (!defined('ETR_INTERNAL')) { http_response_code(403); exit('Forbidden'); }

function wd_cfg() {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    return $c;
}

/* WorkDrive refresh token: from config.php OR a plain text file
   (data/wd_refresh_token.txt) so config never needs hand-editing. */
function wd_refresh_token() {
    $c = wd_cfg();
    if (!empty($c['workdrive_refresh_token'])) return trim($c['workdrive_refresh_token']);
    $f = __DIR__ . '/data/wd_refresh_token.txt';
    if (is_file($f)) {
        $t = trim((string)@file_get_contents($f));
        if ($t !== '') return $t;
    }
    return '';
}

/* mint / cache WorkDrive access token */
function wd_access_token() {
    $c = wd_cfg();
    $cacheFile = __DIR__ . '/data/wd_token.json';
    if (is_file($cacheFile)) {
        $t = json_decode(@file_get_contents($cacheFile), true);
        if ($t && isset($t['expires_at']) && $t['expires_at'] > time() + 60) return $t['access_token'];
    }
    $refresh = wd_refresh_token();
    if ($refresh === '') {
        throw new Exception("No WorkDrive token found. Create data/wd_refresh_token.txt containing only the token (or add 'workdrive_refresh_token' to config.php).");
    }
    $ch = curl_init(rtrim($c['accounts_url'], '/') . '/oauth/v2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'refresh_token',
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'refresh_token' => $refresh,
        ]),
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) throw new Exception('WorkDrive token request failed: ' . $err);
    $d = json_decode($res, true);
    if (empty($d['access_token'])) {
        $e = $d['error'] ?? 'unknown';
        throw new Exception("WorkDrive token error: $e — if 'invalid_scope', re-mint with WorkDrive.files.READ,WorkDrive.team.READ then delete data/wd_token.json");
    }
    $d['expires_at'] = time() + (int)($d['expires_in'] ?? 3600);
    if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0775, true);
    @file_put_contents($cacheFile, json_encode($d));
    return $d['access_token'];
}

/* WorkDrive API base derived from your existing api_domain host */
function wd_base() {
    $c = wd_cfg();
    if (!empty($c['workdrive_api_domain'])) return rtrim($c['workdrive_api_domain'], '/');
    return rtrim($c['api_domain'], '/') . '/workdrive/api/v1';
}

/* low-level GET with one 401 retry */
function wd_get($path, $query = []) {
    $url = wd_base() . $path . ($query ? '?' . http_build_query($query) : '');
    for ($try = 0; $try < 2; $try++) {
        $token = wd_access_token();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: Zoho-oauthtoken ' . $token,
                'Accept: application/vnd.api+json',
            ],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err) throw new Exception('WorkDrive GET failed: ' . $err);
        if ($code === 401 && $try === 0) { @unlink(__DIR__ . '/data/wd_token.json'); continue; }
        if ($code < 200 || $code >= 300) {
            $detail = '';
            $j = json_decode($res, true);
            if (isset($j['errors'][0])) {
                $e0 = $j['errors'][0];
                $detail = $e0['detail'] ?? $e0['title'] ?? '';
            }
            if ($detail === '') $detail = substr((string)$res, 0, 200);
            throw new Exception('WORKDRIVE (HTTP ' . $code . '): ' . $detail);
        }
        $d = json_decode($res, true);
        if (!is_array($d)) throw new Exception('WorkDrive returned non-JSON');
        return $d;
    }
    throw new Exception('WorkDrive auth failed after retry');
}

/* list every filename in a folder (auto-paginate, 50/page) */
function wd_list_folder_filenames($folderId) {
    $names = [];
    $offset = 0; $limit = 50; $guard = 0;
    do {
        $d = wd_get('/files/' . rawurlencode($folderId) . '/files', [
            'page[limit]'  => $limit,
            'page[offset]' => $offset,
            'filter[type]' => 'allfiles',
        ]);
        $rows = $d['data'] ?? [];
        foreach ($rows as $row) {
            $a = $row['attributes'] ?? [];
            if (!empty($a['is_folder'])) continue;
            if (isset($a['name'])) $names[] = $a['name'];
        }
        $got = count($rows);
        $offset += $limit;
        $guard++;
    } while ($got === $limit && $guard < 200);
    return $names;
}

/* set of numeric tokens (3-5 digits) present across all filenames */
function wd_filename_number_set($folderId) {
    $m = wd_filename_number_map($folderId);
    return ['set' => $m['map'], 'file_count' => $m['file_count']];
}

/* map of numeric token -> first filename, merged across one or more folders */
function wd_filename_number_map($folderIds) {
    $map = [];
    $fileCount = 0;
    foreach ((array)$folderIds as $fid) {
        $fid = trim($fid);
        if ($fid === '') continue;
        $names = wd_list_folder_filenames($fid);
        $fileCount += count($names);
        foreach ($names as $name) {
            if (preg_match_all('/\d{3,5}/', $name, $m)) {
                foreach ($m[0] as $num) {
                    if (!isset($map[$num])) $map[$num] = $name;
                    $un = ltrim($num, '0');
                    if ($un !== '' && !isset($map[$un])) $map[$un] = $name;
                }
            }
        }
    }
    return ['map' => $map, 'file_count' => $fileCount];
}

/* full file rows (name, id, link) for a folder */
function wd_list_folder_files($folderId) {
    $out = [];
    $offset = 0; $limit = 50; $guard = 0;
    do {
        $d = wd_get('/files/' . rawurlencode($folderId) . '/files', [
            'page[limit]'  => $limit,
            'page[offset]' => $offset,
            'filter[type]' => 'allfiles',
        ]);
        $rows = $d['data'] ?? [];
        foreach ($rows as $row) {
            $a = $row['attributes'] ?? [];
            if (!empty($a['is_folder'])) continue;
            if (!isset($a['name'])) continue;
            $out[] = [
                'name' => $a['name'],
                'id'   => $row['id'] ?? '',
                'link' => $a['Permalink'] ?? ($a['permalink'] ?? ($a['download_url'] ?? '')),
            ];
        }
        $got = count($rows); $offset += $limit; $guard++;
    } while ($got === $limit && $guard < 200);
    return $out;
}

/* map of numeric token -> {name,id,link}, merged across folders */
function wd_filename_file_map($folderIds) {
    $map = [];
    foreach ((array)$folderIds as $fid) {
        $fid = trim($fid);
        if ($fid === '') continue;
        foreach (wd_list_folder_files($fid) as $f) {
            if (preg_match_all('/\d{3,5}/', $f['name'], $m)) {
                foreach ($m[0] as $num) {
                    if (!isset($map[$num])) $map[$num] = $f;
                    $un = ltrim($num, '0');
                    if ($un !== '' && !isset($map[$un])) $map[$un] = $f;
                }
            }
        }
    }
    return $map;
}

/* default ETR/invoice PDF folders (shared) */
function wd_default_folders() {
    $c = wd_cfg();
    $f = $c['workdrive_folder_ids']
        ?? ['0mqdi73cabe780dcf49adb599e8e650cf893e', 'gfyj5e8ce417a75ca49a7a9641d2931dbae25'];
    if (is_string($f)) $f = array_filter(array_map('trim', explode(',', $f)));
    return $f;
}

/* Upload a file's contents into a WorkDrive folder.
   Needs the WorkDrive token minted with WorkDrive.files.CREATE (or .ALL).
   Returns ['id'=>resource_id, 'name'=>filename] on success; throws on failure. */
function wd_upload_file($parentId, $filename, $content, $mime = 'application/octet-stream') {
    if ($parentId === '' || $parentId === null) throw new Exception('No WorkDrive folder set for backups.');
    $tmp = tempnam(sys_get_temp_dir(), 'wdup');
    @file_put_contents($tmp, $content);
    try {
        for ($try = 0; $try < 2; $try++) {
            $token = wd_access_token();
            $ch = curl_init(wd_base() . '/upload');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
                CURLOPT_HTTPHEADER => ['Authorization: Zoho-oauthtoken ' . $token],
                CURLOPT_POSTFIELDS => [
                    'parent_id'           => $parentId,
                    'filename'            => $filename,
                    'override-name-exist' => 'true',
                    'content'             => new CURLFile($tmp, $mime, $filename),
                ],
            ]);
            $res  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($err) throw new Exception('WorkDrive upload failed: ' . $err);
            if ($code === 401 && $try === 0) { @unlink(__DIR__ . '/data/wd_token.json'); continue; }
            $j = json_decode($res, true);
            if ($code < 200 || $code >= 300) {
                $detail = $j['errors'][0]['detail'] ?? $j['errors'][0]['title'] ?? substr((string)$res, 0, 220);
                throw new Exception('WORKDRIVE upload (HTTP ' . $code . '): ' . $detail);
            }
            $node = $j['data'] ?? null;
            if (isset($node[0])) $node = $node[0];
            $rid  = $node['attributes']['resource_id'] ?? ($node['id'] ?? '');
            return ['id' => $rid, 'name' => $filename];
        }
        throw new Exception('WorkDrive upload auth failed after retry');
    } finally {
        @unlink($tmp);
    }
}

/* folder metadata: name + parent (for breadcrumb / "Up") */
function wd_folder_info($folderId) {
    $d = wd_get('/files/' . rawurlencode($folderId));
    $a = $d['data']['attributes'] ?? [];
    return [
        'id'        => $d['data']['id'] ?? $folderId,
        'name'      => $a['name'] ?? '(folder)',
        'parent_id' => $a['parent_id'] ?? ($a['parent_id_in_millisecond'] ?? ''),
    ];
}

/* list only the SUB-FOLDERS inside a folder (for the picker) */
function wd_list_subfolders($folderId) {
    $out = []; $offset = 0; $limit = 50; $guard = 0;
    do {
        $d = wd_get('/files/' . rawurlencode($folderId) . '/files', [
            'page[limit]'  => $limit,
            'page[offset]' => $offset,
            'filter[type]' => 'folder',
        ]);
        $rows = $d['data'] ?? [];
        foreach ($rows as $row) {
            $a = $row['attributes'] ?? [];
            $out[] = ['id' => $row['id'] ?? '', 'name' => $a['name'] ?? '(folder)'];
        }
        $got = count($rows); $offset += $limit; $guard++;
    } while ($got === $limit && $guard < 60);
    return $out;
}
