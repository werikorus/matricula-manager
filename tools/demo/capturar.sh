#!/usr/bin/env bash
# Tira as capturas do README a partir da instância de demonstração já no ar.
# Uso: bash tools/demo/capturar.sh [porta]   → grava em docs/imagens/
#
# O Chromium da WSL é snap e só grava dentro de ~/snap/chromium; por isso as
# imagens passam por lá antes de ir para docs/imagens.
set -euo pipefail

painel="$(cd "$(dirname "$0")/../.." && pwd)"
porta="${1:-8099}"
temp="$HOME/snap/chromium/common/painel-capturas"
mkdir -p "$temp" "$painel/docs/imagens"

# Recria os dados fictícios: navegar na demo antes soma acessos e buscas ao Início.
XDEBUG_MODE=off php "$painel/tools/demo/seed.php" >/dev/null

capturar() {
    google-chrome --headless=new --no-sandbox --disable-gpu --hide-scrollbars \
        --user-data-dir="$temp/perfil" --window-size=1600,1000 --virtual-time-budget=5000 \
        --screenshot="$temp/$1.png" "http://127.0.0.1:${porta}$2" >/dev/null 2>&1
    cp "$temp/$1.png" "$painel/docs/imagens/$1.png"
    echo "docs/imagens/$1.png"
}

capturar inicio /
capturar commands '/commands?execucao=8'
capturar matriculas /matriculas
capturar pabs /pabs
capturar alunos '/alunos?q=ana+clara'
capturar historico /historico

rm -rf "$temp"
