#!/usr/bin/env bash
# Compila o iniciador do Windows (PainelMatricula.exe) a partir da WSL, usando
# o csc.exe que já vem com o .NET Framework do Windows. Saída: windows/dist/.
set -euo pipefail

AQUI="$(cd "$(dirname "$0")" && pwd)"
CSC="/mnt/c/Windows/Microsoft.NET/Framework64/v4.0.30319/csc.exe"
[ -x "$CSC" ] || CSC="/mnt/c/Windows/Microsoft.NET/Framework/v4.0.30319/csc.exe"
[ -x "$CSC" ] || { echo "Não encontrei o csc.exe do .NET Framework 4 no Windows." >&2; exit 1; }

# O csc trabalha melhor numa pasta do Windows do que em \\wsl.localhost.
TEMP_WIN="$(cd /mnt/c && powershell.exe -NoProfile -Command '[IO.Path]::GetTempPath()' | tr -d '\r')"
[ -n "$TEMP_WIN" ] || { echo "Não consegui descobrir a pasta temporária do Windows (powershell.exe)." >&2; exit 1; }
OBRA="$(wslpath "$TEMP_WIN")/painel-matricula-build"
rm -rf "$OBRA" && mkdir -p "$OBRA"
cp "$AQUI/Iniciador.cs" "$AQUI/icone.ico" "$OBRA/"

(cd "$OBRA" && "$CSC" /nologo /target:winexe /platform:x64 /optimize+ \
    /win32icon:icone.ico /out:PainelMatricula.exe \
    /r:System.Windows.Forms.dll /r:System.Drawing.dll \
    Iniciador.cs | tr -d '\r')

mkdir -p "$AQUI/dist"
cp "$OBRA/PainelMatricula.exe" "$AQUI/dist/"
rm -rf "$OBRA"
echo "Gerado: $AQUI/dist/PainelMatricula.exe"
