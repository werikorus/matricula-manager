<?php

namespace App\Http\Controllers;

use App\Models\Execucao;
use App\Services\MatriculaArtisan;
use Illuminate\View\View;
use Throwable;

/** Histórico das execuções de commands feitas pelo painel. */
class HistoricoController extends Controller
{
    /** Quantas execuções a tela carrega (as mais recentes). */
    public const LIMITE = 500;

    public const ROTULOS = ['pendente' => 'Na fila', 'executando' => 'Executando', 'sucesso' => 'Concluído', 'falha' => 'Falhou', 'interrompido' => 'Interrompido'];

    public function index(MatriculaArtisan $matricula): View
    {
        Execucao::sincronizarOrfas();

        try {
            $catalogo = [];
            foreach ($matricula->catalogo() as $comando) {
                $catalogo[$comando['nome']] = $comando;
            }
        } catch (Throwable $e) {
            $catalogo = [];
        }

        $execucoes = array_map(function (Execucao $execucao) use ($catalogo) {
            $resumo = $execucao->resumo();
            $definicao = $catalogo[$execucao->comando] ?? null;
            $resumo['titulo'] = $definicao['titulo'] ?? $execucao->comando;
            $resumo['repetir'] = $definicao ? route('commands', $this->parametrosParaRepetir($definicao, $execucao->argumentos ?: [])) : null;

            return $resumo;
        }, Execucao::recentes(self::LIMITE));

        $porId = [];
        foreach ($execucoes as $execucao) {
            $porId[(string) $execucao['id']] = $execucao;
        }

        return view('historico.index', [
            'dadosJs' => [
                'painel' => [
                    'csrf' => csrf_token(),
                    'urls' => [
                        'execucoes' => route('execucoes.index', [], false),
                        'login' => route('login', [], false),
                    ],
                ],
                'execucoes' => (object) $porId,
                'rotulos' => self::ROTULOS,
                'commands' => route('commands', [], false),
            ],
            'rotulos' => self::ROTULOS,
            'execucoes' => $execucoes,
            'porStatus' => array_count_values(array_column($execucoes, 'status')),
            'limite' => self::LIMITE,
        ]);
    }

    /**
     * Converte os argumentos gravados ("cmd --opcao=valor --flag -- pos1 pos2")
     * nos parâmetros que a tela de Commands entende para preencher o formulário
     * (comando, opt.<nome>, arg.<nome>).
     */
    private function parametrosParaRepetir(array $definicao, array $argumentos): array
    {
        $parametros = ['comando' => $definicao['nome']];
        $posicionais = [];
        $depoisDoSeparador = false;

        foreach (array_slice($argumentos, 1) as $parte) {
            if (! $depoisDoSeparador && $parte === '--') {
                $depoisDoSeparador = true;
                continue;
            }
            if (! $depoisDoSeparador && strpos($parte, '--') === 0) {
                [$nome, $valor] = array_pad(explode('=', substr($parte, 2), 2), 2, '1');
                $parametros['opt.'.$nome] = $valor;
                continue;
            }
            $posicionais[] = $parte;
        }

        foreach ($definicao['argumentos'] as $i => $argumento) {
            if (isset($posicionais[$i])) {
                $parametros['arg.'.$argumento['nome']] = $posicionais[$i];
            }
        }

        return $parametros;
    }
}
