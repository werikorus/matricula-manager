<?php

namespace App\Http\Controllers;

use App\Models\Execucao;
use App\Services\Acessos;
use App\Services\BancoDoMatricula;
use App\Services\EditorDeSolicitacao;
use App\Support\Erros;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Consulta de aluno: lista as solicitações dele, com atalho para a edição. */
class AlunoController extends Controller
{
    public function index(Request $request, BancoDoMatricula $banco, EditorDeSolicitacao $editor, Acessos $acessos): View
    {
        $termo = trim((string) $request->query('q', ''));
        $resultado = null;
        $erro = null;
        $situacoes = [];

        if ($termo !== '') {
            try {
                $resultado = $banco->buscarSolicitacoesDoAluno(mb_substr($termo, 0, 100));
                $resultado['linhas'] = array_map(function (array $l) {
                    $l['erro_resumo'] = Erros::resumir((string) $l['ds_erro']);

                    return $l;
                }, $resultado['linhas']);
                $situacoes = $editor->situacoes();
                $acessos->registrarBusca($termo, count($resultado['linhas']));
            } catch (RuntimeException $e) {
                $erro = $e->getMessage();
            }
        }

        if ($resultado !== null) {
            Execucao::sincronizarOrfas();
        }

        return view('alunos.index', [
            'termo' => $termo,
            'resultado' => $resultado,
            'situacoes' => $situacoes,
            'erro' => $erro,
            'ambiente' => config('painel.matricula.ambiente'),
            'emAndamento' => $resultado === null ? null
                : (Execucao::ativaDoComando(MatriculaController::COMANDO_LOTE) ?: Execucao::ativaDoComando(MatriculaController::COMANDO)),
        ]);
    }
}
