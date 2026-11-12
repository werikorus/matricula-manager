<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Login com Microsoft Entra ID pelo fluxo "authorization code" com PKCE.
 *
 * Decisão: implementado direto com o cliente HTTP do Laravel em vez de
 * Socialite + provider da comunidade. São duas chamadas HTTP, e assim o
 * painel não ganha dependências extras para manter.
 *
 * Os dados do usuário vêm do Microsoft Graph (/me) usando o access token
 * recebido direto do endpoint de token por TLS, então não é preciso validar
 * a assinatura de um id_token no lado do painel.
 */
class EntraId
{
    public function configurado(): bool
    {
        $c = config('painel.entra');

        return filled($c['tenant']) && filled($c['client_id']) && filled($c['client_secret']);
    }

    public function urlDeAutorizacao(string $state, string $verifier): string
    {
        $desafio = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return $this->base().'/authorize?'.http_build_query([
            'client_id' => config('painel.entra.client_id'),
            'response_type' => 'code',
            'response_mode' => 'query',
            'redirect_uri' => config('painel.entra.redirect'),
            'scope' => 'openid profile email User.Read',
            'state' => $state,
            'code_challenge' => $desafio,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
    }

    /**
     * @return array{id: string, nome: string, email: string, emails: string[]}
     */
    public function usuarioPeloCodigo(string $code, string $verifier): array
    {
        $token = Http::asForm()->timeout(15)->post($this->base().'/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('painel.entra.client_id'),
            'client_secret' => config('painel.entra.client_secret'),
            'redirect_uri' => config('painel.entra.redirect'),
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        $dadosToken = (array) $token->json();

        if ($token->failed() || empty($dadosToken['access_token'])) {
            throw new RuntimeException(
                'A Microsoft recusou a troca do código de login: '
                .($dadosToken['error_description'] ?? 'HTTP '.$token->status())
            );
        }

        $resposta = Http::withToken($dadosToken['access_token'])
            ->timeout(15)
            ->get('https://graph.microsoft.com/v1.0/me', [
                '$select' => 'id,displayName,mail,userPrincipalName',
            ]);

        $perfil = (array) $resposta->json();

        if ($resposta->failed() || empty($perfil['id'])) {
            throw new RuntimeException('Não foi possível ler seu perfil no Microsoft Graph (HTTP '.$resposta->status().').');
        }

        $emails = array_values(array_unique(array_filter([
            strtolower((string) ($perfil['mail'] ?? '')),
            strtolower((string) ($perfil['userPrincipalName'] ?? '')),
        ])));

        return [
            'id' => (string) $perfil['id'],
            'nome' => (string) (($perfil['displayName'] ?? '') ?: ($emails[0] ?? 'Usuário')),
            'email' => $emails[0] ?? '',
            'emails' => $emails,
        ];
    }

    private function base(): string
    {
        return 'https://login.microsoftonline.com/'.rawurlencode((string) config('painel.entra.tenant')).'/oauth2/v2.0';
    }
}
