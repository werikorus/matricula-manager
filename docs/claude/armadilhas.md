# Armadilhas e restrições não óbvias

Coisas que já deram problema ou que quebram em silêncio. Leia antes de mexer
na área correspondente.

## Ambiente e máquina

- **WSL2 + Windows.** O painel roda na WSL; o navegador e a VPN são do Windows.
  O IP da requisição é sempre `127.0.0.1` — por isso o "IP da VPN" é descoberto
  com `powershell.exe Find-NetRoute` (`IpDaVpn`) ou fixado em `PAINEL_IP_VPN`.
- **Xdebug ligado na máquina.** Qualquer `php` imprime "Could not connect to
  debugging client". Use `XDEBUG_MODE=off` nos comandos; o painel já o força
  nos commands que dispara (`MatriculaArtisan::ambienteLimpo`).
- **Chromium é snap**: em modo headless só grava arquivos dentro de
  `~/snap/chromium/...` (gravar em `/tmp` falha com "No such file or
  directory"). `tools/demo/capturar.sh` já contorna.
- **Arquivos `*:Zone.Identifier`** aparecem ao lado de arquivos copiados do
  Windows. Estão no `.gitignore`; não os commite nem os edite.
- O painel real normalmente já está no ar em `:8010` (iniciador do Windows).
  Não suba outro na mesma porta; para testes use a demonstração em `:8099`.

## PHP / Laravel

- **PHP 7.4 / Laravel 8**: sem sintaxe de PHP 8 (`match`, `?->`, argumentos
  nomeados, `str_contains`, `enum`, promoted properties). `??=` e arrow
  functions existem no 7.4, mas o estilo do código é `function () use (...)`.
- **Config não está em cache** (`bootstrap/cache/config.php` não existe) e
  não deve estar: `Configuracoes::aplicar()` sobrescreve config em runtime e
  `env()` é lido em `Configuracoes::ambiente()`.
- **Não use `php artisan list` no matrícula**: o `AppServiceProvider` de lá
  consulta o banco no boot e trava sem VPN. Por isso o catálogo é lido como
  texto (`CatalogoDoMatricula`, via `token_get_all`). Se o formato do método
  `commands()` de lá mudar (ex.: deixar de ser um `return [...]` literal), o
  parser falha com mensagem explicando o ponto.
- **Processo filho herda o ambiente**: sem `ambienteLimpo()`, o matrícula
  herdaria `APP_KEY`, `DB_*` etc. do painel. Ao adicionar variáveis ao `.env`
  do painel que o matrícula também usa, inclua-as na lista de remoção.
- **Argumentos posicionais**: `montarArgumentos` recusa preencher um argumento
  depois de um vazio (seria lido no lugar do anterior) e põe opções antes de
  `--` e argumentos depois (um valor como `--env=x` nunca vira opção).
- **Commands rodam com `--no-interaction`**: os que perguntam algo no terminal
  não funcionam pelo painel.

## Regras duplicadas do matrícula

- Telas de paradas **espelham o SQL dos commands** do matrícula para mostrar
  o que eles pegariam (ver [telas.md](telas.md) › "Regras espelhadas"). Se o
  command mudar lá, o painel fica mentindo sem erro nenhum.
- **Nomes de argumento fixos em links de linha**: `pabs/index.blade.php` usa
  `arg.codigoProcessoAdmissao` e `reservas/index.blade.php` usa
  `arg.codigoReserva`. Se o nome mudar no catálogo do matrícula, o campo
  simplesmente não é preenchido. Os POSTs de Matrículas, ao contrário, leem os
  nomes do catálogo (`$comando['argumentos']`) — prefira esse padrão.
- Os nomes dos commands (`MatriculaController::COMANDO`, `PabController::COMANDO`
  etc.) precisam existir no catálogo; senão a tela mostra erro "não está na
  tela Dev > Commands".

## Dados e privacidade

- `storage/app/execucoes/*.log` e `acessos/*.json` **contêm dados de alunos**
  (saída dos commands, nomes buscados). Ficam fora do git; nunca copie trechos
  para docs, commits ou issues.
- O repositório é **público**. Capturas de tela só da demonstração.
- O `.env` do painel e os `.env` do matrícula têm segredos (Entra ID, banco).

## Banco

- Banco em UTC: use `BancoDoMatricula::AGORA`, não `GETDATE()`.
- `nu_ra` é texto com zeros à esquerda; não converta a coluna (perde índice) —
  compare com variações, como a busca faz.
- Precedência `AND`/`OR` no SQL original do SSMS fazia o filtro de ano valer só
  para uma das situações de PAB; a consulta do painel aplica o ano às duas de
  propósito (comentado em `pabsParados`).

## Documentação

- O projeto nasceu de um pacote zip (`instalar.sh` + `overlay/` sobre um
  `laravel/laravel` novo); isso não existe mais. O único `instalar.sh` é
  `windows/instalar.sh` (iniciador). Ao mudar telas, estrutura ou instalação,
  atualize o `README.md`, o `CLAUDE.md` e o documento de `docs/claude/`
  correspondente.
