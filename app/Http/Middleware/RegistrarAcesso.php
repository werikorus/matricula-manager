<?php

namespace App\Http\Middleware;

use App\Services\Acessos;
use App\Support\Telas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Conta cada abertura (GET de página, com sucesso) das telas do menu. */
class RegistrarAcesso
{
    /** @var Acessos */
    private $acessos;

    public function __construct(Acessos $acessos)
    {
        $this->acessos = $acessos;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        $rota = optional($request->route())->getName();
        if ($request->isMethod('GET') && ! $request->expectsJson()
            && $resposta->getStatusCode() === 200
            && $rota !== null && isset(Telas::lista()[$rota])) {
            try {
                $this->acessos->registrarTela($rota);
            } catch (Throwable $e) {
                // Contagem de uso nunca pode derrubar uma tela.
            }
        }

        return $resposta;
    }
}
