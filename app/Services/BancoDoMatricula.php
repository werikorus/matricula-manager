<?php

namespace App\Services;

use App\Support\AnoLetivo;
use Dotenv\Dotenv;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Consultas no banco do matrícula. A única escrita é prepararReintegracao().
 *
 * As credenciais são lidas do .env do próprio matrícula (as mesmas da conexão
 * "sqlsrv_servico" de lá), então nada precisa ser duplicado no .env do painel.
 */
class BancoDoMatricula
{
    /**
     * "Agora" no horário de Brasília, para colunas de data do matrícula.
     * O banco (Azure SQL) roda em UTC, então GETDATE() ficaria 3 h adiantado
     * em relação ao que o matrícula grava (America/Sao_Paulo).
     */
    public const AGORA = "CAST(SYSDATETIMEOFFSET() AT TIME ZONE 'E. South America Standard Time' AS datetime)";

    /** @var PDO|null */
    private $pdo;

    /**
     * PABs que não andaram, só do ano letivo informado: processados
     * sem boleto e sem contrato (situação 3) ou com erro de integração (11).
     * O ano vale para as duas situações (na consulta original do SSMS, pela
     * precedência do AND sobre o OR, só valia para a 3).
     *
     * @return array<int, array>
     */
    public function pabsParados(int $ano): array
    {
        return $this->consultar(
            "SELECT
                a.cd_admissao, a.cd_contrato, a.nu_ra, p.cd_coligada, p.cd_filial,
                a.nm_aluno, p.dh_inicio, p.dh_termino, a.cd_situacao,
                s.ds_situacao_admissao, a.nu_quantidade_tentativas, a.dh_inclusao,
                p.nu_ano_letivo,
                DATEDIFF(DAY, a.dh_inclusao, ".self::AGORA.") AS dias_corridos
            FROM tb_admissao a
            JOIN tb_processo_admissao p ON p.cd_processo_admissao = a.cd_processo_admissao
            LEFT JOIN tb_situacao_admissao s ON s.cd_situacao_admissao = a.cd_situacao
            WHERE p.nu_ano_letivo = ?
              AND (
                    (a.cd_situacao = 3 AND a.cd_boleto IS NULL AND a.cd_contrato IS NULL)
                    OR a.cd_situacao = 11
              )
            ORDER BY a.dh_inclusao DESC",
            [$ano]
        );
    }

    /**
     * Matrículas (solicitações) aguardando integração com o RM (14) ou com
     * erro de integração (16), só do ano letivo informado, incluídas a partir
     * de 1º de janeiro desse ano, ou do atual se o ano for futuro
     * (consulta original: ano letivo >= 2026 e inclusão após 01/01/2026).
     *
     * @return array<int, array>
     */
    public function matriculasParadas(int $ano): array
    {
        return $this->consultar(
            "SELECT
                s.cd_solicitacao, s.cd_contrato, s.nu_ra,
                pe.nm_pessoa AS aluno,
                p.cd_processo, p.nm_processo AS processo, p.nu_ano_letivo AS ano,
                s.cd_situacao_solicitacao AS status,
                s.nu_quantidade_tentativas_integracao AS tentativas,
                s.dh_inclusao AS data_inclusao,
                DATEDIFF(DAY, s.dh_inclusao, ".self::AGORA.") AS dias_corridos,
                CASE s.cd_situacao_solicitacao
                    WHEN 1 THEN 'Em preenchimento'       WHEN 2 THEN 'Concluída'
                    WHEN 3 THEN 'Solicitada - aguard. parcelas' WHEN 4 THEN 'Solicitada - em processamento'
                    WHEN 5 THEN 'COM ERRO - geração de parcelas' WHEN 6 THEN 'Cancelada'
                    WHEN 7 THEN 'Agendado p/ reprocessamento' WHEN 8 THEN 'Pendente avaliação técnica'
                    WHEN 9 THEN 'Parcelas geradas'        WHEN 10 THEN 'Em fila proc. manual (2)'
                    WHEN 11 THEN 'Em fila proc. manual'   WHEN 12 THEN 'Em fila proc. local'
                    WHEN 13 THEN 'Em fila de espera'      WHEN 14 THEN 'Aguardando integrar RM'
                    WHEN 15 THEN 'Pré-preenchimento'      WHEN 16 THEN 'COM ERRO - integração'
                    WHEN 18 THEN 'Aguardando envio e-mail' WHEN 19 THEN 'COM ERRO - envio e-mail'
                    ELSE 'Desconhecido'
                END AS descricao_status,
                s.ds_erro,
                p.cd_coligada, p.cd_filial
            FROM tb_solicitacao s
            JOIN tb_processo p ON p.cd_processo = s.cd_processo
            LEFT JOIN tb_pessoa pe ON pe.cd_pessoa = s.cd_aluno
            WHERE s.cd_situacao_solicitacao IN (14, 16)
              AND p.nu_ano_letivo = ?
              AND s.dh_inclusao > ?
            ORDER BY p.dh_inclusao DESC, s.dh_inclusao DESC",
            [$ano, AnoLetivo::inicioDasInclusoes($ano).'T00:00:00']
        );
    }

    /**
     * Solicitações que o integradorLoteCommand pega, agrupadas por processo:
     * situação 14 ou 16, sem filtro de data (o command não tem). Serve para os
     * dois filtros dele: por processo é a linha do processo; por ano letivo é
     * a soma dos processos daquele ano.
     *
     * @return array<int, array{cd_processo: int, processo: string, ano: int, total: int}>
     */
    public function pendentesDeIntegracaoPorProcesso(): array
    {
        return array_map(function (array $linha) {
            return [
                'cd_processo' => (int) $linha['cd_processo'],
                'processo' => (string) $linha['processo'],
                'ano' => (int) $linha['ano'],
                'total' => (int) $linha['total'],
            ];
        }, $this->consultar(
            'SELECT p.cd_processo, p.nm_processo AS processo, p.nu_ano_letivo AS ano, COUNT(*) AS total
            FROM tb_solicitacao s
            JOIN tb_processo p ON p.cd_processo = s.cd_processo
            WHERE s.cd_situacao_solicitacao IN (14, 16)
            GROUP BY p.cd_processo, p.nm_processo, p.nu_ano_letivo
            ORDER BY p.nu_ano_letivo DESC, COUNT(*) DESC'
        ));
    }

    /**
     * Reservas de vaga paradas em alguma etapa que o sistema resolve sozinho:
     *
     *  - erro:            situação 7 (Com erro)
     *  - processando:     situação 6 (Em processamento) — travada no meio
     *  - sem_boleto:      situação 3 ou 5 sem boleto em rl_reserva_vaga_boleto
     *  - aguarda_pagamento: situação 5 (Termo assinado) com boleto, esperando
     *                     o RM dar baixa (alteraStatusReservaPaga)
     *
     * tem_envio_codigo importa para "sem_boleto": sem ele o
     * ProcessaReservaRMCommand ignora a reserva.
     *
     * @return array<int, array>
     */
    public function reservasParadas(int $ano): array
    {
        return $this->consultar(
            "SELECT
                r.cd_reserva_vaga, r.cd_contrato, r.nu_ra, r.nu_cpf, r.nm_pessoa,
                r.cd_aluno, r.cd_responsavel_financeiro, r.cd_turma,
                r.ds_turno_a_cursar, r.ds_periodo_a_cursar, r.in_colaborador,
                r.cd_situacao, sv.ds_situacao_reserva_vaga,
                r.nu_quantidade_tentativas, r.ds_erro,
                r.dh_inclusao, r.dh_alteracao,
                DATEDIFF(DAY, COALESCE(r.dh_alteracao, r.dh_inclusao), ".self::AGORA.") AS dias_parada,
                p.cd_processo_reserva, p.nm_processo, p.nu_ano_letivo, p.cd_coligada, p.cd_filial,
                CASE
                    WHEN r.cd_situacao = 7 THEN 'erro'
                    WHEN r.cd_situacao = 6 THEN 'processando'
                    WHEN b.cd_reserva_vaga IS NULL THEN 'sem_boleto'
                    ELSE 'aguarda_pagamento'
                END AS etapa,
                CASE WHEN EXISTS (
                    SELECT 1 FROM tb_envio_codigo e WHERE e.cd_responsavel_financeiro = r.cd_responsavel_financeiro
                ) THEN 1 ELSE 0 END AS tem_envio_codigo
            FROM tb_reserva_vaga r
            JOIN tb_processo_reserva p ON p.cd_processo_reserva = r.cd_processo_reserva
            LEFT JOIN tb_situacao_reserva_vaga sv ON sv.cd_situacao_reserva_vaga = r.cd_situacao
            OUTER APPLY (
                SELECT TOP 1 rb.cd_reserva_vaga FROM rl_reserva_vaga_boleto rb WHERE rb.cd_reserva_vaga = r.cd_reserva_vaga
            ) b
            WHERE p.nu_ano_letivo = ?
              AND r.cd_situacao IN (3, 5, 6, 7)
            ORDER BY
                CASE WHEN r.cd_situacao = 7 THEN 0 WHEN r.cd_situacao = 6 THEN 1 WHEN b.cd_reserva_vaga IS NULL THEN 2 ELSE 3 END,
                r.dh_inclusao DESC",
            [$ano]
        );
    }

    /**
     * Quantas reservas cada command pegaria agora, com a regra de cada um:
     *
     *  - integrar: ProcessaReservaRMCommand sem código (anos atual/seguinte;
     *    3/5 sem boleto e com envio de código, ou 7 com menos de 3 tentativas)
     *  - pagamento: alteraStatusReservaPaga (situação 5, ano >= o atual)
     *
     * @return array{integrar: int, pagamento: int}
     */
    public function contagemDosCommandsDeReserva(int $ano): array
    {
        $linhas = $this->consultar(
            "SELECT
                SUM(CASE WHEN x.ano IN (?, ?) AND (
                        (x.cd_situacao IN (3, 5) AND x.tem_boleto = 0 AND x.tem_envio_codigo = 1)
                        OR (x.cd_situacao = 7 AND (x.tentativas IS NULL OR x.tentativas < 3))
                    ) THEN 1 ELSE 0 END) AS integrar,
                SUM(CASE WHEN x.cd_situacao = 5 AND x.ano >= ? THEN 1 ELSE 0 END) AS pagamento
            FROM (
                SELECT
                    r.cd_situacao, p.nu_ano_letivo AS ano, r.nu_quantidade_tentativas AS tentativas,
                    CASE WHEN EXISTS (SELECT 1 FROM rl_reserva_vaga_boleto rb WHERE rb.cd_reserva_vaga = r.cd_reserva_vaga) THEN 1 ELSE 0 END AS tem_boleto,
                    CASE WHEN EXISTS (SELECT 1 FROM tb_envio_codigo e WHERE e.cd_responsavel_financeiro = r.cd_responsavel_financeiro) THEN 1 ELSE 0 END AS tem_envio_codigo
                FROM tb_reserva_vaga r
                JOIN tb_processo_reserva p ON p.cd_processo_reserva = r.cd_processo_reserva
                WHERE r.cd_situacao IN (3, 5, 7)
            ) x",
            [$ano, $ano + 1, $ano]
        );

        return [
            'integrar' => (int) ($linhas[0]['integrar'] ?? 0),
            'pagamento' => (int) ($linhas[0]['pagamento'] ?? 0),
        ];
    }

    /**
     * Deixa uma solicitação pronta para o integradorCommand: volta para
     * "Aguardando integrar RM" (14), zera as tentativas e limpa o erro.
     * Registra dh_alteracao (hora do banco) e nu_ip_alteracao (IP da VPN).
     *
     * Só altera se ela ainda estiver em 14 ou 16: uma lista desatualizada na
     * tela não pode reabrir uma matrícula que já foi integrada/concluída.
     * Tudo numa transação, com a linha travada entre a leitura e a escrita.
     *
     * @return array{situacao: int, tentativas: int|null, tinha_erro: bool} valores de antes
     */
    public function prepararReintegracao(int $codigoSolicitacao, string $ipVpn): array
    {
        $pdo = $this->pdo();

        try {
            $pdo->beginTransaction();

            $leitura = $pdo->prepare(
                'SELECT cd_situacao_solicitacao, nu_quantidade_tentativas_integracao,
                        CASE WHEN DATALENGTH(ds_erro) > 0 THEN 1 ELSE 0 END AS tinha_erro
                 FROM tb_solicitacao WITH (UPDLOCK, ROWLOCK)
                 WHERE cd_solicitacao = ?'
            );
            $leitura->execute([$codigoSolicitacao]);
            $antes = $leitura->fetch(PDO::FETCH_ASSOC);

            if (! $antes) {
                throw new RuntimeException("A solicitação {$codigoSolicitacao} não existe.");
            }

            $situacao = (int) $antes['cd_situacao_solicitacao'];
            if (! in_array($situacao, [14, 16], true)) {
                throw new RuntimeException(
                    "A solicitação {$codigoSolicitacao} não está mais aguardando integração nem com erro "
                    ."(situação atual: {$situacao}). Nada foi alterado; atualize a lista."
                );
            }

            $escrita = $pdo->prepare(
                'UPDATE tb_solicitacao
                 SET cd_situacao_solicitacao = 14,
                     nu_quantidade_tentativas_integracao = 0,
                     ds_erro = NULL,
                     dh_alteracao = '.self::AGORA.',
                     nu_ip_alteracao = ?
                 WHERE cd_solicitacao = ? AND cd_situacao_solicitacao IN (14, 16)'
            );
            $escrita->execute([mb_substr($ipVpn, 0, 40), $codigoSolicitacao]);

            if ($escrita->rowCount() !== 1) {
                throw new RuntimeException("Não consegui atualizar a solicitação {$codigoSolicitacao}. Nada foi alterado.");
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e instanceof RuntimeException
                ? $e
                : new RuntimeException('Falha ao atualizar a solicitação no banco do matrícula: '.$e->getMessage());
        }

        return [
            'situacao' => $situacao,
            'tentativas' => $antes['nu_quantidade_tentativas_integracao'] === null ? null : (int) $antes['nu_quantidade_tentativas_integracao'],
            'tinha_erro' => (bool) $antes['tinha_erro'],
        ];
    }

    /** Limite de linhas da busca de alunos. */
    public const LIMITE_BUSCA = 200;

    /**
     * Solicitações de um aluno, de qualquer situação. O termo pode ser:
     *  - CPF (11 dígitos, com ou sem pontuação): CPF do aluno;
     *  - só números: código da solicitação ou RA (com ou sem zeros à esquerda);
     *  - texto: nome do aluno, palavra por palavra, sem acento/maiúscula.
     *
     * Busca uma linha a mais que o limite para saber se o resultado foi cortado.
     *
     * @return array{tipo: string, linhas: array<int, array>, cortado: bool}
     */
    public function buscarSolicitacoesDoAluno(string $termo): array
    {
        $termo = trim($termo);
        $digitos = preg_replace('/\D/', '', $termo);
        $parametros = [];

        if (preg_match('/^\d{3}\.?\d{3}\.?\d{3}-?\d{2}$/', $termo) && strlen($digitos) === 11) {
            $tipo = 'CPF';
            $filtro = "REPLACE(REPLACE(REPLACE(s.nu_cpf_aluno, '.', ''), '-', ''), ' ', '') = ?";
            $parametros[] = $digitos;
        } elseif ($termo !== '' && ctype_digit($termo)) {
            $tipo = 'código da solicitação ou RA';
            // RA é texto com zeros à esquerda ("011956"): compara com as variações,
            // sem converter a coluna (converter obrigaria a ler a tabela inteira).
            $semZeros = ltrim($termo, '0') ?: '0';
            $variacoes = [$termo, $semZeros];
            for ($tamanho = strlen($semZeros) + 1; $tamanho <= 10; $tamanho++) {
                $variacoes[] = str_pad($semZeros, $tamanho, '0', STR_PAD_LEFT);
            }
            $variacoes = array_values(array_unique($variacoes));
            $filtro = 's.cd_solicitacao = ? OR s.nu_ra IN ('.implode(', ', array_fill(0, count($variacoes), '?')).')';
            $parametros[] = strlen($semZeros) > 9 ? 0 : (int) $semZeros;
            array_push($parametros, ...$variacoes);
        } else {
            $palavras = array_values(array_filter(preg_split('/\s+/', $termo) ?: [], function ($p) {
                return mb_strlen($p) >= 2;
            }));
            if ($palavras === [] || mb_strlen(implode('', $palavras)) < 3) {
                throw new RuntimeException('Digite pelo menos 3 letras do nome, o CPF, o RA ou o código da solicitação.');
            }
            $tipo = 'nome';
            $filtro = implode(' AND ', array_fill(0, count($palavras), 's.nm_aluno COLLATE Latin1_General_CI_AI LIKE ?'));
            foreach ($palavras as $palavra) {
                $parametros[] = '%'.str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $palavra).'%';
            }
        }

        $linhas = $this->consultar(
            'SELECT TOP '.(self::LIMITE_BUSCA + 1).'
                s.cd_solicitacao, s.nm_aluno, s.nu_ra, s.nu_cpf_aluno, s.dt_nascimento_aluno,
                s.cd_situacao_solicitacao AS status, s.ds_erro,
                s.nu_quantidade_tentativas_integracao AS tentativas,
                s.cd_contrato, s.cd_turma, s.ds_turno,
                s.dh_inclusao, s.dh_alteracao,
                p.cd_processo, p.nm_processo AS processo, p.nu_ano_letivo AS ano, p.cd_coligada, p.cd_filial
            FROM tb_solicitacao s
            LEFT JOIN tb_processo p ON p.cd_processo = s.cd_processo
            WHERE '.$filtro.'
            ORDER BY s.nm_aluno, s.dh_inclusao DESC',
            $parametros
        );

        return [
            'tipo' => $tipo,
            'linhas' => array_slice($linhas, 0, self::LIMITE_BUSCA),
            'cortado' => count($linhas) > self::LIMITE_BUSCA,
        ];
    }

    /**
     * Solicitações que o integradorLoteCommand vai pegar, com a situação de
     * agora: a mesma consulta dele (SolicitacaoDAO::buscarSolicitacoesPendentesIntegracaoLote
     * no matrícula), 14 ou 16 do ano letivo OU do processo, sem filtro de data.
     *
     * @return array<int, int> cd_solicitacao => situação
     */
    public function solicitacoesDoLote(?int $ano, ?int $processo): array
    {
        $filtro = $processo !== null ? 's.cd_processo = ?' : 'p.nu_ano_letivo = ?';
        $linhas = $this->consultar(
            "SELECT s.cd_solicitacao, s.cd_situacao_solicitacao AS situacao
            FROM tb_solicitacao s
            JOIN tb_processo p ON p.cd_processo = s.cd_processo
            WHERE s.cd_situacao_solicitacao IN (14, 16) AND {$filtro}",
            [$processo !== null ? $processo : $ano]
        );

        return array_map('intval', array_column($linhas, 'situacao', 'cd_solicitacao'));
    }

    /**
     * Situação atual de registros reprocessados, para a tela Gráficos.
     * Consulta em blocos de 1.000 códigos (o SQL Server aceita até 2.100 parâmetros).
     *
     * @param  string  $tipo  matriculas | pabs | reservas
     * @param  int[]  $codigos
     * @return array<int, array{situacao: int, erro?: string|null, sem_boleto_contrato?: bool, tem_boleto?: bool}>
     */
    public function situacaoAtual(string $tipo, array $codigos): array
    {
        $sql = [
            'matriculas' => 'SELECT cd_solicitacao AS codigo, cd_situacao_solicitacao AS situacao, ds_erro AS erro
                FROM tb_solicitacao WHERE cd_solicitacao IN (%s)',
            'pabs' => 'SELECT cd_admissao AS codigo, cd_situacao AS situacao,
                    CASE WHEN cd_boleto IS NULL AND cd_contrato IS NULL THEN 1 ELSE 0 END AS sem_boleto_contrato
                FROM tb_admissao WHERE cd_admissao IN (%s)',
            'reservas' => 'SELECT r.cd_reserva_vaga AS codigo, r.cd_situacao AS situacao, r.ds_erro AS erro,
                    CASE WHEN EXISTS (SELECT 1 FROM rl_reserva_vaga_boleto rb WHERE rb.cd_reserva_vaga = r.cd_reserva_vaga) THEN 1 ELSE 0 END AS tem_boleto
                FROM tb_reserva_vaga r WHERE r.cd_reserva_vaga IN (%s)',
        ][$tipo] ?? null;

        if ($sql === null) {
            throw new RuntimeException("Tipo de registro desconhecido: {$tipo}.");
        }

        $atuais = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $codigos))), 1000) as $bloco) {
            $linhas = $this->consultar(sprintf($sql, implode(', ', array_fill(0, count($bloco), '?'))), $bloco);
            foreach ($linhas as $linha) {
                $atual = ['situacao' => (int) $linha['situacao']];
                if (array_key_exists('erro', $linha)) {
                    $atual['erro'] = $linha['erro'];
                }
                if (array_key_exists('sem_boleto_contrato', $linha)) {
                    $atual['sem_boleto_contrato'] = (bool) $linha['sem_boleto_contrato'];
                }
                if (array_key_exists('tem_boleto', $linha)) {
                    $atual['tem_boleto'] = (bool) $linha['tem_boleto'];
                }
                $atuais[(int) $linha['codigo']] = $atual;
            }
        }

        return $atuais;
    }

    /**
     * Quantos registros estão parados agora, do ano letivo informado em
     * diante (foto diária da tela Gráficos):
     *  - matrículas: situação 14 ou 16;
     *  - PABs: 3 sem boleto e sem contrato, ou 11;
     *  - reservas: 6 ou 7, ou 3/5 sem boleto (as que aguardam pagamento não contam).
     *
     * @return array{matriculas: int, pabs: int, reservas: int}
     */
    public function contagemDeParadas(int $anoMinimo): array
    {
        $linhas = $this->consultar(
            'SELECT
                (SELECT COUNT(*) FROM tb_solicitacao s
                    JOIN tb_processo p ON p.cd_processo = s.cd_processo
                    WHERE s.cd_situacao_solicitacao IN (14, 16) AND p.nu_ano_letivo >= ?) AS matriculas,
                (SELECT COUNT(*) FROM tb_admissao a
                    JOIN tb_processo_admissao p ON p.cd_processo_admissao = a.cd_processo_admissao
                    WHERE p.nu_ano_letivo >= ?
                      AND ((a.cd_situacao = 3 AND a.cd_boleto IS NULL AND a.cd_contrato IS NULL) OR a.cd_situacao = 11)) AS pabs,
                (SELECT COUNT(*) FROM tb_reserva_vaga r
                    JOIN tb_processo_reserva p ON p.cd_processo_reserva = r.cd_processo_reserva
                    WHERE p.nu_ano_letivo >= ?
                      AND (r.cd_situacao IN (6, 7)
                           OR (r.cd_situacao IN (3, 5) AND NOT EXISTS (
                               SELECT 1 FROM rl_reserva_vaga_boleto rb WHERE rb.cd_reserva_vaga = r.cd_reserva_vaga)))) AS reservas',
            [$anoMinimo, $anoMinimo, $anoMinimo]
        );

        return [
            'matriculas' => (int) ($linhas[0]['matriculas'] ?? 0),
            'pabs' => (int) ($linhas[0]['pabs'] ?? 0),
            'reservas' => (int) ($linhas[0]['reservas'] ?? 0),
        ];
    }

    /**
     * Maior ano letivo entre os processos seletivos cadastrados (matrícula,
     * admissão/PAB e reserva). Ex.: já existindo processos de 2027, devolve 2027.
     */
    public function ultimoAnoLetivo(): ?int
    {
        $linhas = $this->consultar(
            'SELECT MAX(ano) AS ano FROM (
                SELECT MAX(nu_ano_letivo) AS ano FROM tb_processo
                UNION ALL SELECT MAX(nu_ano_letivo) FROM tb_processo_admissao
                UNION ALL SELECT MAX(nu_ano_letivo) FROM tb_processo_reserva
            ) x'
        );
        $ano = $linhas[0]['ano'] ?? null;

        return $ano === null ? null : (int) $ano;
    }

    /**
     * @return array<int, array>
     */
    private function consultar(string $sql, array $parametros = []): array
    {
        try {
            $consulta = $this->pdo()->prepare($sql);
            $consulta->execute($parametros);

            return $consulta->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('A consulta no banco do matrícula falhou: '.$e->getMessage());
        }
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $env = $this->envDoMatricula();

        foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $chave) {
            if (! isset($env[$chave]) || $env[$chave] === '') {
                throw new RuntimeException("Não achei {$chave} no .env do matrícula.");
            }
        }

        try {
            // LoginTimeout curto: com a VPN fora do ar a tela avisa em segundos,
            // em vez de pendurar a requisição (o padrão do driver espera muito).
            return $this->pdo = new PDO(
                "sqlsrv:Server={$env['DB_HOST']};Database={$env['DB_DATABASE']};LoginTimeout=8",
                $env['DB_USERNAME'],
                $env['DB_PASSWORD'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 60]
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Não consegui conectar no banco do matrícula ({$env['DB_HOST']}). "
                .'Confira a VPN/rede e tente de novo. Detalhe: '.$e->getMessage()
            );
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function envDoMatricula(): array
    {
        $arquivo = rtrim((string) config('painel.matricula.caminho'), '/').'/.env';

        if (! is_file($arquivo)) {
            throw new RuntimeException("Não encontrei o .env do matrícula em {$arquivo}.");
        }

        try {
            return Dotenv::parse((string) file_get_contents($arquivo));
        } catch (Throwable $e) {
            throw new RuntimeException('Não consegui ler o .env do matrícula: '.$e->getMessage());
        }
    }
}
