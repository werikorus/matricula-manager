#!/usr/bin/env bash
# Instala o iniciador no Windows do usuário atual:
#   - compila o PainelMatricula.exe
#   - copia para %LOCALAPPDATA%\PainelMatricula, com o PainelMatricula.ini
#     apontando para esta pasta do painel e esta distro da WSL
#   - cria atalhos no Menu Iniciar e na Área de Trabalho
# Uso (dentro da WSL, na pasta do painel):  bash windows/instalar.sh [porta]
set -euo pipefail

AQUI="$(cd "$(dirname "$0")" && pwd)"
PAINEL="$(cd "$AQUI/.." && pwd)"
PORTA="${1:-8010}"
DISTRO="${WSL_DISTRO_NAME:-Ubuntu}"

bash "$AQUI/compilar.sh"

ps() { (cd /mnt/c && powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$1" | tr -d '\r'); }
LOCAL_WIN="$(ps '[Environment]::GetFolderPath("LocalApplicationData")')"
DESTINO_WIN="$LOCAL_WIN\\PainelMatricula"
DESTINO="$(wslpath "$DESTINO_WIN")"
mkdir -p "$DESTINO"

# Se o iniciador estiver aberto, o .exe fica travado: avisa em vez de falhar no meio.
if ! cp "$AQUI/dist/PainelMatricula.exe" "$DESTINO/PainelMatricula.exe" 2>/dev/null; then
    echo "Não consegui substituir o PainelMatricula.exe: feche o painel pelo ícone da bandeja (Encerrar painel) e rode de novo." >&2
    exit 1
fi

# Mantém a configuração existente; só cria na primeira instalação.
if [ ! -f "$DESTINO/PainelMatricula.ini" ]; then
    printf '%s\r\n' \
        "; Configuração do iniciador do Painel Matrícula" \
        "[painel]" \
        "; Distro da WSL e pasta do painel (caminho Linux)" \
        "Distro=$DISTRO" \
        "Pasta=$PAINEL" \
        "Porta=$PORTA" \
        "; chrome, edge ou padrao (navegador padrão do Windows, em aba comum)" \
        "Navegador=chrome" > "$DESTINO/PainelMatricula.ini"
fi

ps "
\$exe = '$DESTINO_WIN\\PainelMatricula.exe'
\$shell = New-Object -ComObject WScript.Shell
foreach (\$pasta in @([Environment]::GetFolderPath('Programs'), [Environment]::GetFolderPath('Desktop'))) {
    \$atalho = \$shell.CreateShortcut((Join-Path \$pasta 'Painel Matrícula.lnk'))
    \$atalho.TargetPath = \$exe
    \$atalho.WorkingDirectory = '$DESTINO_WIN'
    \$atalho.IconLocation = \"\$exe,0\"
    \$atalho.Description = 'Inicia o Painel Matrícula na WSL e abre a janela do painel'
    \$atalho.Save()
}
"

echo "Instalado em: $DESTINO_WIN"
echo "Atalhos 'Painel Matrícula' criados no Menu Iniciar e na Área de Trabalho."
echo "Configuração: $DESTINO_WIN\\PainelMatricula.ini (Distro=$DISTRO, Pasta=$PAINEL, Porta=$PORTA)"
