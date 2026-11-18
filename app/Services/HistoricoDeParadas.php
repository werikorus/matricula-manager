<?php

namespace App\Services;

use Throwable;

/**
 * Quantos registros estavam parados em cada dia, para o gráfico "Paradas em
 * aberto" da tela Gráficos.
 *
 * Decisão: o banco do matrícula não guarda histórico de situação, então o
 * painel tira uma "foto" da contagem quando uma tela de acompanhamento é
 * aberta (Gráficos, PABs, Matrículas, Reservas), no máximo a cada 30 minutos,
 * e guarda a última do dia. Dias em que o painel não foi aberto ficam sem ponto.
 *
 * Um JSON por ambiente em storage/app/paradas/: dia => {matriculas, pabs, reservas, em}.
 */
class HistoricoDeParadas
{
    private const INTERVALO = 30 * 60;

    private const DIAS_GUARDADOS = 400;

    /** @var BancoDoMatricula */
    private $banco;

    public function __construct(BancoDoMatricula $banco)
    {
        $this->banco = $banco;
    }

    /** Atualiza a foto de hoje se a última tiver mais de 30 min. Nunca lança. */
    public function registrar(): void
    {
        try {
            $dados = $this->ler();
            $hoje = date('Y-m-d');
            if (isset($dados[$hoje]['em']) && strtotime($dados[$hoje]['em']) > time() - self::INTERVALO) {
                return;
            }

            $contagem = $this->banco->contagemDeParadas((int) date('Y'));
            $dados[$hoje] = $contagem + ['em' => date(DATE_ATOM)];

            ksort($dados);
            $dados = array_slice($dados, -self::DIAS_GUARDADOS, null, true);

            $arquivo = $this->arquivo();
            if (! is_dir(dirname($arquivo))) {
                mkdir(dirname($arquivo), 0775, true);
            }
            file_put_contents($arquivo, json_encode($dados, JSON_PRETTY_PRINT), LOCK_EX);
        } catch (Throwable $e) {
            // Sem banco (VPN) ou sem disco: a tela segue sem a foto de hoje.
        }
    }

    /**
     * Fotos dos últimos $dias dias, da mais antiga para a mais recente.
     *
     * @return array<int, array{dia: string, matriculas: int, pabs: int, reservas: int}>
     */
    public function ultimos(int $dias): array
    {
        $desde = date('Y-m-d', strtotime('-'.($dias - 1).' days'));
        $lista = [];

        foreach ($this->ler() as $dia => $foto) {
            if ($dia >= $desde) {
                $lista[] = [
                    'dia' => $dia,
                    'matriculas' => (int) ($foto['matriculas'] ?? 0),
                    'pabs' => (int) ($foto['pabs'] ?? 0),
                    'reservas' => (int) ($foto['reservas'] ?? 0),
                ];
            }
        }

        return $lista;
    }

    private function arquivo(): string
    {
        return storage_path('app/paradas/'.preg_replace('/\W/', '', (string) config('painel.matricula.ambiente')).'.json');
    }

    private function ler(): array
    {
        $arquivo = $this->arquivo();
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;

        return is_array($dados) ? $dados : [];
    }
}
