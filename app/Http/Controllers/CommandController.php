<?php

namespace App\Http\Controllers;

use App\Services\MatriculaArtisan;
use Illuminate\View\View;
use RuntimeException;

class CommandController extends Controller
{
    public function index(MatriculaArtisan $matricula): View
    {
        $erro = null;
        $comandos = [];

        try {
            $comandos = $matricula->catalogo();
        } catch (RuntimeException $e) {
            $erro = $e->getMessage();
        }

        $logs = array_map(function (array $log) {
            return $log['titulo'];
        }, (array) config('painel.logs', []));

        return view('commands.index', [
            'comandos' => $comandos,
            'erro' => $erro,
            'ambiente' => config('painel.matricula.ambiente'),
            'caminho' => $matricula->caminho(),
            'logs' => $logs,
            'painel' => [
                'comandos' => $comandos,
                'csrf' => csrf_token(),
                'urls' => [
                    'execucoes' => route('execucoes.index', [], false),
                    'logs' => route('logs', [], false),
                    'login' => route('login', [], false),
                ],
            ],
        ]);
    }
}
