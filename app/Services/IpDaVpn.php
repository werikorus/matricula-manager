<?php

namespace App\Services;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * IP desta máquina na VPN, gravado em nu_ip_alteracao quando o painel altera
 * um registro.
 *
 * Decisão: o painel roda na WSL, então o IP da requisição é sempre 127.0.0.1.
 * O "IP da VPN" é o endereço de origem que o WINDOWS usa para chegar no banco
 * do matrícula (Find-NetRoute), que é o IP da interface da VPN. Leva ~4 s, por
 * isso fica em cache por 10 minutos. PAINEL_IP_VPN no .env fixa o valor.
 */
class IpDaVpn
{
    private const CACHE = 'painel.ip_vpn';

    private const POWERSHELL = '/mnt/c/Windows/System32/WindowsPowerShell/v1.0/powershell.exe';

    /** Valor já conhecido (cache ou .env), sem consultar o Windows. */
    public function conhecido(): ?string
    {
        $fixo = trim((string) config('painel.ip_vpn'));
        if ($fixo !== '') {
            return $fixo;
        }

        $guardado = Cache::get(self::CACHE);

        return is_array($guardado) ? $guardado['ip'] : null;
    }

    /** @return array{ip: string, interface: string|null} */
    public function obter(): array
    {
        $fixo = trim((string) config('painel.ip_vpn'));
        if ($fixo !== '') {
            return ['ip' => $fixo, 'interface' => 'PAINEL_IP_VPN (.env)'];
        }

        return Cache::remember(self::CACHE, now()->addMinutes(10), function () {
            return $this->detectar();
        });
    }

    /** @return array{ip: string, interface: string|null} */
    private function detectar(): array
    {
        $destino = $this->ipDoBanco();

        if (! is_file(self::POWERSHELL)) {
            throw new RuntimeException('Não encontrei o PowerShell do Windows para descobrir o IP da VPN. Defina PAINEL_IP_VPN no .env do painel.');
        }

        $script = '$r = Find-NetRoute -RemoteIPAddress '.$destino.' -ErrorAction Stop | Where-Object { $_.IPAddress } | Select-Object -First 1; '
            .'[Console]::OutputEncoding = [Text.Encoding]::UTF8; $r.IPAddress; $r.InterfaceAlias';

        try {
            $processo = new Process([self::POWERSHELL, '-NoProfile', '-NonInteractive', '-Command', $script], '/mnt/c');
            $processo->setTimeout(30)->run();
        } catch (Throwable $e) {
            throw new RuntimeException('Não consegui consultar o Windows para descobrir o IP da VPN: '.$e->getMessage());
        }

        $linhas = array_values(array_filter(array_map('trim', preg_split('/\R/', $processo->getOutput()) ?: [])));
        $ip = $linhas[0] ?? '';

        if (! $processo->isSuccessful() || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException(
                "Não consegui descobrir o IP da VPN (rota do Windows até o banco, {$destino}). "
                .'A VPN está conectada? Se preferir, defina PAINEL_IP_VPN no .env do painel.'
            );
        }

        return ['ip' => $ip, 'interface' => $linhas[1] ?? null];
    }

    /** IP do servidor de banco do matrícula (DB_HOST do .env de lá). */
    private function ipDoBanco(): string
    {
        $arquivo = rtrim((string) config('painel.matricula.caminho'), '/').'/.env';

        try {
            $host = (string) (Dotenv::parse((string) file_get_contents($arquivo))['DB_HOST'] ?? '');
        } catch (Throwable $e) {
            $host = '';
        }

        $host = preg_replace('/[\\\\,].*$/', '', $host); // "servidor\instancia" ou "servidor,porta"
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("Não consegui resolver o endereço do banco do matrícula ({$host}) para descobrir o IP da VPN.");
        }

        return $ip;
    }
}
