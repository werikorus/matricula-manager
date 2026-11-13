<?php

namespace App\Http\Controllers;

use App\Models\Execucao;
use App\Services\BancoDoMatricula;
use App\Support\AnoLetivo;
use App\Services\MatriculaArtisan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class PabController extends Controller
{
    /** Command do matrícula que reprocessa um PAB pelo código da admissão. */
    public const COMANDO = 'ProcessaAdmissaoRMCommand';

    public function index(BancoDoMatricula $banco): View
    {
        $ano = AnoLetivo::escolhido();
        $erro = null;
        $pabs = [];

        try {
            $pabs = $banco->pabsParados($ano);
        } catch (RuntimeException $e) {
            $erro = $e->getMessage();
        }

        Execucao::sincronizarOrfas();
        $emAndamento = Execucao::ativaDoComando(self::COMANDO);

        return view('pabs.index', [
            'pabs' => $pabs,
            'selecionados' => self::selecionadosPeloCommand($pabs),
            'anosDoCommand' => self::anosDoCommand(),
            'emAndamento' => $emAndamento,
            'erro' => $erro,
            'ano' => $ano,
            'ambiente' => config('painel.matricula.ambiente'),
            'consultadoEm' => now(),
        ]);
    }

    /**
     * Roda o command sem argumento: ele mesmo busca e processa os PABs
     * parados, com a regra dele (veja selecionadosPeloCommand).
     */
    public function reprocessarTodos(Request $request, MatriculaArtisan $matricula): RedirectResponse
    {
        try {
            $comando = $matricula->comando(self::COMANDO);
        } catch (RuntimeException $e) {
            return redirect()->route('pabs')->with('erro', $e->getMessage());
        }

        if ($comando === null) {
            return redirect()->route('pabs')->with('erro', 'O '.self::COMANDO.' não está na tela "Dev > Commands" do matrícula.');
        }

        Execucao::sincronizarOrfas();
        if (Execucao::existeAtivaDoComando(self::COMANDO)) {
            return redirect()->route('pabs')->with('erro', 'O '.self::COMANDO.' já está em execução. Espere terminar ou pare a execução atual.');
        }

        $execucao = Execucao::criar(self::COMANDO, [self::COMANDO], (string) $request->session()->get('usuario.email'));
        $execucao->disparar();

        // A tela de commands volta a acompanhar sozinha a execução em andamento.
        return redirect()->route('commands', ['comando' => self::COMANDO]);
    }

    /**
     * Quais PABs da lista o command pega quando roda sem argumento. Espelha o
     * WHERE dele: ano letivo atual ou seguinte E situação 3 sem boleto e sem
     * contrato, OU situação 11 com menos de 3 tentativas (de qualquer ano, pela
     * precedência do AND sobre o OR no SQL de lá).
     *
     * @return array<int, array>
     */
    private static function selecionadosPeloCommand(array $pabs): array
    {
        $anos = self::anosDoCommand();

        return array_values(array_filter($pabs, function (array $pab) use ($anos) {
            $situacao = (int) $pab['cd_situacao'];
            $tentativas = $pab['nu_quantidade_tentativas'];

            return ($situacao === 3 && in_array((int) $pab['nu_ano_letivo'], $anos, true))
                || ($situacao === 11 && ($tentativas === null || (int) $tentativas < 3));
        }));
    }

    /**
     * O command filtra pelo ano letivo atual e o seguinte.
     *
     * @return int[]
     */
    private static function anosDoCommand(): array
    {
        $ano = (int) date('Y');

        return [$ano, $ano + 1];
    }
}
