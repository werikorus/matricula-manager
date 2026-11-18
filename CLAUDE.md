# CLAUDE.md — Painel Matrícula

Painel web pessoal (Laravel 8, PHP 7.4) que roda na WSL e opera o **sistema de
matrículas** ("o matrícula", outro repositório): executa commands `php artisan`
de lá, acompanha a saída ao vivo, e consulta/corrige registros parados direto no
banco SQL Server dele. Um usuário, sem banco próprio: o estado do painel são
arquivos em `storage/app/`.

Este arquivo é o ponto de partida. Aprofunde só no que a tarefa pedir:

| Preciso de… | Leia |
|---|---|
| Como as peças se ligam (ambiente, execução de commands, storage, login) | [docs/claude/arquitetura.md](docs/claude/arquitetura.md) |
| Uma tela: rota, controller, view, JS, consultas, commands que dispara | [docs/claude/telas.md](docs/claude/telas.md) |
| Tabelas, colunas, códigos de situação, as escritas no banco | [docs/claude/banco.md](docs/claude/banco.md) |
| Erros que já aconteceram e restrições não óbvias | [docs/claude/armadilhas.md](docs/claude/armadilhas.md) |
| Ver/testar telas ou gerar capturas **sem** banco real | [docs/claude/demonstracao.md](docs/claude/demonstracao.md) |
| Uso, instalação, decisões de projeto (visão do usuário) | [README.md](README.md) |

## Regras inegociáveis

1. **O painel aponta para PRODUÇÃO por padrão** (`MATRICULA_AMBIENTE=PRD`, ou o
   que estiver salvo em `storage/app/configuracoes.json`). Durante o
   desenvolvimento, nunca dispare commands, "Reprocessar", "Salvar" nem
   qualquer POST no painel real (`localhost:8010`) sem o usuário pedir
   explicitamente. Para ver telas, use a instância de demonstração.
2. **O repositório no GitHub é público.** Nada de dados de alunos (nome, CPF,
   RA, códigos reais), `.env`, caminhos/hosts do banco, nem arquivos do
   repositório do matrícula em commits, capturas ou documentação. Capturas só
   da demonstração (`tools/demo`), que usa dados fictícios.
3. **Só duas escritas no banco existem**, e ambas são transacionais, com trava
   de linha e checagem de estado: `BancoDoMatricula::prepararReintegracao()` e
   `EditorDeSolicitacao::salvar()`. Uma escrita nova precisa seguir o mesmo
   padrão (ver [banco.md](docs/claude/banco.md)) e ser combinada com o usuário.
4. **O matrícula é caixa-preta.** Não carregar código dele; não rodar
   `php artisan list` lá (o boot dele consulta o banco e trava). O catálogo de
   commands vem de `app/Http/Controllers/Dev/CommandsController.php` de lá,
   lido como texto (tokens) por `CatalogoDoMatricula`.
5. **PHP 7.4 e C# 5.** Nada de `match`, `?->`, argumentos nomeados, enums,
   `readonly`, union types (PHP 8). O código usa `function () use (...)` em vez
   de arrow functions — mantenha o estilo. O iniciador Windows (`windows/`) é
   C# 5: sem interpolação, sem `?.`, sem membros `=>`.
6. **Sem build de front.** CSS/JS escritos à mão em `public/` (o
   `resources/js|css` e `webpack.mix.js` são resto do esqueleto, não usados).

## Rodar e verificar

```bash
XDEBUG_MODE=off php artisan serve --port=8010    # painel real (normalmente já está no ar pelo iniciador)
bash tools/demo/subir.sh                          # demonstração: http://127.0.0.1:8099, dados fictícios, só leitura
bash tools/demo/capturar.sh                       # com a demonstração no ar: refaz docs/imagens/*.png
php -l arquivo.php                                # checagem de sintaxe (PHP 7.4)
```

Não há suíte de testes real (`tests/` tem só os exemplos do Laravel). Verifique
mudanças de tela pela demonstração (`curl` nas rotas ou captura com o Chromium
headless — ver [demonstracao.md](docs/claude/demonstracao.md)).
Sempre `XDEBUG_MODE=off` em comandos PHP: o Xdebug da máquina tenta conectar e
polui a saída.

## Mapa do código

```
routes/web.php                       todas as rotas (grupo com Autenticado + RegistrarAcesso)
config/painel.php                    ambientes PRD/HML, logs exibidos, login, IP da VPN
app/Http/Controllers/                um controller por tela (+ Execucao = API JSON, Log, Auth)
app/Services/
  BancoDoMatricula.php               TODAS as consultas SQL ao banco do matrícula (PDO sqlsrv)
  EditorDeSolicitacao.php            edição genérica da tb_solicitacao (esquema via sys.columns)
  MatriculaArtisan.php               catálogo de commands + montagem segura dos argumentos + Process
  CatalogoDoMatricula.php            parser (token_get_all) da tela Dev > Commands do matrícula
  Configuracoes.php                  ambiente escolhido (PRD/HML) e caminhos → config('painel.matricula')
  Acessos.php                        contagem de uso por tela, buscas e solicitações recentes (tela Início)
  IpDaVpn.php                        IP da VPN via PowerShell do Windows (gravado em nu_ip_alteracao)
  EntraId.php                        login Microsoft (OAuth + PKCE, cliente HTTP do Laravel)
app/Models/Execucao.php              execução de command = storage/app/execucoes/{id}.json + {id}.log
app/Console/Commands/ExecutarCommand painel:executar {id} — executor destacado em segundo plano
app/Support/Telas.php                lista de telas: alimenta o menu e os atalhos do Início
app/Support/AnoLetivo.php            select "Ano letivo" das telas de paradas (?ano=)
app/Support/Erros.php                resumo do ds_erro (exceção do RM) para tabelas
resources/views/<tela>/index.blade.php   uma pasta por tela; layouts/app (menu) e layouts/base (head)
resources/views/components/          filtro-ano, carregando, reprocessar-solicitacao
public/js/*.js                       um arquivo por comportamento, IIFE sem dependências
public/css/painel.css                um CSS só, tokens em :root, tema escuro = [data-tema="escuro"]
windows/                             iniciador .exe (C# 5) e scripts de instalação
tools/demo/                          instância de demonstração (dados fictícios)
```

## Convenções

- **Tudo em português**: classes, métodos, variáveis, rotas, mensagens,
  commits. Nomes de tabela/coluna do matrícula ficam como estão
  (`cd_`, `nm_`, `ds_`, `dh_`, `nu_`, `in_`).
- Comentários explicam **decisão e motivo** ("Decisão: …"), não o óbvio.
  Docblocks de consulta citam a regra de negócio e a consulta original.
- Controller fino: lê/valida, chama serviço, devolve view. Erro de banco vira
  `RuntimeException` com mensagem para o usuário e a tela mostra `$erro` em vez
  de quebrar.
- Telas que disparam command: conferem se o command está no catálogo, recusam
  se já houver execução ativa do mesmo command (ou do "par" dele), criam a
  `Execucao`, chamam `disparar()` e redirecionam para
  `route('commands', ['comando' => …])`.
- Assets com cache-busting `?v={{ filemtime(public_path(...)) }}`.
- Commits: `tipo: descrição curta em português` (`feat`, `fix`, `refactor`,
  `docs`, `chore`), sem ID de card. Branch principal: `main`; remote `origin`
  via SSH (`git@github.com:werikorus/matricula-manager.git`).

## Tarefas comuns

- **Tela nova**: rota em `routes/web.php` dentro do grupo autenticado →
  controller → `resources/views/<tela>/index.blade.php` estendendo
  `layouts.app` → entrada em `App\Support\Telas::lista()` (menu + Início +
  contagem de uso) → se consultar banco, método novo em `BancoDoMatricula` e
  fake correspondente em `tools/demo/fakes.php`.
- **Consulta nova**: só em `BancoDoMatricula`, via `$this->consultar($sql, $params)`
  com parâmetros `?`. Datas "agora" com `self::AGORA` (banco em UTC).
- **Atalho para um command**: constante `COMANDO` no controller, nome igual ao
  do catálogo do matrícula; os nomes dos argumentos vêm do catálogo
  (`$comando['argumentos']`), não fixos no painel (as exceções que existem
  estão em [armadilhas.md](docs/claude/armadilhas.md)); monte com
  `MatriculaArtisan::montarArgumentos()`.
- **Mudou uma tela visível no README**: suba a demonstração e rode
  `tools/demo/capturar.sh`; confira as imagens antes de commitar.
