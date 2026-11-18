<?php

namespace App\Http\Controllers;

use App\Services\HistoricoDeParadas;
use App\Services\Reprocessamentos;
use Illuminate\View\View;
use RuntimeException;

/**
 * Resultado dos reprocessamentos feitos pelo painel e evolução das paradas.
 * Os filtros (período, tipo) são aplicados no navegador: o controller manda
 * as últimas 13 semanas já classificadas, sem nome nem código de aluno.
 */
class GraficoController extends Controller
{
    public function index(Reprocessamentos $reprocessamentos, HistoricoDeParadas $paradas): View
    {
        $erro = null;
        $itens = [];

        try {
            $itens = $reprocessamentos->comResultado();
            $paradas->registrar();
        } catch (RuntimeException $e) {
            $erro = $e->getMessage();
        }

        return view('graficos.index', [
            'erro' => $erro,
            'ambiente' => config('painel.matricula.ambiente'),
            'consultadoEm' => now(),
            'dados' => [
                'hoje' => date('Y-m-d'),
                'dias' => Reprocessamentos::DIAS,
                'itens' => $itens,
                'paradas' => $paradas->ultimos(Reprocessamentos::DIAS),
            ],
        ]);
    }
}
