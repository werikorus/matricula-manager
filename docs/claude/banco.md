# Banco do matrícula

SQL Server (Azure SQL) acessado por PDO `sqlsrv`, pela VPN. Credenciais: `DB_HOST`,
`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` do **`.env` da pasta do matrícula
do ambiente em uso** (PRD ou HML) — nunca do `.env` do painel.
`LoginTimeout=8`, timeout de consulta 60 s. Sem VPN, as telas mostram a
mensagem de erro em vez de quebrar.

Todo acesso fica em `App\Services\BancoDoMatricula` (exceto a edição genérica,
em `EditorDeSolicitacao`). Consultas sempre com parâmetros `?` via
`consultar()`, que converte qualquer falha em `RuntimeException`.

## Fuso horário

O banco roda em **UTC**; o matrícula grava horário de Brasília. Para "agora"
use `BancoDoMatricula::AGORA`
(`CAST(SYSDATETIMEOFFSET() AT TIME ZONE 'E. South America Standard Time' AS datetime)`),
nunca `GETDATE()`. Datas enviadas ao banco em ISO com `T` (`2026-01-01T00:00:00`),
que o SQL Server lê igual em qualquer idioma.

## Tabelas usadas

| Tabela | Para quê | Colunas mais usadas |
|---|---|---|
| `tb_solicitacao` | matrícula/rematrícula (uma por aluno e processo) | `cd_solicitacao` (PK), `cd_situacao_solicitacao`, `nu_quantidade_tentativas_integracao`, `ds_erro`, `nm_aluno`, `nu_ra`, `nu_cpf_aluno`, `dt_nascimento_aluno`, `cd_aluno`, `cd_processo`, `cd_contrato`, `cd_turma`, `ds_turno`, `dh_inclusao`, `dh_alteracao`, `nu_ip_alteracao` |
| `tb_processo` | processo de matrícula | `cd_processo`, `nm_processo`, `nu_ano_letivo`, `cd_coligada`, `cd_filial`, `dh_inclusao` |
| `tb_situacao_solicitacao` | descrições das situações (1ª coluna = código, 2ª = descrição) | — |
| `tb_pessoa` | nome do aluno nas paradas | `cd_pessoa`, `nm_pessoa` |
| `tb_admissao` | PAB (processo de admissão) | `cd_admissao`, `cd_situacao`, `cd_boleto`, `cd_contrato`, `nu_ra`, `nm_aluno`, `nu_quantidade_tentativas`, `dh_inclusao`, `cd_processo_admissao` |
| `tb_processo_admissao` | processo do PAB | `cd_processo_admissao`, `nu_ano_letivo`, `cd_coligada`, `cd_filial`, `dh_inicio`, `dh_termino` |
| `tb_situacao_admissao` | descrição da situação do PAB | `cd_situacao_admissao`, `ds_situacao_admissao` |
| `tb_reserva_vaga` | reserva de vaga | `cd_reserva_vaga`, `cd_situacao`, `nu_quantidade_tentativas`, `ds_erro`, `cd_responsavel_financeiro`, `nm_pessoa`, `nu_cpf`, `nu_ra`, `dh_inclusao`, `dh_alteracao`, `cd_processo_reserva` |
| `tb_processo_reserva` | processo de reserva | `cd_processo_reserva`, `nm_processo`, `nu_ano_letivo`, `cd_coligada`, `cd_filial` |
| `tb_situacao_reserva_vaga` | descrição da situação da reserva | `cd_situacao_reserva_vaga`, `ds_situacao_reserva_vaga` |
| `rl_reserva_vaga_boleto` | boleto da reserva (existência = tem boleto) | `cd_reserva_vaga` |
| `tb_envio_codigo` | envio de código ao responsável (sem ele o `ProcessaReservaRMCommand` ignora a reserva) | `cd_responsavel_financeiro` |

## Códigos de situação

**Solicitação** (`cd_situacao_solicitacao`; textos no `CASE` de
`matriculasParadas`): 1 Em preenchimento · 2 Concluída · 3 Solicitada - aguard.
parcelas · 4 Solicitada - em processamento · 5 COM ERRO - geração de parcelas ·
6 Cancelada · 7 Agendado p/ reprocessamento · 8 Pendente avaliação técnica ·
9 Parcelas geradas · 10 Em fila proc. manual (2) · 11 Em fila proc. manual ·
12 Em fila proc. local · 13 Em fila de espera · **14 Aguardando integrar RM** ·
15 Pré-preenchimento · **16 COM ERRO - integração** · 18 Aguardando envio
e-mail · 19 COM ERRO - envio e-mail.
"Matrícula parada" = 14 ou 16.

**PAB** (`tb_admissao.cd_situacao`): parado = 3 (processado) sem boleto e sem
contrato, ou 11 (erro de integração).

**Reserva** (`tb_reserva_vaga.cd_situacao`): 3/5 sem boleto = "sem boleto";
5 com boleto = "aguardando pagamento"; 6 = travada em processamento; 7 = com erro.

## As duas escritas

Ambas: transação, `SELECT … WITH (UPDLOCK, ROWLOCK)`, confere o estado antes,
`UPDATE` com `rowCount() === 1`, rollback em qualquer erro, e preenchem
`dh_alteracao = AGORA` e `nu_ip_alteracao = IP da VPN` (`IpDaVpn`; sem IP não grava).

1. **`BancoDoMatricula::prepararReintegracao($codigo, $ip)`** — antes do
   `integradorCommand` de uma linha: só se a situação ainda for 14/16, faz
   situação 14, tentativas 0, `ds_erro` NULL. O controller confere command,
   execução ativa e argumento **antes** do UPDATE, para não alterar e depois
   falhar em disparar.
2. **`EditorDeSolicitacao::salvar($codigo, $alteracoes, $usuario)`** — edição
   genérica da `tb_solicitacao`. Colunas e tipos vêm de `sys.columns` (cache
   10 min); chave/identidade/calculadas/automáticas e tipos binários são só
   leitura. Concorrência otimista: cada campo leva o valor `original` lido pela
   tela; se o banco mudou, nada é gravado. Auditoria em
   `storage/app/auditoria/tb_solicitacao.log`.

Qualquer escrita nova deve seguir esse padrão e ser combinada com o usuário.

## Busca de aluno (`buscarSolicitacoesDoAluno`)

- CPF (11 dígitos, com/sem pontuação) → compara `nu_cpf_aluno` sem pontuação.
- Só dígitos → `cd_solicitacao` ou `nu_ra` (RA é texto com zeros à esquerda:
  compara com as variações de preenchimento, sem converter a coluna, para usar índice).
- Texto → cada palavra (≥ 2 letras, total ≥ 3) com
  `nm_aluno COLLATE Latin1_General_CI_AI LIKE ?` (sem acento/maiúscula),
  escapando `[`, `%`, `_`.
- `TOP 201` para saber se cortou em 200.
