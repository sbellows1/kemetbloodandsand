#!/usr/bin/env bash
# Upload game files to BGA Studio over SFTP.
#   tools/deploy-bga.sh              upload the whole deployable payload
#   tools/deploy-bga.sh FILE...      upload only these files (non-deployable paths are skipped)
#   tools/deploy-bga.sh --hook       read a Claude Code PostToolUse JSON payload on stdin
# Connection settings can be overridden with BGA_SFTP_HOST/PORT/USER/KEY/DIR.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOST="${BGA_SFTP_HOST:-1.studio.boardgamearena.com}"
PORT="${BGA_SFTP_PORT:-2022}"
USER_NAME="${BGA_SFTP_USER:-quirked}"
KEY="${BGA_SFTP_KEY:-$HOME/.ssh/id_rsa_bga}"
REMOTE="${BGA_SFTP_DIR:-/kemetbloodandsands}"

deployable() {
  case "$1" in
    dbmodel.sql|gameinfos.jsonc|gameoptions.jsonc|gamepreferences.jsonc|stats.jsonc|kemetbloodandsands.css) return 0 ;;
    modules/js/*.js|modules/php/*.php|modules/php/States/*.php) return 0 ;;
    img/*.jpg|img/*.png) return 0 ;;
  esac
  return 1
}

files=()
if [ "${1:-}" = "--hook" ]; then
  payload="$(cat)"
  f="$(printf '%s' "$payload" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const j=JSON.parse(s);process.stdout.write((j.tool_response&&j.tool_response.filePath)||(j.tool_input&&j.tool_input.file_path)||"")})')"
  [ -n "$f" ] || exit 0
  set -- "$f"
fi

if [ $# -eq 0 ]; then
  cd "$ROOT"
  for f in dbmodel.sql gameinfos.jsonc gameoptions.jsonc gamepreferences.jsonc stats.jsonc kemetbloodandsands.css \
           modules/js/*.js modules/php/*.php modules/php/States/*.php img/*.jpg img/*.png; do
    [ -f "$f" ] && files+=("$f")
  done
else
  for f in "$@"; do
    f="${f//\\//}"
    # Make the path relative to the repo root (handles C:/..., /c/... and relative forms).
    case "$f" in
      [A-Za-z]:/*) f="/$(printf '%s' "${f:0:1}" | tr 'A-Z' 'a-z')${f:2}" ;;
    esac
    rootlc="$(printf '%s' "$ROOT" | tr 'A-Z' 'a-z')"
    flc="$(printf '%s' "$f" | tr 'A-Z' 'a-z')"
    if [ "${flc#"$rootlc"/}" != "$flc" ]; then f="${f:$(( ${#ROOT} + 1 ))}"; fi
    deployable "$f" && [ -f "$ROOT/$f" ] && files+=("$f")
  done
fi

[ ${#files[@]} -gt 0 ] || exit 0

batch="$(mktemp)"
trap 'rm -f "$batch"' EXIT
for d in modules modules/js modules/php modules/php/States img; do echo "-mkdir $REMOTE/$d"; done > "$batch"
for f in "${files[@]}"; do echo "put \"$f\" \"$REMOTE/$f\""; done >> "$batch"

cd "$ROOT"
if ! err="$(sftp -q -P "$PORT" -i "$KEY" -o StrictHostKeyChecking=accept-new -o BatchMode=yes -b "$batch" "$USER_NAME@$HOST" 2>&1 >/dev/null)"; then
  printf '%s\n' "$err" | grep -v 'remote mkdir' >&2
  echo "BGA Studio deploy FAILED for: ${files[*]}" >&2
  exit 1
fi
echo "Deployed to BGA Studio: ${files[*]}"
