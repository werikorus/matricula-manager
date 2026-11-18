<?php

namespace App\Http\Controllers;

use App\Models\Execucao;
use App\Services\MatriculaArtisan;
use App\Services\Reprocessamentos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ExecucaoController extends Controller
{
    /** Máximo de bytes de saída devolvidos por consulta. */
    private const TRECHO = 262144;

    public function index(): JsonResponse
    {
        Execucao::sincronizarOrfas();

        return response()->json([
            'execucoes' => array_map(function (Execucao $execucao) {
                return $execucao->resumo();
            }, Execucao::recentes(30)),
        ]);
    }

    public function store(Request $request, MatriculaArtisan $matricula, Reprocessamentos $reprocessamentos): JsonResponse
    {
        $dados = $request->validate([
            'comando' => ['required', 'string', 'max:200'],
            'argumentos' => ['array'],
            'opcoes' => ['array'],
        ]);

        try {
            $comando = $matricula->comando($dados['comando']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        if ($comando === null) {
            return response()->json(['message' => 'Este command não está na tela "Dev > Commands" do matrícula.'], 403);
        }

        $argumentos = $matricula->montarArgumentos($comando, $dados['argumentos'] ?? [], $dados['opcoes'] ?? []);

        Execucao::sincronizarOrfas();

        // Evita disparar a mesma rotina duas vezes por engano (duplo clique, outra aba).
        if (Execucao::existeAtivaDoComando($comando['nome'])) {
            return response()->json(['message' => 'Este command já está em execução. Espere terminar ou pare a execução atual.'], 409);
        }

        $execucao = Execucao::criar(
            $comando['nome'],
            $argumentos,
            (string) $request->session()->get('usuario.email')
        );

        // Antes de disparar: guarda os registros que o command vai reprocessar (tela Gráficos).
        $reprocessamentos->registrar($execucao);
        $execucao->disparar();

        return response()->json(['execucao' => $execucao->resumo()], 201);
    }

    public function saida(Request $request, string $id): JsonResponse
    {
        $execucao = $this->buscar($id);

        if ($execucao->ativa()) {
            Execucao::sincronizarOrfas();
            $execucao->recarregar();
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $arquivo = $execucao->caminhoLog();
        $trecho = '';

        clearstatcache(true, $arquivo);
        $tamanho = is_file($arquivo) ? filesize($arquivo) : 0;

        if ($offset < $tamanho) {
            $ponteiro = fopen($arquivo, 'rb');
            fseek($ponteiro, $offset);
            $bruto = (string) fread($ponteiro, min(self::TRECHO, $tamanho - $offset));
            fclose($ponteiro);

            // O corte pode cair no meio de um caractere UTF-8; devolve os bytes finais para a próxima leitura.
            $trecho = $bruto;
            for ($i = 0; $i < 3 && $trecho !== '' && ! mb_check_encoding($trecho, 'UTF-8'); $i++) {
                $trecho = substr($trecho, 0, -1);
            }
            if (! mb_check_encoding($trecho, 'UTF-8')) {
                $trecho = $bruto;
            }

            $offset += strlen($trecho);
            $trecho = mb_scrub($trecho, 'UTF-8');
        }

        return response()->json([
            'saida' => $trecho,
            'offset' => $offset,
            'mais' => $offset < $tamanho,
            'execucao' => $execucao->resumo(),
        ]);
    }

    public function parar(string $id): JsonResponse
    {
        $execucao = $this->buscar($id);
        $pid = (int) $execucao->pid;

        if ($execucao->status !== 'executando' || $pid <= 0) {
            return response()->json(['message' => 'Esta execução não está rodando.'], 409);
        }

        // O PID pode ter sido reaproveitado por outro processo; só sinaliza se ainda for um artisan.
        $linha = (string) @file_get_contents("/proc/{$pid}/cmdline");
        if (strpos($linha, 'artisan') === false) {
            return response()->json(['message' => 'O processo desta execução não existe mais.'], 409);
        }

        $execucao->atualizar(['interrupcao_solicitada_em' => Execucao::agora()]);

        function_exists('posix_kill') ? posix_kill($pid, 15) : exec('kill -TERM '.$pid);

        return response()->json(['execucao' => $execucao->resumo()]);
    }

    private function buscar(string $id): Execucao
    {
        $execucao = Execucao::buscar($id);

        abort_if($execucao === null, 404, 'Execução não encontrada.');

        return $execucao;
    }
}
