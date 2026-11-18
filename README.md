# Painel Matrícula

Dashboard pessoal para executar e acompanhar as rotinas diárias do sistema de
matrículas. Roda dentro da WSL, ao lado do matrícula, e abre no navegador do
Windows em `http://localhost:8010`.

Primeira tela: **Commands** — lista dos commands liberados, formulário com os
argumentos e opções de cada um, saída ao vivo e histórico de execuções.

## Telas

> As imagens usam dados fictícios: nomes, RAs, códigos e processos não são
> de alunos reais. Elas saem da instância de demonstração (`tools/demo`,
> veja [docs/claude/demonstracao.md](docs/claude/demonstracao.md)).

**Início** — atalhos ordenados pelo uso, solicitações abertas e buscas recentes.

![Tela Início](docs/imagens/inicio.png)

**Commands** — catálogo do matrícula, formulário de parâmetros e saída ao vivo.

![Tela Commands](docs/imagens/commands.png)

**Matrículas paradas** — solicitações aguardando integração ou com erro no RM,
com os erros mais comuns e o reprocessamento por linha ou em lote.

![Tela Matrículas paradas](docs/imagens/matriculas.png)

**PABs parados** — inscrições sem boleto/contrato ou com erro de integração.

![Tela PABs parados](docs/imagens/pabs.png)

**Consultar matrícula** — busca por nome, CPF, RA ou código da solicitação.

![Tela Consultar matrícula](docs/imagens/alunos.png)

**Histórico de commands** — execuções feitas pelo painel, com a saída de cada uma.

![Tela Histórico de commands](docs/imagens/historico.png)

## Instalação

Dentro da WSL:

```bash
git clone git@github.com:werikorus/matricula-manager.git ~/development/painel-matricula
cd ~/development/painel-matricula
composer install
cp .env.example .env
php artisan key:generate
```

Depois, no `.env`, aponte `MATRICULA_PATH_PRD` e `MATRICULA_PATH_HML` para as
pastas do matrícula de cada ambiente (veja [Configuração](#configuração)).
Não há banco de dados próprio nem migrations: o estado do painel fica em
`storage/app/`. Para abrir pelo Windows, instale o iniciador
([Uso](#uso)).

Requisitos: **PHP 7.4** (o mesmo do matrícula) com as extensões `pdo_sqlsrv`
(banco do matrícula), `mbstring`, `tokenizer` e `posix`, e o Composer. Não
há Node nem build de front-end.

## Configuração

### 1. Login Microsoft (Entra ID)

No portal do Entra (ou Azure) > **Registros de aplicativo** > **Novo registro**:

- Tipos de conta: somente este diretório organizacional (locatário único)
- URI de redirecionamento, plataforma **Web**: `http://localhost:8010/auth/callback`

Depois, em **Certificados e segredos**, crie um segredo do cliente. Preencha no
`.env` do painel:

```dotenv
ENTRA_TENANT_ID=        # "ID do diretório (locatário)"
ENTRA_CLIENT_ID=        # "ID do aplicativo (cliente)"
ENTRA_CLIENT_SECRET=    # o VALOR do segredo, não o ID dele
PAINEL_EMAILS_PERMITIDOS=seu.email@empresa.com.br
```

A permissão `User.Read` (delegada) já vem no registro e é a única usada.
Estar no AD não basta: só entra quem estiver em `PAINEL_EMAILS_PERMITIDOS`.

### 2. Commands

O painel mostra os mesmos commands da tela "Dev > Commands" do matrícula: lê o
array de `commands()` em `app/Http/Controllers/Dev/CommandsController.php` do
matrícula (como texto, sem carregar o matrícula). Para liberar um command novo,
adicione-o lá. As fontes de log exibidas ficam em `config/painel.php`, chave `logs`.

Não usamos `php artisan list`: o boot do matrícula consulta o banco (menu no
`AppServiceProvider`) e, sem acesso ao banco, a listagem travava.

### Filtro de ano letivo

PABs, Matrículas e Reservas paradas têm um select **Ano letivo**: do último
ano com processo seletivo cadastrado no ambiente (ex.: 2027, lido de
`tb_processo`, `tb_processo_admissao` e `tb_processo_reserva`) até 2 anos antes
do atual. O ano atual vem selecionado (`?ano=2025` na URL) e a tela traz só os
registros desse ano letivo. Em Matrículas, o corte de inclusão é 1º de janeiro
do ano escolhido, ou do atual quando o ano é futuro. O lote do integrador
(`integradorLoteCommand anoLetivo <ano>`) também pega só o ano escolhido.

### PABs parados

O menu "PABs parados" consulta direto o banco do matrícula (credenciais
`DB_*` lidas do `.env` de lá). O botão "Reprocessar" abre o
`ProcessaAdmissaoRMCommand` com o código da admissão preenchido.

### Matrículas paradas

O menu "Matrículas paradas" lista as solicitações aguardando integração (14)
ou com erro de integração (16), com o resumo do `ds_erro` e atalhos para os
erros mais comuns (incluídas desde 1º de janeiro do ano escolhido).
"Reprocessar" de uma linha primeiro atualiza a `tb_solicitacao` (situação 14,
tentativas 0, `ds_erro` nulo; só se ainda estiver em 14/16) e depois roda o
`integradorCommand` com a solicitação;
"Reprocessar todos" roda `integradorLoteCommand anoLetivo <ano escolhido>` ou
`integradorLoteCommand processo <código>` (escolha no diálogo; o código do
processo em cada linha abre o diálogo já com ele).

### Configurações: ambiente

Em Configurações (rodapé do menu) escolhe-se o matrícula usado pelo painel:
**Produção** ou **Homologação**. A pasta de cada um é editada na mesma tela
(aceita o caminho do Windows `\\wsl.localhost\Ubuntu\...` ou o da WSL; vazio
volta ao padrão de `MATRICULA_PATH_PRD`/`MATRICULA_PATH_HML` no `.env`).
Commands, logs, banco (do `.env` de cada pasta) e edição seguem a escolha,
salva em `storage/app/configuracoes.json`. Cada execução guarda o ambiente em
que rodou; a troca é bloqueada com rotina em execução. Em homologação o painel
mostra uma faixa e o nome do ambiente em destaque. A tela testa se o banco de
cada ambiente responde (VPN).

### Início

Primeira tela do painel (`/`): busca rápida de aluno, atalhos para as telas
ordenados pelo uso (as 3 mais acessadas em destaque), solicitações abertas
recentemente, commands mais executados (do histórico) e buscas recentes. O uso
fica em `storage/app/acessos/` (um JSON por usuário, fora do git). Telas novas
entram no menu e nos atalhos por `app/Support/Telas.php`.

### Histórico de commands

O menu "Histórico de commands" lista as execuções feitas pelo painel (as 500
mais recentes), com filtro por situação, busca e a saída de cada uma no console
ao lado (ao vivo, se ainda estiver rodando). "Repetir" abre a tela de Commands
com o mesmo command e os mesmos parâmetros, para conferir e executar.

### Consultar matrícula

O menu "Consultar matrícula" busca solicitações de qualquer situação por nome
(partes, sem acento/maiúscula), CPF, RA (com ou sem zeros) ou código da
solicitação. O lápis abre a tela de edição.

### Edição da solicitação

O lápis ao lado do nome do aluno em "Matrículas paradas" abre
`/solicitacoes/{codigo}`: todos os campos da `tb_solicitacao`, montados a partir
do esquema da tabela (tipo, tamanho, NULL, chave estrangeira). Grava só os
campos alterados, depois de uma confirmação com "antes → depois". Se o registro
mudou no banco desde que a tela abriu, nada é gravado. Cada gravação fica em
`storage/app/auditoria/tb_solicitacao.log` (quem, quando, antes e depois).
Toda gravação preenche `dh_alteracao` (agora, horário de Brasília) e
`nu_ip_alteracao` (IP desta máquina na VPN, descoberto pela rota do Windows até
o banco; `PAINEL_IP_VPN` no `.env` fixa o valor). O "Reprocessar" de Matrículas
paradas também preenche os dois.

### Reservas paradas

O menu "Reservas paradas" lista reservas de vaga em quatro etapas: com erro (7),
travada em processamento (6), sem boleto (3/5 sem `rl_reserva_vaga_boleto`) e
aguardando pagamento (5 com boleto). "Reprocessar" de uma linha abre o
`ProcessaReservaRMCommand` com a reserva; não aparece para as que aguardam
pagamento, que já têm boleto. "Reprocessar todos" oferece
`alteraStatusReservaPaga` (verifica pagamentos no RM) ou
`ProcessaReservaRMCommand` sem código (no máximo 2 por execução).

### 3. Login

`VALIDA_LOGIN_POR_MICROSOFT=true` exige login Microsoft; qualquer outro valor
deixa o painel aberto.

## Uso

### Iniciador do Windows (recomendado)

Dentro da WSL, na pasta do painel:

```bash
bash windows/instalar.sh          # porta padrão 8010; ou: bash windows/instalar.sh 8020
```

Compila o `PainelMatricula.exe` com o `csc.exe` que já vem no Windows (nada a
instalar), copia para `%LOCALAPPDATA%\PainelMatricula` e cria o atalho
**Painel Matrícula** no Menu Iniciar e na Área de Trabalho. Rode de novo para
atualizar (feche antes pelo ícone da bandeja › Encerrar painel).

O iniciador sobe `php artisan serve` na WSL, espera responder e abre o painel
numa janela própria (modo app do Google Chrome; troque para `edge` no `.ini`
se preferir). Fica na bandeja com: abrir,
abrir no navegador, reiniciar servidor, ver log, configuração e encerrar.

- `PainelMatricula.exe --bandeja`: só sobe o servidor e fica na bandeja (bom
  para colocar em `shell:startup` e iniciar com o Windows).
- Configuração em `%LOCALAPPDATA%\PainelMatricula\PainelMatricula.ini`
  (distro, pasta, porta, navegador) e log em `servidor.log` na mesma pasta.
- "Encerrar painel" avisa se houver rotina rodando. Ela continua (é um processo
  separado), mas para se a WSL for desligada.

Só compilar, sem instalar: `bash windows/compilar.sh` (gera `windows/dist/`).

### Instalar como aplicativo (PWA)

Com o painel aberto no Chrome: menu ⋮ › Transmitir, salvar e compartilhar ›
**Instalar página como app** (no Edge: menu › Aplicativos › Instalar este site
como aplicativo). Ganha ícone próprio, janela sem barra de endereço e,
no botão direito do ícone na barra de tarefas, atalhos para Commands, PABs,
Matrículas e Reservas. O servidor precisa estar de pé (use o iniciador).

### Manual

```bash
cd ~/development/painel-matricula && XDEBUG_MODE=off php artisan serve --port=8010
```

## Decisões de projeto

| Decisão | Motivo |
|---|---|
| Iniciador em C# compilado pelo csc.exe do .NET Framework | Já vem em todo Windows: nada a instalar para gerar o .exe. Custo: só C# 5 (sem interpolação, `?.` etc.). |
| Laravel 8 em PHP 7.4 | Roda com o PHP que já existe na máquina, sem instalar outra versão. Custo: o Laravel 8 não recebe mais correções de segurança; aceitável aqui porque o painel só atende em `127.0.0.1` e exige login. |
| Matrícula tratado como caixa-preta (`php artisan` em processo separado) | Nenhuma alteração no sistema de produção; o command roda exatamente como no terminal. |
| Lista fechada de commands (a mesma da tela "Dev > Commands" do matrícula) | O painel aponta para PRD; nada de campo livre que permita `migrate:fresh` e afins. |
| Argumentos passados como array, opções antes de `--` e argumentos depois | Nenhum valor digitado vira comando de shell nem opção do artisan (ex.: `--env=`). |
| Variáveis do `.env` do painel removidas do processo filho | Sem isso o matrícula herdaria `APP_KEY`, `DB_*` etc. do painel e rodaria com a configuração errada. |
| Xdebug desligado (`XDEBUG_MODE=off`) nos commands disparados pelo painel | Evita o aviso "Could not connect to debugging client" em toda execução. Para depurar um command, rode-o pelo terminal. |
| Execução em processo destacado (`painel:executar`), sem fila | Não há `queue:work` para manter; a rotina não depende da requisição web e continua se a janela fechar. |
| Histórico em arquivos (`storage/app/execucoes/{id}.json` e `.log`), sem banco | Dispensa banco, migrations e extensões extras do PHP. A saída é lida por offset, o que dá o "ao vivo" com consultas simples. |
| Bloqueio de execução simultânea do mesmo command | Evita integrar duas vezes por duplo clique ou outra aba. |
| OAuth com Entra ID escrito com o cliente HTTP do Laravel (PKCE) | Duas chamadas HTTP; evita depender de Socialite + provider de terceiros. |
| CSS e JS escritos à mão em `public/` | Sem Node, sem build: menos peças para quebrar num equipamento corporativo. |
| Sessão simples, sem tabela de usuários | Ferramenta de um usuário só; a identidade vem sempre do Entra ID. |

## Limites conhecidos

- Commands que fazem perguntas no terminal não funcionam: tudo roda com
  `--no-interaction`. Se o command exigir, libere e marque a opção `--force`.
- "Parar execução" envia SIGTERM ao processo do artisan. O command pode parar
  no meio de um registro; use com o mesmo cuidado de um Ctrl+C.
- O servidor embutido atende só em `127.0.0.1`. Não exponha a porta na rede.

## Estrutura

```
routes/web.php                         todas as rotas do painel
config/painel.php                      ambientes PRD/HML, logs exibidos, login, IP da VPN
app/Http/Controllers/                  um controller por tela, Execucao (API JSON), Log, Auth
app/Services/BancoDoMatricula.php      consultas ao banco do matrícula (e o reprocessamento)
app/Services/EditorDeSolicitacao.php   edição da tb_solicitacao, com auditoria
app/Services/MatriculaArtisan.php      catálogo e montagem segura dos argumentos
app/Services/CatalogoDoMatricula.php   leitura da tela "Dev > Commands" do matrícula
app/Services/Configuracoes.php         ambiente escolhido e caminhos dos repositórios
app/Services/Acessos.php               uso das telas (tela Início)
app/Services/IpDaVpn.php               IP da VPN gravado em nu_ip_alteracao
app/Services/EntraId.php               login Microsoft
app/Console/Commands/ExecutarCommand   executor em segundo plano (painel:executar)
app/Models/Execucao.php                histórico em arquivos JSON
app/Support/                           Telas (menu), AnoLetivo (filtro), Erros (resumo do ds_erro)
resources/views/                       uma pasta por tela, layouts e componentes
public/css/painel.css, public/js/      CSS e JS escritos à mão, sem build
windows/                               iniciador do Windows (C#) e scripts de instalação
tools/demo/                            instância de demonstração com dados fictícios
docs/                                  imagens do README e base de conhecimento (docs/claude)
CLAUDE.md                              ponto de partida para o Claude Code
```
