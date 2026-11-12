<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Autenticado
{
    /** Usuário usado quando VALIDA_LOGIN_POR_MICROSOFT está desligado. */
    public const USUARIO_LIVRE = [
        'id' => null,
        'nome' => 'Acesso livre',
        'email' => 'acesso-livre',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('painel.valida_login_microsoft')) {
            if (! $request->session()->has('usuario')) {
                $request->session()->put('usuario', self::USUARIO_LIVRE);
            }

            return $next($request);
        }

        if (! $request->session()->has('usuario') || $request->session()->get('usuario.id') === null) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Sessão expirada. Entre novamente.'], 401)
                : redirect()->route('login');
        }

        return $next($request);
    }
}
