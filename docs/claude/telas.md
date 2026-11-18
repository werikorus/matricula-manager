# Telas

Uma linha por tela: onde está cada peça e o que ela toca. Todas as rotas estão
em `routes/web.php`, dentro do grupo `Autenticado` + `RegistrarAcesso` (exceto
login). O menu e os atalhos do Início vêm de `App\Support\Telas`.

## Resumo

| Tela | Rota(s) | Controller | View | JS | Lê | Escreve / dispara |
|---|---|---|---|---|---|---|
| Início | `GET /` | `InicioController` | `inicio/index` | — | `Acessos`, `Execucao::recentes(300)`, catálogo | — |
| Commands | `GET /commands` (`?comando=`, `?execucao=`, `?arg.x=`, `?opt.x=`) | `CommandController` | `commands/index` | `painel.js`, `console-terminal.js` | catálogo, logs | `POST /execucoes` (qualquer command do catálogo) |
| API de execuções | `GET/POST /execucoes`, `GET /execucoes/{id}/saida`, `POST /execucoes/{id}/parar` | `ExecucaoController` | JSON | — | `Execucao` | cria/para execução |
| Logs do matrícula | `GET /logs?fonte=&linhas=` | `LogController` | JSON | — | `tail` do log do dia (`config('painel.logs')`) | — |
| Histórico de commands | `GET /historico` | `HistoricoController` | `historico/index` | `historico.js`, `console-terminal.js` | `Execucao::recentes(500)` | "Repetir" = link para Commands com `arg.`/`opt.` |
| Consultar matrícula | `GET /alunos?q=` | `AlunoController` | `alunos/index` | `tabela.js`, `copiar.js` | `buscarSolicitacoesDoAluno` (máx. 200) | "Reprocessar" → `POST /matriculas/{codigo}/reprocessar` (com `voltar`) |
| PABs parados | `GET /pabs?ano=` | `PabController` | `pabs/index` | `tabela.js` | `pabsParados($ano)` | `POST /pabs/reprocessar-todos` → `ProcessaAdmissaoRMCommand` sem argumento; linha → Commands com o código |
| Matrículas paradas | `GET /matriculas?ano=` | `MatriculaController` | `matriculas/index` | `tabela.js` | `matriculasParadas($ano)`, `pendentesDeIntegracaoPorProcesso()` | linha: `POST /matriculas/{codigo}/reprocessar` (**UPDATE** + `integradorCommand`); lote: `POST /matriculas/reprocessar-todos` → `integradorLoteCommand anoLetivo <ano>` ou `processo <cd>` |
| Edição da solicitação | `GET/POST /solicitacoes/{codigo}?voltar=` | `SolicitacaoController` | `solicitacoes/editar` | `solicitacao.js` | `EditorDeSolicitacao::{agrupadas,situacoes,buscar}` | **UPDATE** genérico (`EditorDeSolicitacao::salvar`) |
| Reservas paradas | `GET /reservas?ano=` | `ReservaController` | `reservas/index` | `tabela.js` | `reservasParadas($ano)`, `contagemDosCommandsDeReserva` | `POST /reservas/processar` (`acao=integrar` → `ProcessaReservaRMCommand`, `acao=pagamento` → `alteraStatusReservaPaga`); linha → Commands com o código |
| Gráficos | `GET /graficos` | `GraficoController` | `graficos/index` | `graficos.js` | `Reprocessamentos::comResultado()` (registros + `situacaoAtual`), `HistoricoDeParadas` | foto do dia em `storage/app/paradas/` |
| Configurações | `GET /configuracoes`, `POST /configuracoes/{ambiente,caminhos}` | `ConfiguracoesController` | `configuracoes/index` | — | `Configuracoes`, `.env` de cada pasta, teste TCP no banco, `git` da pasta | `configuracoes.json` |
| Login | `GET /login`, `/auth/redirect`, `/auth/callback`, `POST /logout` | `AuthController` | `auth/login` | — | Entra ID | sessão |

## Detalhes que importam

### Filtro de ano letivo (PABs, Matrículas, Reservas)

`App\Support\AnoLetivo`: opções = do maior `nu_ano_letivo` com processo
cadastrado (máx. atual+3, em cache) até 2 anos antes do atual; padrão = ano
atual; `?ano=` inválido cai no atual. As consultas filtram **só** o ano
escolhido (`= ?`, não `>=`). Em Matrículas, a inclusão é cortada em
`inicioDasInclusoes($ano)` = 1º/jan de `min($ano, ano atual)`. Componente:
`resources/views/components/filtro-ano.blade.php`.

### Regras espelhadas dos commands do matrícula

Algumas telas mostram "quantos o command pegaria" ou marcam linhas que o
command pega sozinho. Essas regras **copiam o SQL do command de lá** e precisam
ser atualizadas se ele mudar:

- `PabController::selecionadosPeloCommand()` — `ProcessaAdmissaoRMCommand`:
  (situação 3 e ano atual/seguinte) OU (situação 11 com < 3 tentativas, qualquer ano).
- `ReservaController::anosDoCommand()` + `contagemDosCommandsDeReserva()` —
  `ProcessaReservaRMCommand` sem código pega no máximo 2 (`TOP 2`),
  anos atual/seguinte; `alteraStatusReservaPaga` pega situação 5 com ano >= atual.
- `MatriculaController::index` `noLoteDoAno` — soma de `pendentesDeIntegracaoPorProcesso`
  do ano (o lote não filtra data de inclusão).

### Gráficos

- **O que entra**: `Reprocessamentos::registrar($execucao)` é chamado *antes* de
  `disparar()` em `ExecucaoController::store` e nas duas ações de
  `MatriculaController`. Grava em `storage/app/reprocessamentos/{AMBIENTE}.jsonl`:
  commands com código (`integradorCommand`, `ProcessaAdmissaoRMCommand`,
  `ProcessaReservaRMCommand`) e a lista do `integradorLoteCommand`
  (`solicitacoesDoLote`, a mesma consulta do command). PAB/reserva **sem
  código** não entram: o command pega só `TOP 3`/`TOP 2` da regra dele.
  Registrar nunca lança (não pode impedir um reprocessamento).
- **Resultado**: a tela lê os últimos 91 dias, fica com o último
  reprocessamento de cada registro e consulta a situação **atual**
  (`situacaoAtual`, blocos de 1.000). `Reprocessamentos::classificar`:
  matrícula 5/16/19 = voltou, 14 = parado; PAB 11 = voltou, 3 sem boleto e
  contrato = parado; reserva 7 = voltou, 6 ou 3/5 sem boleto = parado; o resto
  = resolvido.
- **Paradas em aberto**: `HistoricoDeParadas::registrar()` (no máximo a cada
  30 min) roda `contagemDeParadas(ano atual)` ao abrir Gráficos, PABs,
  Matrículas ou Reservas e guarda em `storage/app/paradas/{AMBIENTE}.json`.
  Dia sem tela aberta = dia sem ponto.
- **Front**: o controller manda só tipo, dia, resultado e resumo do erro (sem
  código nem nome) em `window.GRAFICOS`; `graficos.js` aplica os filtros
  (4/13 semanas, tipo) e desenha em SVG. Cores em `painel.css` › "Gráficos"
  (validadas para daltonismo; o resultado usa verde/amarelo/vermelho com
  legenda e tabela).

### Bloqueio de execução simultânea ("pares")

- Matrículas: `integradorCommand` e `integradorLoteCommand` bloqueiam um ao outro.
- Reservas: `ProcessaReservaRMCommand` e `alteraStatusReservaPaga` idem.
- Tela Commands/API: bloqueia só o mesmo command.

### Parâmetro `voltar`

Reprocessar/editar a partir de outra tela leva `voltar=/caminho?query`. É
validado por regex (`#^/(?!/)[a-z0-9/_-]*(\?[^\s]*)?$#i`) para não virar
open redirect — reutilize o mesmo padrão.

### Tela Commands (JS)

`painel.js` monta lista e formulário a partir de `$painel['comandos']`
(catálogo), sincroniza `?comando=` na URL, acompanha a execução ativa
(polling de `/execucoes` e `/execucoes/{id}/saida`) e o log escolhido (`/logs`).
`?execucao=ID` abre uma execução específica (vindo do Histórico).
