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
TLS=$([ "${FTP_TLS:-1}" = "1" ] && echo "--ssl-reqd" || echo "")

# 1) push to GitHub (optional)
if [ "${GIT_PUSH:-1}" = "1" ]; then
  echo "== git push origin main =="
  git push origin main || echo "(git push skipped/failed — continuing with the upload)"
fi

# 2) upload to cPanel over FTP(S)
up(){ echo "  -> $2"; curl -fsS $TLS --ftp-create-dirs -T "$1" "${BASE}$2" --user "${FTP_USER}:${FTP_PASS}"; }

echo "== Uploading to ${FTP_HOST}/${REMOTE:-<home>} =="
for f in index.php app.js app.css .htaccess .user.ini; do [ -f "$f" ] && up "$f" "$f"; done
while IFS= read -r -d '' f; do up "$f" "$f"; done < <(find api -type f -print0)
[ -f data/.htaccess ] && up data/.htaccess "data/.htaccess"

echo "== DEPLOYED $(git rev-parse --short HEAD 2>/dev/null || echo '?') to ${FTP_HOST}/${REMOTE:-<home>} =="
