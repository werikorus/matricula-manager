# Instância de demonstração (`tools/demo`)

Roda **a mesma aplicação** com dados fictícios, para ver telas, conferir uma
mudança de layout ou gerar as capturas do README sem tocar no banco de
produção, no histórico real nem no matrícula.

```bash
bash tools/demo/subir.sh          # http://127.0.0.1:8099  (porta opcional: subir.sh 8100)
bash tools/demo/capturar.sh       # em outro terminal, com a demo no ar → docs/imagens/*.png
```

Para rodar em segundo plano numa sessão do Claude Code, use `subir.sh` com
`run_in_background` e depois `curl`/`capturar.sh`; pare com
`pkill -f "php -S 127.0.0.1:8099"`.

## Como funciona

`tools/demo/router.php` é o roteador do `php -S` (docroot = `public/`):

- arquivos estáticos de `public/` são servidos direto;
- **qualquer método diferente de GET devolve 403** — um POST dispararia
  `artisan painel:executar` do painel real, fora do storage da demo;
- define `MATRICULA_PATH*` → `tools/demo/matricula` (catálogo fictício, sem
  `.env` nem `artisan`: nada consegue conectar no banco nem executar);
- `useStoragePath(tools/demo/storage)` (gitignored) — execuções, acessos,
  cache e sessão isolados;
- troca no container, **depois** dos providers (`afterBootstrapping(RegisterProviders)`;
  o `booted` confere e aborta se o banco real tiver vencido): `BancoDoMatricula` → `Demo\BancoFalso`,
  `MatriculaArtisan` → `Demo\ArtisanFalso`, `EditorDeSolicitacao` →
  `Demo\EditorFalso` (em `tools/demo/fakes.php`);
- exibe o caminho do matrícula como `/home/usuario/development/matricula`.

`tools/demo/seed.php` (chamado por `subir.sh` e `capturar.sh`) recria 8
execuções fictícias (com saída), o uso das telas para o Início, 91 dias de
reprocessamentos e de contagens de paradas para Gráficos. O resultado de cada
reprocessamento sai de `BancoFalso::situacaoAtual`, determinístico pelo código.

## O que funciona e o que não

| Tela | Na demo |
|---|---|
| Início, Commands, Histórico, PABs, Matrículas, Consultar matrícula (`?q=` qualquer), Gráficos | dados fictícios |
| Reservas paradas | lista vazia (fake devolve `[]`) |
| Edição da solicitação | mostra o erro "Demonstração: sem banco." |
| Configurações | abre, com verificações falhando (pasta sem `artisan`/`.env`) |
| Qualquer ação (Executar, Reprocessar, Salvar, trocar ambiente) | 403 |

## Ao mudar o painel

- Método novo em `BancoDoMatricula` usado por uma tela → acrescente o fake em
  `Demo\BancoFalso`; senão a demo cai no `pdo()` falso e a tela mostra erro.
- Command novo citado por uma tela → acrescente ao catálogo fictício
  `tools/demo/matricula/app/Http/Controllers/Dev/CommandsController.php`
  (nunca copie o arquivo real do matrícula: o repositório é público).
- Dados fictícios: nomes inventados, CPF `000.000.000-00`, códigos aleatórios.
  Não use nada que venha do banco real.
- Confira as capturas (abra os PNGs) antes de commitar: nenhum caminho real,
  nome real ou host deve aparecer.
