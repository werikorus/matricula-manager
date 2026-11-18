# Arquitetura

Visão de como as peças se ligam. Para a lista de telas, veja
[telas.md](telas.md); para o banco, [banco.md](banco.md).

## Contexto

```
Windows ── PainelMatricula.exe (windows/Iniciador.cs) ── abre Chrome em modo app
   │                                                       │
   └─ WSL ── php artisan serve :8010 (este painel) ◄────────┘
               │  lê como texto: <matricula>/app/Http/Controllers/Dev/CommandsController.php
               │  lê:            <matricula>/.env  (DB_* do SQL Server)
               │  executa:       php artisan <command> em <matricula>  (processo separado)
               │  consulta/escreve via PDO sqlsrv ──► banco do matrícula (Azure SQL, pela VPN)
               └─ estado próprio em storage/app/  (JSON + logs, sem banco)
```

"O matrícula" é o sistema de matrículas (outro repositório Laravel), com duas
cópias locais: **PRD** e **HML**. O painel nunca carrega o código dele.

## Ambiente (PRD/HML) em cada requisição

1. `config/painel.php` define `matricula` (padrão do `.env`) e `ambientes`
   (`PRD` → `MATRICULA_PATH_PRD`, `HML` → `MATRICULA_PATH_HML`).
2. `AppServiceProvider::boot()` chama `Configuracoes::aplicar()`, que lê
   `storage/app/configuracoes.json` (ambiente escolhido + caminhos editados na
   tela) e sobrescreve `config('painel.matricula.{ambiente,titulo,caminho,php}')`.
3. Tudo que depende do matrícula lê **só** `config('painel.matricula.*')`:
   `MatriculaArtisan::caminho()`, `BancoDoMatricula` (lê o `.env` dessa pasta),
   `LogController`, `IpDaVpn`, caches por ambiente (`AnoLetivo`, esquema da
   `tb_solicitacao`, situações).
4. Trocar de ambiente é bloqueado se houver execução ativa
   (`Configuracoes::definirAmbiente`). Em HML o layout ganha destaque via
   `<html data-ambiente="HML">` + CSS.

## Execução de um command

```
Tela (POST)                      ExecucaoController::store / XController::reprocessar…
  │  MatriculaArtisan::comando($nome)         → precisa estar no catálogo (lista fechada)
  │  MatriculaArtisan::montarArgumentos()     → valida contra a definição; opções antes de "--",
  │                                             argumentos depois; "options" viram lista fechada
  │  Execucao::existeAtivaDoComando()         → recusa duplicata (409 / flash 'erro')
  │  Execucao::criar()                        → storage/app/execucoes/{id}.json, status "pendente"
  │                                             (id do arquivo "contador", com flock)
  └─ Execucao::disparar()                     → nohup php artisan painel:executar {id} &
                                                 (processo destacado: sobrevive à requisição)
ExecutarCommand (painel:executar)
  │  aplica o ambiente/caminho GRAVADO na execução (não o atual)
  │  status "executando", pid_executor
  │  MatriculaArtisan::processo() → Symfony Process [php, artisan, cmd, --no-interaction, --ansi, ...]
  │     cwd = pasta do matrícula; env limpo (APP_*, DB_* do painel removidos; XDEBUG_MODE=off; COLUMNS=160)
  │  saída → {id}.log (append)
  └─ status final: "sucesso" (código 0) | "falha" | "interrompido" (se pediram parar)
Tela de Commands / Histórico (JS)
     GET /execucoes             → últimas 30 (resumo)
     GET /execucoes/{id}/saida?offset=N → trecho novo do .log (até 256 KB, corte UTF-8 seguro)
     POST /execucoes/{id}/parar → SIGTERM no pid, só se /proc/{pid}/cmdline contém "artisan"
```

`Execucao::sincronizarOrfas()` (chamado nas telas) marca como `interrompido` o
que estava "executando" com executor morto (ex.: `wsl --shutdown`) e como
`falha` o "pendente" há mais de 20 s (executor não subiu).

Status possíveis: `pendente`, `executando`, `sucesso`, `falha`,
`interrompido` (rótulos em `HistoricoController::ROTULOS`).

## Storage do painel (`storage/app/`, fora do git)

| Caminho | Conteúdo | Escrito por |
|---|---|---|
| `execucoes/{id}.json`, `{id}.log`, `contador` | execuções e saída (contém dados de alunos!) | `Execucao`, `ExecutarCommand` |
| `acessos/{sha1(email)}.json` | uso por tela, buscas e solicitações recentes | `Acessos` (via `RegistrarAcesso` e telas) |
| `configuracoes.json` | ambiente escolhido e caminhos editados | `Configuracoes` |
| `auditoria/tb_solicitacao.log` | uma linha JSON por gravação na edição | `EditorDeSolicitacao::auditar` |

Cache (driver `file`): último ano letivo (10 min, 1 min se sem banco), esquema
da `tb_solicitacao` e situações (10 min), IP da VPN (10 min) — todos com o
ambiente na chave.

## Login

- `VALIDA_LOGIN_POR_MICROSOFT=false` (uso normal local): `Autenticado` põe na
  sessão o usuário fixo `acesso-livre` ("Acesso livre").
- `true`: OAuth2 com Entra ID + PKCE (`AuthController`, `EntraId`), só entra
  quem está em `PAINEL_EMAILS_PERMITIDOS`. Sessão simples, sem tabela de
  usuários. API JSON devolve 401 e o JS manda para `/login`.

## Middlewares do grupo autenticado

- `Autenticado` — acima.
- `RegistrarAcesso` — após a resposta, conta GET 200 não-JSON de rotas que
  estão em `Telas::lista()` (falha aqui nunca derruba a tela).

## Front-end

- `layouts/base.blade.php`: `<head>`, fontes (Schibsted Grotesk, JetBrains
  Mono), tema/menu aplicados antes do desenho (localStorage `painel.tema`,
  `painel.menu`), `@stack('scripts')`.
- `layouts/app.blade.php`: menu lateral gerado de `Telas::menu()`, rodapé
  (Configurações, recolher menu, tema, conta).
- JS em `public/js/`, cada arquivo uma IIFE sem dependências:
  `painel.js` (tela Commands), `historico.js`, `tabela.js` (filtro, paginação,
  diálogos de "Reprocessar todos" nas listas), `solicitacao.js` (edição com
  "antes → depois"), `console-terminal.js` (ANSI → HTML, barras de progresso),
  `copiar.js`, `carregando.js` (overlay em links/forms com `data-carregando`),
  `menu.js` (Ctrl+B), `tema.js`.
- Dados para o JS vão em `@json(...)` na view (ex.: `$painel` na tela Commands).
- PWA: `public/manifest.json` + `public/img/app/`.

## Iniciador Windows (`windows/`)

`Iniciador.cs` (C# 5, compilado pelo `csc.exe` do .NET Framework via
`compilar.sh`; `instalar.sh` copia para `%LOCALAPPDATA%\PainelMatricula` e cria
atalhos). Sobe `php artisan serve` na WSL, espera responder, abre o Chrome em
modo app e fica na bandeja. Configuração em `PainelMatricula.ini`.
