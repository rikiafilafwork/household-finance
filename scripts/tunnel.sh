#!/usr/bin/env bash
# Buka stack lokal (nginx :8080) ke internet lewat Cloudflare Quick Tunnel untuk testing
# (mis. dari HP). URL acak *.trycloudflare.com, tanpa akun Cloudflare.
#
# Selama tunnel hidup, apps/api/.env diubah sementara (dipulihkan saat Ctrl+C / kill):
#   APP_DEBUG=false            stack trace / env tidak tampil ke pengunjung
#   REGISTRATION_ENABLED=false hanya dengan --closed (default: registrasi mengikuti .env, supaya
#                              penguji bisa mencoba alur Daftar)
# SANCTUM_STATEFUL_DOMAINS ditambah *.trycloudflare.com (permanen; tidak berbahaya di lokal).
#
# Pakai: scripts/tunnel.sh [--closed]   (port default 8080, atau APP_PORT)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/apps/api/.env"
PORT="${APP_PORT:-8080}"
BACKUP="$(mktemp -t pf-env-backup)"
CLOSED=false
for arg in "$@"; do
  case "$arg" in
    --closed) CLOSED=true ;;
    *) echo "Opsi tidak dikenal: $arg (pakai --closed untuk menutup registrasi)" >&2; exit 2 ;;
  esac
done

command -v cloudflared >/dev/null || { echo "cloudflared belum terpasang: brew install cloudflared" >&2; exit 1; }
curl -fsS -o /dev/null "http://localhost:${PORT}/" || { echo "Stack lokal tidak menjawab di :${PORT}. Jalankan: docker compose up -d" >&2; exit 1; }

# set_env KEY VALUE — ganti baris KEY=..., atau tambahkan kalau belum ada.
set_env() {
  if grep -q "^$1=" "$ENV_FILE"; then
    sed -i.bak "s|^$1=.*|$1=$2|" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
  else
    printf '%s=%s\n' "$1" "$2" >>"$ENV_FILE"
  fi
}

cp "$ENV_FILE" "$BACKUP"
restore() {
  # Pulihkan hanya dua kunci yang diubah sementara, supaya edit lain selama tunnel hidup tidak hilang.
  for key in APP_DEBUG REGISTRATION_ENABLED; do
    original="$(grep "^${key}=" "$BACKUP" || true)"
    if [ -n "$original" ]; then set_env "$key" "${original#*=}"; else sed -i.bak "/^${key}=/d" "$ENV_FILE" && rm -f "$ENV_FILE.bak"; fi
  done
  rm -f "$BACKUP"
  echo "apps/api/.env dipulihkan (APP_DEBUG, REGISTRATION_ENABLED)."
}
trap restore EXIT
trap 'exit 130' INT TERM

stateful="$(grep '^SANCTUM_STATEFUL_DOMAINS=' "$ENV_FILE" | cut -d= -f2- || true)"
case ",$stateful," in
  *",*.trycloudflare.com,"*) ;;
  *) set_env SANCTUM_STATEFUL_DOMAINS "${stateful:+$stateful,}*.trycloudflare.com" ;;
esac
set_env APP_DEBUG false
if $CLOSED; then set_env REGISTRATION_ENABLED false; fi
echo "Registrasi: $(grep '^REGISTRATION_ENABLED=' "$ENV_FILE" | cut -d= -f2- || echo true) (ubah dengan --closed)"

echo "Tunnel ke http://localhost:${PORT} … (URL https://*.trycloudflare.com muncul di log di bawah; Ctrl+C untuk berhenti)"
cloudflared tunnel --no-autoupdate --url "http://localhost:${PORT}"
