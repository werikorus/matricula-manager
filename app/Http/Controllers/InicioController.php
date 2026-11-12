<?php

namespace App\Http\Controllers;

use App\Models\Execucao;
use App\Services\Acessos;
use App\Services\MatriculaArtisan;
use App\Support\Telas;
use Illuminate\View\View;
use Throwable;

/** Primeira tela: atalhos para o que é mais usado. */
class InicioController extends Controller
{
    public function index(Acessos $acessos, MatriculaArtisan $matricula): View
    {
        $uso = $acessos->dados();

        // Telas ordenadas pelo uso; as nunca abertas ficam no fim, na ordem do menu.
        $ordem = array_flip(array_keys(Telas::lista()));
        $telas = array_map(function (array $tela) use ($uso) {
            $tela['total'] = (int) ($uso['telas'][$tela['rota']]['total'] ?? 0);
            $tela['ultimo'] = $uso['telas'][$tela['rota']]['ultimo'] ?? null;

            return $tela;
        }, Telas::lista());
        uasort($telas, function ($a, $b) use ($ordem) {
            return [$b['total'], $ordem[$a['rota']]] <=> [$a['total'], $ordem[$b['rota']]];
        });

        Execucao::sincronizarOrfas();
        $historico = Execucao::recentes(300);

        // Commands mais executados pelo painel, com a última execução de cada.
        $porComando = [];
        foreach ($historico as $execucao) {
            $nome = (string) $execucao->comando;
            if (! isset($porComando[$nome])) {
                $porComando[$nome] = ['nome' => $nome, 'total' => 0, 'ultima' => $execucao->resumo()];
            }
            $porComando[$nome]['total']++;
        }
        usort($porComando, function ($a, $b) {
            return [$b['total'], $b['ultima']['criado_em']] <=> [$a['total'], $a['ultima']['criado_em']];
        });

        try {
            $titulos = array_column($matricula->catalogo(), 'titulo', 'nome');
        } catch (Throwable $e) {
            $titulos = [];
        }

        return view('inicio.index', [
            'telas' => array_values($telas),
            'semUso' => array_sum(array_column($telas, 'total')) === 0,
            'comandos' => array_slice($porComando, 0, 6),
            'titulos' => $titulos,
            'emAndamento' => array_values(array_filter($historico, function (Execucao $e) { return $e->ativa(); })),
            'solicitacoes' => $uso['solicitacoes'],
            'buscas' => $uso['buscas'],
            'usuario' => (string) session('usuario.nome'),
            'ambiente' => config('painel.matricula.ambiente'),
        ]);
    }
}
