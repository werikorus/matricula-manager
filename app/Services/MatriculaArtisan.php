<?php

namespace App\Services;

use Dotenv\Dotenv;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Ponte entre o painel e o artisan do matrícula.
 *
 * O matrícula é tratado como caixa-preta: nada do código dele é carregado
 * aqui. O catálogo vem da tela "Dev > Commands" do matrícula (lida como
 * texto, veja CatalogoDoMatricula) e a execução é um processo separado, com
 * os argumentos passados como array (sem shell).
 */
class MatriculaArtisan
{
    public function caminho(): string
    {
        return rtrim((string) config('painel.matricula.caminho'), '/');
    }

    /**
     * Monta o processo "php artisan <command> ..." dentro da pasta do matrícula.
     *
     * @param  string[]  $argumentos  nome do command seguido dos parâmetros
     */
    public function processo(array $argumentos, ?float $timeout = null): Process
    {
        $nome = array_shift($argumentos);

        $linha = array_merge(
            [(string) config('painel.matricula.php'), 'artisan', $nome, '--no-interaction', '--ansi'],
            $argumentos
        );

        return (new Process($linha, $this->caminho(), $this->ambienteLimpo()))->setTimeout($timeout);
    }

    /**
     * Commands da tela "Dev > Commands" do matrícula, no formato do painel.
     *
     * @return array<int, array>
     */
    public function catalogo(): array
    {
        return array_map(function (array $c) {
            $argumentos = [];
            $opcoes = [];

            foreach ((array) ($c['params'] ?? []) as $p) {
                $nome = ltrim((string) ($p['name'] ?? ''), '-');
                $rotulo = (string) ($p['label'] ?? $nome);

                // "options" => ['valor' => 'Rótulo', ...] vira uma lista de escolha.
                $escolhas = [];
                foreach ((array) ($p['options'] ?? []) as $valor => $rotuloDaEscolha) {
                    $escolhas[] = ['valor' => (string) $valor, 'rotulo' => (string) $rotuloDaEscolha];
                }

                if (($p['type'] ?? 'argument') === 'argument') {
                    $argumentos[] = [
                        'nome' => $nome,
                        'rotulo' => $rotulo,
                        'obrigatorio' => (bool) ($p['required'] ?? false),
                        'lista' => false,
                        'descricao' => '',
                        'padrao' => '',
                        'escolhas' => $escolhas,
                    ];
                } else {
                    $opcoes[] = [
                        'nome' => $nome,
                        'rotulo' => $rotulo,
                        'aceita_valor' => ($p['type'] ?? '') === 'option',
                        'multipla' => false,
                        'descricao' => '',
                        'padrao' => '',
                        'escolhas' => $escolhas,
                    ];
                }
            }

            return [
                'nome' => (string) $c['command'],
                'titulo' => (string) ($c['label'] ?? $c['command']),
                'descricao' => (string) ($c['description'] ?? ''),
                'argumentos' => $argumentos,
                'opcoes' => $opcoes,
            ];
        }, (new CatalogoDoMatricula($this->caminho()))->ler());
    }

    public function comando(string $nome): ?array
    {
        foreach ($this->catalogo() as $comando) {
            if ($comando['nome'] === $nome) {
                return $comando;
            }
        }

        return null;
    }

    /**
     * Valida o que veio do formulário contra a definição do command e devolve
     * a lista de argumentos pronta para o processo.
     *
     * Opções vão antes de "--" e argumentos depois, então um valor digitado
     * nunca é interpretado como opção do artisan (ex.: "--env=...").
     *
     * @return string[]
     */
    public function montarArgumentos(array $comando, array $argumentos, array $opcoes): array
    {
        $erros = [];
        $linha = [$comando['nome']];

        foreach ($comando['opcoes'] as $opcao) {
            $bruto = $opcoes[$opcao['nome']] ?? null;

            if (! $opcao['aceita_valor']) {
                if (filter_var($bruto, FILTER_VALIDATE_BOOLEAN)) {
                    $linha[] = '--'.$opcao['nome'];
                }

                continue;
            }

            $valores = $this->valores($bruto, $opcao['multipla'], "opcoes.{$opcao['nome']}", $erros);
            $this->conferirEscolhas($opcao, $valores, "opcoes.{$opcao['nome']}", $erros);
            foreach ($valores as $valor) {
                $linha[] = "--{$opcao['nome']}={$valor}";
            }
        }

        $posicionais = [];
        $pulado = null;

        foreach ($comando['argumentos'] as $argumento) {
            $chave = "argumentos.{$argumento['nome']}";
            $valores = $this->valores($argumentos[$argumento['nome']] ?? null, $argumento['lista'], $chave, $erros);
            $this->conferirEscolhas($argumento, $valores, $chave, $erros);

            if ($valores === []) {
                if ($argumento['obrigatorio']) {
                    $erros[$chave] ??= "Informe \"{$argumento['nome']}\".";
                }
                $pulado ??= $argumento['nome'];

                continue;
            }

            if ($pulado !== null) {
                // Argumentos são posicionais: sem o anterior, este seria lido no lugar dele.
                $erros[$chave] = "Preencha \"{$pulado}\" antes de \"{$argumento['nome']}\".";

                continue;
            }

            array_push($posicionais, ...$valores);
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        return $posicionais === [] ? $linha : array_merge($linha, ['--'], $posicionais);
    }

    /**
     * Com lista de escolha, só aceita os valores declarados na tela do matrícula.
     */
    private function conferirEscolhas(array $parametro, array $valores, string $chave, array &$erros): void
    {
        $permitidos = array_column($parametro['escolhas'] ?? [], 'valor');

        if ($permitidos === []) {
            return;
        }

        foreach ($valores as $valor) {
            if (! in_array($valor, $permitidos, true)) {
                $erros[$chave] = 'Escolha uma das opções: '.implode(', ', $permitidos).'.';

                return;
            }
        }
    }

    /**
     * @return string[]
     */
    private function valores($bruto, bool $lista, string $chave, array &$erros): array
    {
        if ($bruto === null || $bruto === '') {
            return [];
        }

        if (! is_string($bruto) && ! is_int($bruto)) {
            $erros[$chave] = 'Valor inválido.';

            return [];
        }

        $texto = trim((string) $bruto);
        $valores = $lista
            ? (preg_split('/[\s,;]+/', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : ($texto === '' ? [] : [$texto]);

        foreach ($valores as $valor) {
            if (strlen($valor) > 500 || preg_match('/[\x00-\x1F\x7F]/', $valor)) {
                $erros[$chave] = 'Use até 500 caracteres, sem quebras de linha.';

                return [];
            }
        }

        return $valores;
    }

    /**
     * Remove do ambiente do processo filho tudo o que veio do .env do PAINEL.
     *
     * Sem isso o matrícula herdaria APP_KEY, DB_CONNECTION etc. do painel, e
     * como o Laravel não sobrescreve variáveis de ambiente já definidas, ele
     * rodaria apontando para a configuração errada.
     *
     * @return array<string, false|string>
     */
    private function ambienteLimpo(): array
    {
        $chaves = [
            'APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL',
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        ];

        try {
            $chaves = array_merge($chaves, array_keys(Dotenv::parse((string) file_get_contents(base_path('.env')))));
        } catch (Throwable $e) {
            // Sem .env legível, ficam só as chaves conhecidas acima.
        }

        // Xdebug desligado nos processos disparados pelo painel: sem isso cada
        // execução tenta conectar no depurador e suja a saída com o aviso.
        // COLUMNS: sem terminal o Symfony supõe 80 colunas e encolhe a barra de
        // progresso quando a linha (barra + nome do item) passa disso.
        return array_merge(array_fill_keys(array_unique($chaves), false), ['XDEBUG_MODE' => 'off', 'COLUMNS' => '160']);
    }
}
