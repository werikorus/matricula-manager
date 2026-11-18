#!/usr/bin/env bash
# Sobe a instância de demonstração (dados fictícios) em http://127.0.0.1:8099.
# Uso: bash tools/demo/subir.sh [porta]
set -euo pipefail

demo="$(cd "$(dirname "$0")" && pwd)"
painel="$(cd "$demo/../.." && pwd)"
porta="${1:-8099}"

mkdir -p "$demo"/storage/app/{execucoes,acessos} \
         "$demo"/storage/framework/{cache/data,sessions,views} \
         "$demo"/storage/logs

XDEBUG_MODE=off php "$demo/seed.php"
echo "Demonstração em http://127.0.0.1:${porta} (Ctrl+C para parar)"
cd "$painel/public"
XDEBUG_MODE=off exec php -S "127.0.0.1:${porta}" "$demo/router.php"
