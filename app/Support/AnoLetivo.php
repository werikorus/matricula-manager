<?php

namespace App\Support;

use App\Services\BancoDoMatricula;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Filtro "ano letivo a partir de" das telas de acompanhamento: do último ano
 * com processo seletivo cadastrado (ex.: 2027) até 2 anos antes do atual,
 * escolhido num select (?ano=2025), com o ano atual por padrão.
 *
 * "A partir de" (>=) e não "igual": o ano seguinte (rematrículas, reservas)
 * continua aparecendo quando o filtro está no ano atual.
 */
class AnoLetivo
{
    public const QUANTOS = 3;

    /**
     * Do ano mais recente com processo seletivo cadastrado (ex.: 2027) até
     * 2 anos antes do atual.
     *
     * @return int[] do mais recente para o mais antigo
     */
    public static function opcoes(): array
    {
        $atual = (int) date('Y');
        $ultimo = max($atual, self::ultimoComProcesso() ?? $atual);

        return range($ultimo, $atual - self::QUANTOS + 1);
    }

    /**
     * Maior ano letivo dos processos do ambiente em uso. Em cache por 10 min
     * (por ambiente); sem banco, fica de fora por 1 min (só os anos fixos)
     * para não esperar o tempo de conexão a cada tela.
     */
    private static function ultimoComProcesso(): ?int
    {
        static $porAmbiente = [];
        $ambiente = (string) config('painel.matricula.ambiente');

        if (! array_key_exists($ambiente, $porAmbiente)) {
            $chave = 'painel.ultimo_ano_letivo.'.$ambiente;
            $guardado = Cache::get($chave);

            if (is_array($guardado)) {
                $porAmbiente[$ambiente] = $guardado['ano'];
            } else {
                try {
                    $ano = app(BancoDoMatricula::class)->ultimoAnoLetivo();
                    // Protege contra um ano digitado errado num processo (ex.: 2207).
                    $ano = ($ano !== null && $ano <= (int) date('Y') + 3) ? $ano : null;
                    Cache::put($chave, ['ano' => $ano], now()->addMinutes(10));
                } catch (Throwable $e) {
                    $ano = null;
                    Cache::put($chave, ['ano' => null], now()->addMinute());
                }
                $porAmbiente[$ambiente] = $ano;
            }
        }

        return $porAmbiente[$ambiente];
    }

    /**
     * Data de corte da inclusão das matrículas para um ano letivo: 1º de
     * janeiro dele, mas nunca no futuro. As matrículas de 2027 são feitas em
     * 2026, então para 2027 o corte é 01/01/2026.
     */
    public static function inicioDasInclusoes(int $ano): string
    {
        return min($ano, (int) date('Y')).'-01-01';
    }

    /** Ano escolhido na requisição (?ano=) ou o atual, se ausente ou inválido. */
    public static function escolhido(?Request $request = null): int
    {
        $request = $request ?: request();
        $ano = (int) $request->input('ano', 0);

        return in_array($ano, self::opcoes(), true) ? $ano : (int) date('Y');
    }
}
