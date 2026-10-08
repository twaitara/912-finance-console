#!/usr/bin/env bash
# ============================================================================
# One-step deploy from this PC.
#   1) pushes your commits to GitHub
#   2) uploads the app files straight to cPanel over FTP(S)
# Run it from Git Bash:   bash deploy.sh      (or double-click deploy.cmd)
#
# Your FTP details live in .deploy.env (git-ignored, never committed/pushed).
# Copy .deploy.env.example -> .deploy.env and fill it in once.
# config.php / mail_token.php are NEVER uploaded, so live secrets are safe.
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")"

[ -f .deploy.env ] || { echo "!! Missing .deploy.env — copy .deploy.env.example to .deploy.env and fill it in."; exit 1; }
set -a; . ./.deploy.env; set +a
: "${FTP_HOST:?set FTP_HOST in .deploy.env}"
: "${FTP_USER:?set FTP_USER in .deploy.env}"
: "${FTP_PASS:?set FTP_PASS in .deploy.env}"

REMOTE="${FTP_REMOTE_DIR:-.}"; REMOTE="${REMOTE%/}"; [ "$REMOTE" = "." ] && REMOTE=""
BASE="ftp://${FTP_HOST}/${REMOTE:+$REMOTE/}"
# FTP over TLS. The LOGIN (control channel) is encrypted so your password is
# never sent in clear. The file transfer itself uses the data channel in the
# clear — required because Pure-FTPd + Windows curl can't do TLS session reuse
# on the data channel (causes FTP 451). -k relaxes the hostname check since the
# cPanel FTP cert is for the server's own hostname. Set FTP_TLS=0 for plain FTP.
TLS="--ftp-pasv"
[ "${FTP_TLS:-1}" = "1" ] && TLS="$TLS --ftp-ssl-control"
[ "${FTP_INSECURE:-1}" = "1" ] && TLS="$TLS -k"

# 1) push to GitHub (optional)
if [ "${GIT_PUSH:-1}" = "1" ]; then
  echo "== git push origin main =="
  git push origin main || echo "(git push skipped/failed — continuing with the upload)"
fi

# 2) upload to cPanel over FTP(S). --ftp-method nocwd sends the full path in one
# STOR (no CWD), which avoids "denied you to change to the given directory" on
# restricted accounts. Per-file timeout so a stalled transfer can't hang.
FILES=()
# all root PHP except files that hold or handle live secrets (never uploaded)
for f in *.php; do
  case "$f" in config.php|mail_token.php|calendar_oauth.php) continue;; esac
  [ -f "$f" ] && FILES+=("$f")
done
for f in app.js app.css .htaccess .user.ini; do [ -f "$f" ] && FILES+=("$f"); done
while IFS= read -r -d '' f; do FILES+=("$f"); done < <(find api -type f -not -name 'error_log' -not -name '*.log' -not -name '*.old' -not -name '*.bak' -print0)
[ -f data/.htaccess ] && FILES+=("data/.htaccess")

echo "== Uploading ${#FILES[@]} files to ${FTP_HOST}/${REMOTE:-<home>} =="
UP_FAIL=0
for f in "${FILES[@]}"; do
  printf "  -> %s " "$f"
  if curl -sS $TLS --ftp-method nocwd --connect-timeout 20 -m 90 -T "$f" "${BASE}${f}" --user "${FTP_USER}:${FTP_PASS}"; then echo "ok"; else echo "FAILED"; UP_FAIL=$((UP_FAIL+1)); fi
done
[ "$UP_FAIL" -eq 0 ] && echo "== all files uploaded ==" || echo "== WARNING: $UP_FAIL file(s) failed to upload =="

echo "== DEPLOYED $(git rev-parse --short HEAD 2>/dev/null || echo '?') to ${FTP_HOST}/${REMOTE:-<home>} =="
