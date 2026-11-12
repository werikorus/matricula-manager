<?php

namespace App\Http\Controllers;

use App\Models\Execucao;
use App\Services\BancoDoMatricula;
use App\Services\MatriculaArtisan;
use App\Support\Erros;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ReservaController extends Controller
{
    /** Integra a reserva com o RM e gera o boleto. Sem código, pega no máximo 2 (TOP 2 no SQL dele). */
    public const COMANDO_INTEGRAR = 'ProcessaReservaRMCommand';

    /** Consulta o RM e passa para "Reserva de vaga" as de termo assinado já pagas. */
    public const COMANDO_PAGAMENTO = 'alteraStatusReservaPaga';

    /** Quanto o ProcessaReservaRMCommand pega por execução, quando roda sem código. */
    public const LIMITE_POR_EXECUCAO = 2;

    public const ETAPAS = [
        'erro' => ['titulo' => 'Com erro', 'classe' => 'selo-falha'],
        'processando' => ['titulo' => 'Travada em processamento', 'classe' => 'selo-falha'],
        'sem_boleto' => ['titulo' => 'Sem boleto', 'classe' => 'selo-interrompido'],
        'aguarda_pagamento' => ['titulo' => 'Aguardando pagamento', 'classe' => 'selo-pendente'],
    ];

    public function index(BancoDoMatricula $banco): View
    {
        $erro = null;
        $reservas = [];
        $contagem = ['integrar' => 0, 'pagamento' => 0];

        try {
            $reservas = array_map(function (array $r) {
                $r['erro_resumo'] = Erros::resumir((string) $r['ds_erro']);

                return $r;
            }, $banco->reservasParadas());
            $contagem = $banco->contagemDosCommandsDeReserva((int) date('Y'));
        } catch (RuntimeException $e) {
            $erro = $e->getMessage();
        }

        Execucao::sincronizarOrfas();

        return view('reservas.index', [
            'reservas' => $reservas,
            'contagem' => $contagem,
            'anosDoCommand' => self::anosDoCommand(),
            'anoMinimo' => (int) config('painel.reservas.ano_minimo'),
            'emAndamento' => Execucao::ativaDoComando(self::COMANDO_INTEGRAR) ?: Execucao::ativaDoComando(self::COMANDO_PAGAMENTO),
            'erro' => $erro,
            'ambiente' => config('painel.matricula.ambiente'),
            'consultadoEm' => now(),
        ]);
    }

    /**
     * Roda um dos dois commands de reserva, sem argumento: cada um busca as
     * reservas pela própria regra.
     */
    public function processar(Request $request, MatriculaArtisan $matricula): RedirectResponse
    {
        $dados = $request->validate([
            'acao' => ['required', 'in:integrar,pagamento'],
        ], [
            'acao.required' => 'Escolha o que fazer.',
            'acao.in' => 'Ação inválida.',
        ]);

        $nome = $dados['acao'] === 'integrar' ? self::COMANDO_INTEGRAR : self::COMANDO_PAGAMENTO;

        try {
            $comando = $matricula->comando($nome);
        } catch (RuntimeException $e) {
            return redirect()->route('reservas')->with('erro', $e->getMessage());
        }

        if ($comando === null) {
            return redirect()->route('reservas')->with('erro', "O {$nome} não está na tela \"Dev > Commands\" do matrícula.");
        }

        Execucao::sincronizarOrfas();
        foreach ([self::COMANDO_INTEGRAR, self::COMANDO_PAGAMENTO] as $outro) {
            if (Execucao::existeAtivaDoComando($outro)) {
                return redirect()->route('reservas')->with('erro', "O {$outro} já está em execução. Espere terminar ou pare a execução atual.");
            }
        }

        $execucao = Execucao::criar($nome, [$nome], (string) $request->session()->get('usuario.email'));
        $execucao->disparar();

        return redirect()->route('commands', ['comando' => $nome]);
    }

    /**
     * O ProcessaReservaRMCommand só encontra reservas do ano letivo atual e
     * do seguinte, com ou sem código.
     *
     * @return int[]
     */
    private static function anosDoCommand(): array
    {
        $ano = (int) date('Y');

        return [$ano, $ano + 1];
    }
}
