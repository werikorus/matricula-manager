<?php

namespace App\Http\Controllers;

use App\Services\CatalogoDoMatricula;
use App\Services\Configuracoes;
use Dotenv\Dotenv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ConfiguracoesController extends Controller
{
    public function index(Configuracoes $configuracoes): View
    {
        $ambientes = [];
        foreach ($configuracoes->ambientes() as $chave => $ambiente) {
            $ambientes[$chave] = $this->descrever($chave, $ambiente);
        }

        $salvo = $configuracoes->ler();

        return view('configuracoes.index', [
            'ambientes' => $ambientes,
            'atual' => $configuracoes->ambiente(),
            'alteradoEm' => $salvo['ambiente_alterado_em'] ?? null,
            'alteradoPor' => $salvo['ambiente_alterado_por'] ?? null,
        ]);
    }

    public function salvarAmbiente(Request $request, Configuracoes $configuracoes): RedirectResponse
    {
        $chave = (string) $request->input('ambiente');

        try {
            $anterior = $configuracoes->ambiente();
            $configuracoes->definirAmbiente($chave);
        } catch (RuntimeException $e) {
            return redirect()->route('configuracoes')->with('erro', $e->getMessage());
        }

        $titulo = $configuracoes->ambientes()[$chave]['titulo'] ?? $chave;

        return redirect()->route('configuracoes')->with('ok', $anterior === $chave
            ? "O painel já estava em {$titulo}."
            : "Painel agora em {$titulo} ({$chave}). Commands, logs, banco e edição passam a usar este ambiente.");
    }

    public function salvarCaminhos(Request $request, Configuracoes $configuracoes): RedirectResponse
    {
        $caminhos = (array) $request->input('caminhos', []);
        $erros = $configuracoes->definirCaminhos(array_map(function ($c) {
            return is_string($c) ? $c : '';
        }, $caminhos));

        if ($erros !== []) {
            $mensagens = [];
            foreach ($erros as $chave => $erro) {
                $mensagens['caminhos.'.$chave] = $erro;
            }

            return redirect()->to(route('configuracoes').'#repositorios')->withErrors($mensagens)->withInput();
        }

        $resumo = [];
        foreach ($configuracoes->ambientes() as $chave => $ambiente) {
            $resumo[] = "{$chave}: {$ambiente['caminho']}".($ambiente['origem'] === 'padrao' ? ' (padrão)' : '');
        }

        return redirect()->to(route('configuracoes').'#repositorios')
            ->with('caminhos_ok', 'Caminhos salvos — '.implode(' · ', $resumo).'.');
    }

    /** O que o painel vai usar neste ambiente, e se está tudo no lugar. */
    private function descrever(string $chave, array $ambiente): array
    {
        $caminho = rtrim((string) $ambiente['caminho'], '/');
        $env = [];
        try {
            $env = is_file($caminho.'/.env') ? Dotenv::parse((string) file_get_contents($caminho.'/.env')) : [];
        } catch (Throwable $e) {
            $env = [];
        }

        $verificacoes = [
            'Pasta do matrícula' => is_dir($caminho),
            'artisan' => is_file($caminho.'/artisan'),
            '.env (banco)' => is_file($caminho.'/.env'),
            'Tela Dev > Commands' => is_file($caminho.'/'.CatalogoDoMatricula::ARQUIVO),
        ];
        $rede = $this->bancoAlcancavel((string) ($env['DB_HOST'] ?? ''), (string) ($env['DB_PORT'] ?? ''));

        return [
            'chave' => $chave,
            'titulo' => $ambiente['titulo'],
            'caminho' => $caminho,
            'caminho_padrao' => rtrim((string) ($ambiente['caminho_padrao'] ?? $caminho), '/'),
            'origem' => $ambiente['origem'] ?? 'padrao',
            'caminho_windows' => '\\\\wsl.localhost\\'.(getenv('WSL_DISTRO_NAME') ?: 'Ubuntu').str_replace('/', '\\', $caminho),
            'banco' => trim(($env['DB_HOST'] ?? '?').' / '.($env['DB_DATABASE'] ?? '?')),
            'app_env' => $env['APP_ENV'] ?? null,
            'git' => is_dir($caminho.'/.git') ? $this->git($caminho) : null,
            'verificacoes' => $verificacoes,
            'pronto' => ! in_array(false, $verificacoes, true),
            'rede' => $rede,
        ];
    }

    /**
     * Abre uma conexão TCP com o banco (sem logar) para dizer se a rede/VPN
     * alcança o servidor. Não impede a troca: commands e logs funcionam sem banco.
     *
     * @return array{ok: bool, texto: string}
     */
    private function bancoAlcancavel(string $host, string $porta): array
    {
        // "servidor\instancia" ou "servidor,porta"
        if (preg_match('/^([^,\\\\]+)(?:,(\d+))?/', $host, $m)) {
            $host = $m[1];
            $porta = $m[2] ?? $porta;
        }
        if ($host === '') {
            return ['ok' => false, 'texto' => 'sem DB_HOST no .env'];
        }

        $porta = (int) ($porta ?: 1433);
        $inicio = microtime(true);
        $conexao = @fsockopen($host, $porta, $codigo, $mensagem, 2.0);
        if ($conexao) {
            fclose($conexao);

            return ['ok' => true, 'texto' => sprintf('banco acessível (%d ms)', (microtime(true) - $inicio) * 1000)];
        }

        return ['ok' => false, 'texto' => "banco sem resposta em {$host}:{$porta} — confira a VPN deste ambiente"];
    }

    private function git(string $caminho): ?string
    {
        try {
            $branch = new Process(['git', '-C', $caminho, 'branch', '--show-current']);
            $branch->setTimeout(5)->run();
            $ultimo = new Process(['git', '-C', $caminho, 'log', '-1', '--format=%h · %cd', '--date=format:%d/%m/%Y %H:%M']);
            $ultimo->setTimeout(5)->run();

            return trim(trim($branch->getOutput()).'  ('.trim($ultimo->getOutput()).')');
        } catch (Throwable $e) {
            return null;
        }
    }
}
