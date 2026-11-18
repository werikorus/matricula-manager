<?php

namespace App\Services;

use App\Models\Execucao;
use App\Support\Erros;
use Throwable;

/**
 * Reprocessamentos feitos pelo painel e o que aconteceu com cada registro,
 * para a tela Gráficos.
 *
 * Decisão: o código de saída do command não diz se o registro foi resolvido
 * (o integrador termina com 0 mesmo quando a solicitação volta para 16). Por
 * isso o painel guarda QUAIS registros mandou reprocessar e, na hora do
 * gráfico, consulta a situação atual de cada um no banco.
 *
 * Um arquivo JSONL por ambiente em storage/app/reprocessamentos/, uma linha
 * por registro reprocessado. Entram:
 *  - integradorCommand, ProcessaAdmissaoRMCommand e ProcessaReservaRMCommand
 *    com o código do registro (linha das telas ou tela Commands);
 *  - integradorLoteCommand: a lista 14/16 do ano ou do processo, lida antes
 *    de disparar (é exatamente o que o command pega).
 * Ficam de fora os commands de PAB/reserva SEM código: eles pegam só os
 * primeiros 3/2 registros da regra deles (TOP no SQL), e o painel não sabe quais.
 */
class Reprocessamentos
{
    /** Command => tipo de registro. */
    public const TIPOS = [
        'integradorCommand' => 'matriculas',
        'integradorLoteCommand' => 'matriculas',
        'ProcessaAdmissaoRMCommand' => 'pabs',
        'ProcessaReservaRMCommand' => 'reservas',
    ];

    /** Quantos dias a tela Gráficos considera: 13 semanas, o maior período do filtro. */
    public const DIAS = 91;

    /** Acima deste tamanho, o arquivo é reescrito só com os últimos DIAS_GUARDADOS dias. */
    private const TAMANHO_MAXIMO = 4 * 1024 * 1024;

    private const DIAS_GUARDADOS = 180;

    /** Situações de solicitação "COM ERRO" (geração de parcelas, integração, e-mail). */
    private const SOLICITACAO_COM_ERRO = [5, 16, 19];

    /** @var BancoDoMatricula */
    private $banco;

    public function __construct(BancoDoMatricula $banco)
    {
        $this->banco = $banco;
    }

    /**
     * Guarda os registros que a execução vai reprocessar. Chamar ANTES de
     * disparar, para a lista do lote ser a de antes do command mexer.
     * Nunca lança: o registro não pode impedir um reprocessamento.
     *
     * @param  array<int, int|null>  $situacoesAntes  código => situação, quando quem chama já sabe
     *                                                (ex.: o reprocessar de Matrículas já alterou o registro)
     */
    public function registrar(Execucao $execucao, array $situacoesAntes = []): void
    {
        try {
            $tipo = self::TIPOS[(string) $execucao->comando] ?? null;
            if ($tipo === null) {
                return;
            }

            $posicionais = self::posicionais($execucao->argumentos ?: []);
            $origem = 'linha';

            if ($execucao->comando === 'integradorLoteCommand') {
                [$filtro, $valor] = $posicionais + [null, null];
                if (! ctype_digit((string) $valor) || ! in_array($filtro, ['anoLetivo', 'processo'], true)) {
                    return;
                }
                $situacoesAntes = $filtro === 'processo'
                    ? $this->banco->solicitacoesDoLote(null, (int) $valor)
                    : $this->banco->solicitacoesDoLote((int) $valor, null);
                $origem = 'lote';
            } else {
                $codigo = $posicionais[0] ?? null;
                if (! ctype_digit((string) $codigo)) {
                    return; // sem código: o command escolhe os registros (veja o docblock da classe)
                }
                $codigo = (int) $codigo;
                if (! array_key_exists($codigo, $situacoesAntes)) {
                    $atual = $this->banco->situacaoAtual($tipo, [$codigo]);
                    $situacoesAntes = [$codigo => $atual[$codigo]['situacao'] ?? null];
                }
            }

            if ($situacoesAntes === []) {
                return;
            }

            $quando = date(DATE_ATOM);
            $linhas = '';
            foreach ($situacoesAntes as $codigo => $situacao) {
                $linhas .= json_encode([
                    'quando' => $quando,
                    'tipo' => $tipo,
                    'codigo' => (int) $codigo,
                    'situacao_antes' => $situacao === null ? null : (int) $situacao,
                    'execucao' => $execucao->id,
                    'comando' => $execucao->comando,
                    'origem' => $origem,
                ], JSON_UNESCAPED_UNICODE)."\n";
            }

            $this->anexar($linhas);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Reprocessamentos dos últimos DIAS dias com o resultado atual de cada
     * registro (o mais recente de cada registro, sem repetir).
     *
     * @return array<int, array{tipo: string, dia: string, resultado: string, erro: string|null}>
     */
    public function comResultado(): array
    {
        $desde = strtotime('-'.self::DIAS.' days');
        $ultimos = [];

        foreach ($this->ler() as $item) {
            $quando = strtotime((string) ($item['quando'] ?? ''));
            if (! $quando || $quando < $desde || ! isset($item['tipo'], $item['codigo'])) {
                continue;
            }
            $chave = $item['tipo'].':'.$item['codigo'];
            if (! isset($ultimos[$chave]) || $quando >= $ultimos[$chave]['quando']) {
                $ultimos[$chave] = ['tipo' => $item['tipo'], 'codigo' => (int) $item['codigo'], 'quando' => $quando];
            }
        }

        $codigosPorTipo = [];
        foreach ($ultimos as $item) {
            $codigosPorTipo[$item['tipo']][] = $item['codigo'];
        }

        $atuais = [];
        foreach ($codigosPorTipo as $tipo => $codigos) {
            $atuais[$tipo] = $this->banco->situacaoAtual($tipo, $codigos);
        }

        $resultado = [];
        foreach ($ultimos as $item) {
            $atual = $atuais[$item['tipo']][$item['codigo']] ?? null;
            if ($atual === null) {
                continue; // registro apagado: não há o que medir
            }
            $classe = self::classificar($item['tipo'], $atual);
            $resultado[] = [
                'tipo' => $item['tipo'],
                'dia' => date('Y-m-d', $item['quando']),
                'resultado' => $classe,
                'erro' => $classe === 'voltou' && ($atual['erro'] ?? '') !== '' ? Erros::resumir((string) $atual['erro']) : null,
            ];
        }

        usort($resultado, function ($a, $b) {
            return strcmp($a['dia'], $b['dia']);
        });

        return $resultado;
    }

    /**
     * resolvido = saiu da fila de erro; parado = ainda aguardando;
     * voltou = está com erro de novo.
     */
    public static function classificar(string $tipo, array $atual): string
    {
        $situacao = (int) $atual['situacao'];

        switch ($tipo) {
            case 'matriculas':
                if (in_array($situacao, self::SOLICITACAO_COM_ERRO, true)) {
                    return 'voltou';
                }

                return $situacao === 14 ? 'parado' : 'resolvido';

            case 'pabs':
                if ($situacao === 11) {
                    return 'voltou';
                }

                return $situacao === 3 && ! empty($atual['sem_boleto_contrato']) ? 'parado' : 'resolvido';

            case 'reservas':
                if ($situacao === 7) {
                    return 'voltou';
                }
                if ($situacao === 6 || (in_array($situacao, [3, 5], true) && empty($atual['tem_boleto']))) {
                    return 'parado';
                }

                return 'resolvido';
        }

        return 'resolvido';
    }

    /**
     * Argumentos posicionais gravados na execução: o que vem depois de "--"
     * (veja MatriculaArtisan::montarArgumentos).
     *
     * @param  string[]  $argumentos
     * @return string[]
     */
    public static function posicionais(array $argumentos): array
    {
        $separador = array_search('--', $argumentos, true);

        return $separador === false ? [] : array_values(array_slice($argumentos, $separador + 1));
    }

    private function arquivo(): string
    {
        return storage_path('app/reprocessamentos/'.preg_replace('/\W/', '', (string) config('painel.matricula.ambiente')).'.jsonl');
    }

    private function anexar(string $linhas): void
    {
        $arquivo = $this->arquivo();
        if (! is_dir(dirname($arquivo))) {
            mkdir(dirname($arquivo), 0775, true);
        }

        file_put_contents($arquivo, $linhas, FILE_APPEND | LOCK_EX);

        clearstatcache(true, $arquivo);
        if (filesize($arquivo) > self::TAMANHO_MAXIMO) {
            $this->podar();
        }
    }

    /** Reescreve o arquivo só com os últimos DIAS_GUARDADOS dias. */
    private function podar(): void
    {
        $desde = strtotime('-'.self::DIAS_GUARDADOS.' days');
        $manter = '';
        foreach ($this->ler() as $item) {
            if (strtotime((string) ($item['quando'] ?? '')) >= $desde) {
                $manter .= json_encode($item, JSON_UNESCAPED_UNICODE)."\n";
            }
        }
        file_put_contents($this->arquivo(), $manter, LOCK_EX);
    }

    /** @return iterable<array> */
    private function ler(): iterable
    {
        $arquivo = $this->arquivo();
        if (! is_file($arquivo)) {
            return [];
        }

        $ponteiro = fopen($arquivo, 'r');
        flock($ponteiro, LOCK_SH);
        $itens = [];
        while (($linha = fgets($ponteiro)) !== false) {
            $item = json_decode($linha, true);
            if (is_array($item)) {
                $itens[] = $item;
            }
        }
        flock($ponteiro, LOCK_UN);
        fclose($ponteiro);

        return $itens;
    }
}
