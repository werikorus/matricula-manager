<?php

namespace App\Http\Controllers;

use App\Models\Execucao;
use App\Services\BancoDoMatricula;
use App\Services\HistoricoDeParadas;
use App\Services\IpDaVpn;
use App\Services\MatriculaArtisan;
use App\Services\Reprocessamentos;
use App\Support\AnoLetivo;
use App\Support\Erros;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class MatriculaController extends Controller
{
    /** Reintegra uma solicitação pelo código. */
    public const COMANDO = 'integradorCommand';

    /** Zera as tentativas e reintegra todas as 14/16 de um ano letivo ou de um processo. */
    public const COMANDO_LOTE = 'integradorLoteCommand';

    public function index(BancoDoMatricula $banco, HistoricoDeParadas $paradas): View
    {
        $ano = AnoLetivo::escolhido();
        $erro = null;
        $matriculas = [];
        $porProcesso = [];

        try {
            $matriculas = array_map(function (array $m) {
                $m['erro_resumo'] = Erros::resumir((string) $m['ds_erro']);

                return $m;
            }, $banco->matriculasParadas($ano));
            $porProcesso = $banco->pendentesDeIntegracaoPorProcesso();
        } catch (RuntimeException $e) {
            $erro = $e->getMessage();
        }

        if ($erro === null) {
            $paradas->registrar(); // foto do dia para o gráfico "Paradas em aberto"
        }

        Execucao::sincronizarOrfas();

        return view('matriculas.index', [
            'matriculas' => $matriculas,
            'porProcesso' => $porProcesso,
            'noLoteDoAno' => array_sum(array_column(array_filter($porProcesso, function ($p) use ($ano) {
                return $p['ano'] === $ano;
            }), 'total')),
            'ano' => $ano,
            'desde' => AnoLetivo::inicioDasInclusoes($ano),
            'emAndamento' => Execucao::ativaDoComando(self::COMANDO_LOTE) ?: Execucao::ativaDoComando(self::COMANDO),
            'erro' => $erro,
            'ambiente' => config('painel.matricula.ambiente'),
            'consultadoEm' => now(),
        ]);
    }

    /**
     * Roda o integrador em lote com um dos dois filtros dele: o ano letivo
     * escolhido na tela (só esse ano) ou um processo específico.
     */
    public function reprocessarTodos(Request $request, MatriculaArtisan $matricula, Reprocessamentos $reprocessamentos): RedirectResponse
    {
        $dados = $request->validate([
            'filtro' => ['required', 'in:anoLetivo,processo'],
            'ano' => ['nullable', 'integer', 'in:'.implode(',', AnoLetivo::opcoes())],
            'processo' => ['required_if:filtro,processo', 'nullable', 'integer', 'min:1'],
        ], [
            'filtro.required' => 'Escolha como filtrar: por ano letivo ou por processo.',
            'filtro.in' => 'Filtro inválido: use ano letivo ou processo.',
            'processo.required_if' => 'Escolha o processo.',
            'processo.integer' => 'Código de processo inválido.',
            'processo.min' => 'Código de processo inválido.',
            'ano.in' => 'Ano letivo inválido.',
        ]);

        try {
            $comando = $matricula->comando(self::COMANDO_LOTE);
        } catch (RuntimeException $e) {
            return redirect()->route('matriculas')->with('erro', $e->getMessage());
        }

        if ($comando === null) {
            return redirect()->route('matriculas')->with('erro', 'O '.self::COMANDO_LOTE.' não está na tela "Dev > Commands" do matrícula.');
        }

        Execucao::sincronizarOrfas();
        foreach ([self::COMANDO_LOTE, self::COMANDO] as $nome) {
            if (Execucao::existeAtivaDoComando($nome)) {
                return redirect()->route('matriculas')->with('erro', "O {$nome} já está em execução. Espere terminar ou pare a execução atual.");
            }
        }

        $valor = $dados['filtro'] === 'processo' ? (string) $dados['processo'] : (string) AnoLetivo::escolhido($request);

        // Os nomes dos argumentos vêm da tela do matrícula: [tipoFiltro, valor].
        [$tipo, $alvo] = array_column($comando['argumentos'], 'nome') + [null, null];
        if ($tipo === null || $alvo === null) {
            return redirect()->route('matriculas')->with('erro', 'O '.self::COMANDO_LOTE.' não tem mais os argumentos "tipo de filtro" e "valor". Confira a tela "Dev > Commands" do matrícula.');
        }

        $argumentos = $matricula->montarArgumentos($comando, [$tipo => $dados['filtro'], $alvo => $valor], []);

        $execucao = Execucao::criar(self::COMANDO_LOTE, $argumentos, (string) $request->session()->get('usuario.email'));
        $reprocessamentos->registrar($execucao);
        $execucao->disparar();

        return redirect()->route('commands', ['comando' => self::COMANDO_LOTE]);
    }

    /**
     * Reprocessa uma solicitação: primeiro deixa o registro pronto no banco
     * (situação 14, tentativas 0, ds_erro nulo) e só depois dispara o
     * integradorCommand com o código dela.
     *
     * Tudo o que pode falhar sem mexer em nada (command, execução em
     * andamento, argumento) é conferido antes do UPDATE.
     */
    public function reprocessar(Request $request, string $codigo, BancoDoMatricula $banco, MatriculaArtisan $matricula, IpDaVpn $ip, Reprocessamentos $reprocessamentos): RedirectResponse
    {
        $codigo = (int) $codigo;

        try {
            $comando = $matricula->comando(self::COMANDO);
        } catch (RuntimeException $e) {
            return redirect($this->voltar($request))->with('erro', $e->getMessage());
        }

        if ($comando === null) {
            return redirect($this->voltar($request))->with('erro', 'O '.self::COMANDO.' não está na tela "Dev > Commands" do matrícula.');
        }

        Execucao::sincronizarOrfas();
        foreach ([self::COMANDO, self::COMANDO_LOTE] as $nome) {
            if (Execucao::existeAtivaDoComando($nome)) {
                return redirect($this->voltar($request))->with('erro', "O {$nome} já está em execução. Espere terminar ou pare a execução atual. Nada foi alterado.");
            }
        }

        $argumento = $comando['argumentos'][0]['nome'] ?? null;
        if ($argumento === null) {
            return redirect($this->voltar($request))->with('erro', 'O '.self::COMANDO.' não tem mais o argumento do código da solicitação. Confira a tela "Dev > Commands" do matrícula.');
        }
        $argumentos = $matricula->montarArgumentos($comando, [$argumento => (string) $codigo], []);

        try {
            $antes = $banco->prepararReintegracao($codigo, $ip->obter()['ip']);
        } catch (RuntimeException $e) {
            return redirect($this->voltar($request))->with('erro', $e->getMessage());
        }

        $execucao = Execucao::criar(self::COMANDO, $argumentos, (string) $request->session()->get('usuario.email'));
        $execucao->anexarAoLog(sprintf(
            "[painel] Solicitação %d preparada no banco antes de integrar: situação %d → 14, tentativas %s → 0%s.\n\n",
            $codigo,
            $antes['situacao'],
            $antes['tentativas'] === null ? 'vazio' : (string) $antes['tentativas'],
            $antes['tinha_erro'] ? ', ds_erro limpo' : ''
        ));
        // A situação de antes vem do UPDATE: o registro já está em 14 agora.
        $reprocessamentos->registrar($execucao, [$codigo => $antes['situacao']]);
        $execucao->disparar();

        return redirect()->route('commands', ['comando' => self::COMANDO]);
    }

    /**
     * Para onde voltar se o reprocessamento não começar: a tela de onde veio
     * (ex.: Consultar matrícula), desde que seja do próprio painel.
     */
    private function voltar(Request $request): string
    {
        $voltar = (string) $request->input('voltar', '');

        return preg_match('#^/(?!/)[a-z0-9/_-]*(\?[^\s]*)?$#i', $voltar) ? $voltar : route('matriculas', [], false);
    }
}
