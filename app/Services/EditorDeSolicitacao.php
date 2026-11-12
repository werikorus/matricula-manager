<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Leitura e edição de um registro da tb_solicitacao.
 *
 * Decisão: a tela é montada a partir do esquema da tabela (sys.columns), não
 * de uma lista fixa de campos. Assim cada coluna ganha o controle certo para o
 * tipo dela, e uma coluna nova no matrícula aparece sem mexer no painel.
 *
 * Segurança da gravação:
 *  - só colunas que existem no esquema e não são chave/identidade/calculadas;
 *  - valores validados pelo tipo, tamanho e nulidade da coluna;
 *  - concorrência otimista: cada campo alterado leva o valor que a tela leu;
 *    se o banco tiver outro valor agora, nada é gravado;
 *  - tudo numa transação, com a linha travada entre a conferência e o UPDATE;
 *  - cada gravação vai para storage/app/auditoria/tb_solicitacao.log;
 *  - dh_alteracao (agora, horário de Brasília) e nu_ip_alteracao (IP da VPN) são
 *    preenchidos automaticamente em toda gravação e não são editáveis.
 */
class EditorDeSolicitacao
{
    public const TABELA = 'tb_solicitacao';

    public const CHAVE = 'cd_solicitacao';

    /** Agrupamento das colunas na tela, na ordem em que aparecem. A primeira regra que casar vence. */
    public const GRUPOS = [
        'situacao' => ['titulo' => 'Situação e integração', 'regra' => '/^(cd_situacao_solicitacao|ds_erro|nu_quantidade_tentativas_|dh_integracao|dh_processamento|dh_geracao_parcela|in_reprocessou|cd_step|flow_version)/'],
        'aluno' => ['titulo' => 'Aluno', 'regra' => '/^(nm_aluno|dt_nascimento_aluno|nu_cpf_aluno|nu_ra|cd_aluno|in_ex_aluno|cd_ficha_medica)/'],
        'responsaveis' => ['titulo' => 'Responsáveis', 'regra' => '/^(cd_responsavel_|cd_pai|cd_mae|ds_grau_parentesco)/'],
        'processo' => ['titulo' => 'Processo, série e turma', 'regra' => '/^(cd_processo|cd_serie|ds_turno|cd_turma|cd_periodo_letivo|cd_habilitacao_filial|cd_modalidade|dh_alteracao_modalidade|ds_usuario_alteracao_modalidade|in_autorizacao_|in_utiliza_curso_bilingue)/'],
        'financeiro' => ['titulo' => 'Financeiro e contratos', 'regra' => '/^(cd_plano_|nm_plano_|cd_boleto_|cd_contrato|in_plano_|in_taxa_|cd_movimento_material|cd_fornecedor_rm|in_permite_nao_adesao|cd_forma_entrega)/'],
        'vinculos' => ['titulo' => 'Vínculos e e-mails', 'regra' => '/^(cd_admissao_vinculada|cd_reserva_vinculada|in_email_|nu_codigo_validacao|nu_envio_codigo|nu_telefone_envio|dh_envio_codigo|dh_inclusao_codigo)/'],
        'indicacao' => ['titulo' => 'Indicação', 'regra' => '/indicacao/'],
        'textos' => ['titulo' => 'Termos e observações', 'regra' => '/^(ds_termo_|ds_observacao)/'],
        'controle' => ['titulo' => 'Datas e controle', 'regra' => '/^(dh_|nu_ip_)/'],
        'outros' => ['titulo' => 'Outros', 'regra' => '/./'],
    ];

    /** Colunas preenchidas pelo painel em toda gravação. */
    public const AUTOMATICOS = [
        'dh_alteracao' => 'Data e hora da gravação (horário de Brasília)',
        'nu_ip_alteracao' => 'IP desta máquina na VPN',
    ];

    private const INTEIROS = ['tinyint' => [0, 255], 'smallint' => [-32768, 32767], 'int' => [-2147483648, 2147483647], 'bigint' => [PHP_INT_MIN, PHP_INT_MAX]];

    /** @var BancoDoMatricula */
    private $banco;

    /** @var IpDaVpn */
    private $ip;

    public function __construct(BancoDoMatricula $banco, IpDaVpn $ip)
    {
        $this->banco = $banco;
        $this->ip = $ip;
    }

    /**
     * Colunas da tabela com o tipo de controle de cada uma. Guardado em cache
     * por 10 minutos: o esquema quase nunca muda.
     *
     * @return array<string, array>
     */
    public function colunas(): array
    {
        return Cache::remember('painel.esquema.'.self::TABELA, now()->addMinutes(10), function () {
            $sql = "SELECT c.name, t.name AS tipo, c.max_length, c.precision, c.scale,
                        c.is_nullable, c.is_identity, c.is_computed,
                        (SELECT TOP 1 OBJECT_NAME(fk.referenced_object_id) + '.' + COL_NAME(fk.referenced_object_id, fk.referenced_column_id)
                           FROM sys.foreign_key_columns fk
                          WHERE fk.parent_object_id = c.object_id AND fk.parent_column_id = c.column_id) AS referencia,
                        CASE WHEN EXISTS (
                            SELECT 1 FROM sys.index_columns ic
                            JOIN sys.indexes i ON i.object_id = ic.object_id AND i.index_id = ic.index_id
                            WHERE i.is_primary_key = 1 AND ic.object_id = c.object_id AND ic.column_id = c.column_id
                        ) THEN 1 ELSE 0 END AS chave
                    FROM sys.columns c
                    JOIN sys.types t ON t.user_type_id = c.user_type_id
                    WHERE c.object_id = OBJECT_ID(?)
                    ORDER BY c.column_id";

            $consulta = $this->banco->pdo()->prepare($sql);
            $consulta->execute([self::TABELA]);

            $colunas = [];
            foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $colunas[$c['name']] = $this->descrever($c);
            }

            if ($colunas === []) {
                throw new RuntimeException('Não encontrei a tabela '.self::TABELA.' no banco do matrícula.');
            }

            return $colunas;
        });
    }

    /**
     * @return array<string, string|null> valores como o driver devolve (texto ou null)
     */
    public function buscar(int $codigo): ?array
    {
        $consulta = $this->banco->pdo()->prepare('SELECT * FROM '.self::TABELA.' WHERE '.self::CHAVE.' = ?');
        $consulta->execute([$codigo]);
        $linha = $consulta->fetch(PDO::FETCH_ASSOC);

        return $linha ?: null;
    }

    /** @return array<string, string> código => descrição */
    public function situacoes(): array
    {
        return Cache::remember('painel.situacoes_solicitacao', now()->addMinutes(10), function () {
            $linhas = $this->banco->pdo()->query('SELECT * FROM tb_situacao_solicitacao ORDER BY 1')->fetchAll(PDO::FETCH_NUM);
            $situacoes = [];
            foreach ($linhas as $linha) {
                $situacoes[(string) $linha[0]] = trim((string) ($linha[1] ?? ''));
            }

            return $situacoes;
        });
    }

    /** Colunas agrupadas para a tela. @return array<string, array{titulo: string, colunas: array}> */
    public function agrupadas(): array
    {
        $grupos = [];
        foreach (self::GRUPOS as $chave => $grupo) {
            $grupos[$chave] = ['titulo' => $grupo['titulo'], 'colunas' => []];
        }

        foreach ($this->colunas() as $nome => $coluna) {
            foreach (self::GRUPOS as $chave => $grupo) {
                if (preg_match($grupo['regra'], $nome)) {
                    $grupos[$chave]['colunas'][$nome] = $coluna;
                    break;
                }
            }
        }

        return array_filter($grupos, function ($g) { return $g['colunas'] !== []; });
    }

    /**
     * Grava os campos alterados.
     *
     * @param  array<string, array{valor: ?string, nulo: bool, original: ?string}>  $alteracoes
     * @return array<int, array{campo: string, antes: ?string, depois: ?string}>
     */
    public function salvar(int $codigo, array $alteracoes, string $usuario): array
    {
        $colunas = $this->colunas();
        $erros = [];
        $novos = [];

        foreach ($alteracoes as $campo => $alteracao) {
            $coluna = $colunas[$campo] ?? null;
            if ($coluna === null) {
                $erros[$campo] = "A coluna \"{$campo}\" não existe em ".self::TABELA.'.';
                continue;
            }
            if (! $coluna['editavel']) {
                $erros[$campo] = "A coluna \"{$campo}\" não pode ser editada.";
                continue;
            }

            try {
                $novos[$campo] = $this->normalizar($coluna, $alteracao['valor'] ?? null, (bool) ($alteracao['nulo'] ?? false));
            } catch (RuntimeException $e) {
                $erros[$campo] = $e->getMessage();
            }
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
        if ($novos === []) {
            throw new RuntimeException('Nenhum campo foi alterado.');
        }

        // Antes de abrir a transação: sem o IP da VPN, não grava.
        $automaticos = array_intersect_key(self::AUTOMATICOS, $colunas);
        $ip = isset($automaticos['nu_ip_alteracao'])
            ? mb_substr($this->ip->obter()['ip'], 0, (int) ($colunas['nu_ip_alteracao']['caracteres'] ?: 40))
            : null;

        $pdo = $this->banco->pdo();
        $diferencas = [];

        try {
            $pdo->beginTransaction();

            $lista = implode(', ', array_map(function ($c) { return '['.$c.']'; }, array_keys($novos + $automaticos)));
            $leitura = $pdo->prepare('SELECT '.$lista.' FROM '.self::TABELA.' WITH (UPDLOCK, ROWLOCK) WHERE '.self::CHAVE.' = ?');
            $leitura->execute([$codigo]);
            $atual = $leitura->fetch(PDO::FETCH_ASSOC);

            if (! $atual) {
                throw new RuntimeException("A solicitação {$codigo} não existe mais.");
            }

            // Alguém (ou uma rotina) mudou o registro desde que a tela foi aberta?
            $mudaramNoBanco = [];
            foreach ($novos as $campo => $valor) {
                $lido = array_key_exists('original', $alteracoes[$campo]) ? $alteracoes[$campo]['original'] : null;
                if ($atual[$campo] !== $lido) {
                    $mudaramNoBanco[] = $campo;
                }
            }
            if ($mudaramNoBanco !== []) {
                throw new RuntimeException(
                    'O registro mudou no banco depois que você abriu a tela (campos: '.implode(', ', $mudaramNoBanco).'). '
                    .'Nada foi gravado. Recarregue a tela para ver os valores atuais e refaça a alteração.'
                );
            }

            $sets = [];
            $parametros = [];
            foreach ($novos as $campo => $valor) {
                if ($valor === null) {
                    $sets[] = '['.$campo.'] = NULL';
                } else {
                    $sets[] = '['.$campo.'] = ?';
                    $parametros[] = $valor;
                }
                $diferencas[] = ['campo' => $campo, 'antes' => $atual[$campo], 'depois' => $valor];
            }
            if (isset($automaticos['dh_alteracao'])) {
                $sets[] = '[dh_alteracao] = '.BancoDoMatricula::AGORA;
            }
            if ($ip !== null) {
                $sets[] = '[nu_ip_alteracao] = ?';
                $parametros[] = $ip;
            }
            $parametros[] = $codigo;

            $escrita = $pdo->prepare('UPDATE '.self::TABELA.' SET '.implode(', ', $sets).' WHERE '.self::CHAVE.' = ?');
            $escrita->execute($parametros);

            if ($escrita->rowCount() !== 1) {
                throw new RuntimeException("Não consegui atualizar a solicitação {$codigo}. Nada foi gravado.");
            }

            // Valores que o banco gravou nos campos automáticos.
            if ($automaticos !== []) {
                $releitura = $pdo->prepare('SELECT '.implode(', ', array_map(function ($c) { return '['.$c.']'; }, array_keys($automaticos))).' FROM '.self::TABELA.' WHERE '.self::CHAVE.' = ?');
                $releitura->execute([$codigo]);
                $gravado = $releitura->fetch(PDO::FETCH_ASSOC) ?: [];
                foreach (array_keys($automaticos) as $campo) {
                    $diferencas[] = ['campo' => $campo, 'antes' => $atual[$campo], 'depois' => $gravado[$campo] ?? null, 'automatico' => true];
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e instanceof RuntimeException
                ? $e
                : new RuntimeException('Falha ao gravar no banco do matrícula: '.$e->getMessage());
        }

        $this->auditar($codigo, $usuario, $diferencas);

        return $diferencas;
    }

    // ── Tipos ──────────────────────────────────────────────

    private function descrever(array $c): array
    {
        $tipo = strtolower($c['tipo']);
        $tamanho = (int) $c['max_length'];
        $chave = (bool) $c['chave'];
        $automatico = isset(self::AUTOMATICOS[$c['name']]);
        $somenteLeitura = $chave || $c['is_identity'] || $c['is_computed'] || $automatico;

        if (isset(self::INTEIROS[$tipo])) {
            $controle = 'inteiro';
        } elseif (in_array($tipo, ['decimal', 'numeric', 'money', 'smallmoney', 'float', 'real'], true)) {
            $controle = 'decimal';
        } elseif ($tipo === 'bit') {
            $controle = 'bit';
        } elseif ($tipo === 'date') {
            $controle = 'data';
        } elseif (in_array($tipo, ['datetime', 'datetime2', 'smalldatetime'], true)) {
            $controle = 'datahora';
        } elseif (in_array($tipo, ['text', 'ntext'], true) || (in_array($tipo, ['varchar', 'nvarchar'], true) && $tamanho === -1)) {
            $controle = 'textolongo';
        } elseif (in_array($tipo, ['char', 'varchar', 'nchar', 'nvarchar'], true)) {
            $controle = 'texto';
        } else {
            // binary, varbinary, image, timestamp, uniqueidentifier, xml…: só leitura.
            $controle = 'outro';
            $somenteLeitura = true;
        }

        $caracteres = null;
        if ($controle === 'texto') {
            $caracteres = in_array($tipo, ['nchar', 'nvarchar'], true) ? intdiv($tamanho, 2) : $tamanho;
        }

        $rotuloTipo = $tipo;
        if ($caracteres !== null) {
            $rotuloTipo .= "({$caracteres})";
        } elseif ($tipo === 'varchar' || $tipo === 'nvarchar') {
            $rotuloTipo .= '(max)';
        } elseif (in_array($tipo, ['decimal', 'numeric'], true)) {
            $rotuloTipo .= "({$c['precision']},{$c['scale']})";
        }

        return [
            'nome' => $c['name'],
            'tipo' => $tipo,
            'rotulo_tipo' => $rotuloTipo,
            'controle' => $controle,
            'caracteres' => $caracteres,
            'nulo' => (bool) $c['is_nullable'],
            'chave' => $chave,
            'editavel' => ! $somenteLeitura,
            'automatico' => $automatico ? self::AUTOMATICOS[$c['name']] : null,
            'referencia' => $c['referencia'],
        ];
    }

    /**
     * Converte o valor do formulário para o que vai ao banco, validando.
     * Datas vão em ISO com "T", que o SQL Server lê igual em qualquer idioma.
     */
    private function normalizar(array $coluna, ?string $valor, bool $nulo): ?string
    {
        if ($nulo) {
            if (! $coluna['nulo']) {
                throw new RuntimeException('Este campo não aceita NULL.');
            }

            return null;
        }

        $valor = (string) $valor;
        $sem = trim($valor);

        switch ($coluna['controle']) {
            case 'inteiro':
                if (! preg_match('/^-?\d+$/', $sem)) {
                    throw new RuntimeException('Informe um número inteiro.');
                }
                [$min, $max] = self::INTEIROS[$coluna['tipo']];
                if (strlen(ltrim($sem, '-')) > 19 || (int) $sem < $min || (int) $sem > $max) {
                    throw new RuntimeException("Fora do intervalo de {$coluna['tipo']}.");
                }

                return (string) (int) $sem;

            case 'decimal':
                $sem = str_replace(',', '.', $sem);
                if (! is_numeric($sem)) {
                    throw new RuntimeException('Informe um número (use ponto ou vírgula para decimais).');
                }

                return $sem;

            case 'bit':
                if (! in_array($sem, ['0', '1'], true)) {
                    throw new RuntimeException('Escolha sim ou não.');
                }

                return $sem;

            case 'data':
                $data = \DateTime::createFromFormat('!Y-m-d', $sem);
                if (! $data || $data->format('Y-m-d') !== $sem) {
                    throw new RuntimeException('Data inválida.');
                }

                return $sem;

            case 'datahora':
                $formatos = ['!Y-m-d\TH:i:s', '!Y-m-d\TH:i', '!Y-m-d H:i:s', '!Y-m-d H:i'];
                foreach ($formatos as $formato) {
                    $data = \DateTime::createFromFormat($formato, $sem);
                    if ($data && $data->format(ltrim($formato, '!')) === $sem) {
                        return $data->format('Y-m-d\TH:i:s');
                    }
                }
                throw new RuntimeException('Data e hora inválidas.');

            case 'texto':
                if (mb_strlen($valor) > $coluna['caracteres']) {
                    throw new RuntimeException("Use no máximo {$coluna['caracteres']} caracteres.");
                }
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $valor)) {
                    throw new RuntimeException('Tem caracteres de controle.');
                }

                return $valor;

            case 'textolongo':
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $valor)) {
                    throw new RuntimeException('Tem caracteres de controle.');
                }

                return $valor;
        }

        throw new RuntimeException('Tipo de coluna não editável.');
    }

    private function auditar(int $codigo, string $usuario, array $diferencas): void
    {
        $pasta = storage_path('app/auditoria');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }

        $registro = [
            'quando' => date(DATE_ATOM),
            'usuario' => $usuario,
            'tabela' => self::TABELA,
            'chave' => $codigo,
            'alteracoes' => $diferencas,
        ];

        file_put_contents(
            $pasta.'/'.self::TABELA.'.log',
            json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
