<?php

namespace App\Services;

use App\Models\Execucao;
use RuntimeException;

/**
 * Configurações do painel escolhidas pela tela (não pelo .env), guardadas em
 * storage/app/configuracoes.json.
 *
 * Hoje: o ambiente do matrícula (Produção ou Homologação). O escolhido é
 * aplicado em config('painel.matricula') no boot de cada requisição, então
 * tudo que lê essa config (commands, logs, banco, edição) segue a escolha.
 */
class Configuracoes
{
    public function arquivo(): string
    {
        return storage_path('app/configuracoes.json');
    }

    /**
     * Ambientes com o caminho em vigor: o salvo na tela tem prioridade sobre
     * o .env (MATRICULA_PATH_PRD/HML) e o padrão do código.
     *
     * @return array<string, array{titulo: string, caminho: string, php: string, caminho_padrao: string, origem: string}>
     */
    public function ambientes(): array
    {
        $salvos = (array) ($this->ler()['caminhos'] ?? []);
        $ambientes = [];

        foreach ((array) config('painel.ambientes', []) as $chave => $ambiente) {
            $ambiente['caminho_padrao'] = (string) $ambiente['caminho'];
            $ambiente['origem'] = 'padrao';
            if (is_string($salvos[$chave] ?? null) && $salvos[$chave] !== '') {
                $ambiente['caminho'] = $salvos[$chave];
                $ambiente['origem'] = 'configuracoes';
            }
            $ambientes[$chave] = $ambiente;
        }

        return $ambientes;
    }

    /**
     * Salva os caminhos dos repositórios. Vazio = volta ao padrão.
     * Aceita caminho Linux ou do Windows (\\wsl.localhost\Ubuntu\home\...).
     *
     * @param  array<string, string|null>  $caminhos  chave do ambiente => caminho
     * @return array<string, string> erros por ambiente (vazio = salvo)
     */
    public function definirCaminhos(array $caminhos): array
    {
        $ambientes = (array) config('painel.ambientes', []);
        $erros = [];
        $novos = [];

        foreach ($caminhos as $chave => $bruto) {
            if (! isset($ambientes[$chave])) {
                continue;
            }
            $bruto = trim((string) $bruto);
            if ($bruto === '') {
                $novos[$chave] = null;
                continue;
            }

            $caminho = self::normalizarCaminho($bruto);
            if ($caminho === null) {
                $erros[$chave] = 'Informe um caminho Linux (/home/...) ou da WSL no Windows (\\\\wsl.localhost\\Ubuntu\\home\\...).';
            } elseif (! is_dir($caminho)) {
                $erros[$chave] = "A pasta {$caminho} não existe.";
            } elseif (! is_file($caminho.'/artisan')) {
                $erros[$chave] = "{$caminho} não parece o matrícula: não tem o arquivo artisan.";
            } else {
                // Igual ao padrão: não guarda (continua "padrão" se o .env mudar depois).
                $padrao = rtrim((string) $ambientes[$chave]['caminho'], '/');
                $novos[$chave] = $caminho === $padrao ? null : $caminho;
            }
        }

        if ($erros !== []) {
            return $erros;
        }

        $dados = $this->ler();
        $salvos = (array) ($dados['caminhos'] ?? []);
        foreach ($novos as $chave => $caminho) {
            if ($caminho === null) {
                unset($salvos[$chave]);
            } else {
                $salvos[$chave] = $caminho;
            }
        }
        $dados['caminhos'] = $salvos;
        $dados['caminhos_alterados_em'] = date(DATE_ATOM);
        $dados['caminhos_alterados_por'] = (string) (session('usuario.email') ?: 'acesso-livre');
        $this->gravar($dados);

        $this->aplicar();

        return [];
    }

    /**
     * "\\wsl.localhost\Ubuntu\home\x" ou "\\wsl$\Ubuntu\home\x" → "/home/x";
     * "C:\pasta" → "/mnt/c/pasta"; caminho Linux fica como está (sem "/" no fim).
     */
    public static function normalizarCaminho(string $caminho): ?string
    {
        $caminho = trim($caminho, " \t\n\r\0\x0B\"'");

        if (preg_match('#^[\\\\/]{2}wsl(?:\.localhost|\$)[\\\\/][^\\\\/]+(.*)$#i', $caminho, $m)) {
            $caminho = str_replace('\\', '/', $m[1]);
        } elseif (preg_match('#^([a-z]):[\\\\/](.*)$#i', $caminho, $m)) {
            $caminho = '/mnt/'.strtolower($m[1]).'/'.str_replace('\\', '/', $m[2]);
        }

        if ($caminho === '' || $caminho[0] !== '/' || strpos($caminho, '..') !== false) {
            return null;
        }

        return rtrim(preg_replace('#/+#', '/', $caminho), '/') ?: '/';
    }

    /** Chave do ambiente escolhido (PRD, HML…). */
    public function ambiente(): string
    {
        $salvo = $this->ler()['ambiente'] ?? null;
        $ambientes = $this->ambientes();

        if (is_string($salvo) && isset($ambientes[$salvo])) {
            return $salvo;
        }

        $padrao = (string) env('MATRICULA_AMBIENTE', 'PRD');

        return isset($ambientes[$padrao]) ? $padrao : (string) array_key_first($ambientes);
    }

    /** Coloca o ambiente escolhido em config('painel.matricula'). */
    public function aplicar(?string $chave = null): void
    {
        $chave = $chave ?? $this->ambiente();
        $ambiente = $this->ambientes()[$chave] ?? null;

        if ($ambiente === null) {
            return;
        }

        config([
            'painel.matricula.ambiente' => $chave,
            'painel.matricula.titulo' => $ambiente['titulo'],
            'painel.matricula.caminho' => $ambiente['caminho'],
            'painel.matricula.php' => $ambiente['php'] ?? 'php',
        ]);
    }

    public function definirAmbiente(string $chave): void
    {
        if (! isset($this->ambientes()[$chave])) {
            throw new RuntimeException("Ambiente desconhecido: {$chave}.");
        }

        // Trocar no meio de uma rotina misturaria console, logs e banco de ambientes diferentes.
        Execucao::sincronizarOrfas();
        foreach (Execucao::recentes(50) as $execucao) {
            if ($execucao->ativa()) {
                throw new RuntimeException(
                    "Há uma rotina em execução ({$execucao->comando}, #{$execucao->id}). "
                    .'Espere terminar ou pare a execução antes de trocar de ambiente.'
                );
            }
        }

        $dados = $this->ler();
        $dados['ambiente'] = $chave;
        $dados['ambiente_alterado_em'] = date(DATE_ATOM);
        $dados['ambiente_alterado_por'] = (string) (session('usuario.email') ?: 'acesso-livre');

        $this->gravar($dados);

        $this->aplicar($chave);
    }

    private function gravar(array $dados): void
    {
        if (! is_dir(dirname($this->arquivo()))) {
            mkdir(dirname($this->arquivo()), 0775, true);
        }
        file_put_contents($this->arquivo(), json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function ler(): array
    {
        $arquivo = $this->arquivo();
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;

        return is_array($dados) ? $dados : [];
    }
}
