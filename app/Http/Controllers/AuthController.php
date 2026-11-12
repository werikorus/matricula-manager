<?php

namespace App\Http\Controllers;

use App\Services\EntraId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class AuthController extends Controller
{
    public function login(Request $request, EntraId $entra)
    {
        if (! config('painel.valida_login_microsoft')
            || $request->session()->get('usuario.id') !== null) {
            return redirect()->route('inicio');
        }

        return view('auth.login', ['configurado' => $entra->configurado()]);
    }

    public function redirecionar(Request $request, EntraId $entra): RedirectResponse
    {
        if (! config('painel.valida_login_microsoft')) {
            return redirect()->route('inicio');
        }

        if (! $entra->configurado()) {
            return $this->falha('Preencha ENTRA_TENANT_ID, ENTRA_CLIENT_ID e ENTRA_CLIENT_SECRET no .env do painel.');
        }

        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('entra', ['state' => $state, 'verifier' => $verifier]);

        return redirect()->away($entra->urlDeAutorizacao($state, $verifier));
    }

    public function callback(Request $request, EntraId $entra): RedirectResponse
    {
        if (! config('painel.valida_login_microsoft')) {
            return redirect()->route('inicio');
        }

        $pendente = $request->session()->pull('entra');

        if ($request->filled('error')) {
            return $this->falha('A Microsoft não concluiu o login: '.$request->query('error_description', $request->query('error')));
        }

        if (! is_array($pendente)
            || ! is_string($request->query('state'))
            || ! hash_equals($pendente['state'], $request->query('state'))
            || ! is_string($request->query('code'))) {
            return $this->falha('A resposta de login não corresponde a esta sessão. Tente entrar de novo.');
        }

        try {
            $usuario = $entra->usuarioPeloCodigo($request->query('code'), $pendente['verifier']);
        } catch (RuntimeException $e) {
            Log::warning('Falha no login Entra ID', ['erro' => $e->getMessage()]);

            return $this->falha($e->getMessage());
        }

        if (array_intersect($usuario['emails'], config('painel.emails_permitidos')) === []) {
            Log::warning('Login negado: conta fora da lista', ['emails' => $usuario['emails']]);

            return $this->falha('A conta '.$usuario['email'].' não está em PAINEL_EMAILS_PERMITIDOS.');
        }

        $request->session()->regenerate();
        $request->session()->put('usuario', [
            'id' => $usuario['id'],
            'nome' => $usuario['nome'],
            'email' => $usuario['email'],
        ]);

        return redirect()->route('inicio');
    }

    public function sair(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function falha(string $mensagem): RedirectResponse
    {
        return redirect()->route('login')->with('erro', $mensagem);
    }
}
