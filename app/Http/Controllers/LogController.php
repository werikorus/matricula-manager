<?php

namespace App\Http\Controllers;

use App\Services\MatriculaArtisan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

/**
 * Últimas linhas dos logs do matrícula, como na tela "Dev > Commands" de lá.
 */
class LogController extends Controller
{
    public function __invoke(Request $request, MatriculaArtisan $matricula): JsonResponse
    {
        $fontes = (array) config('painel.logs', []);
        $fonte = (string) $request->query('fonte', (string) array_key_first($fontes));
        $linhas = max(50, min(2000, (int) $request->query('linhas', 200)));

        if (! isset($fontes[$fonte])) {
            return response()->json(['arquivo' => null, 'conteudo' => '(fonte de log desconhecida)'], 404);
        }

        $arquivo = $this->arquivoMaisRecente($matricula->caminho().'/'.ltrim($fontes[$fonte]['arquivo'], '/'));

        if ($arquivo === null) {
            return response()->json(['arquivo' => null, 'conteudo' => '(nenhum arquivo de log encontrado)']);
        }

        $tail = new Process(['tail', '-n', (string) $linhas, $arquivo]);
        $tail->setTimeout(10)->run();

        return response()->json([
            'arquivo' => basename($arquivo),
            'conteudo' => mb_scrub($tail->getOutput(), 'UTF-8') ?: '(arquivo vazio)',
        ]);
    }

    /**
     * O driver "daily" do Laravel grava em {nome}-AAAA-MM-DD.log. Usa o do dia,
     * senão o mais recente, senão o próprio {nome}.log (driver "single").
     */
    private function arquivoMaisRecente(string $base): ?string
    {
        $pasta = dirname($base);
        $nome = pathinfo($base, PATHINFO_FILENAME);

        $hoje = "{$pasta}/{$nome}-".date('Y-m-d').'.log';
        if (is_file($hoje)) {
            return $hoje;
        }

        $diarios = glob("{$pasta}/{$nome}-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log") ?: [];
        if ($diarios !== []) {
            sort($diarios);

            return end($diarios);
        }

        return is_file($base) ? $base : null;
    }
}
