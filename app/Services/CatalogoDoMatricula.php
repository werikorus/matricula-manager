<?php

namespace App\Services;

use RuntimeException;

/**
 * Lê a lista de commands da tela "Dev > Commands" do matrícula
 * (app/Http/Controllers/Dev/CommandsController.php, método commands()).
 *
 * Decisão: o arquivo é lido como texto e só o array literal do "return" é
 * interpretado, token a token. Não usamos "php artisan list" porque o boot do
 * matrícula consulta o banco (AppServiceProvider monta o menu) e trava quando
 * o banco não responde. Também não damos include/eval: nada do matrícula roda
 * dentro do painel, e a lista fica igual à da tela de lá automaticamente.
 */
class CatalogoDoMatricula
{
    public const ARQUIVO = 'app/Http/Controllers/Dev/CommandsController.php';

    /** @var string */
    private $caminho;

    /** @var array */
    private $tokens = [];

    /** @var int */
    private $posicao = 0;

    public function __construct(string $caminhoDoMatricula)
    {
        $this->caminho = rtrim($caminhoDoMatricula, '/').'/'.self::ARQUIVO;
    }

    /**
     * @return array<int, array{command: string, label?: string, description?: string, params?: array}>
     */
    public function ler(): array
    {
        if (! is_file($this->caminho)) {
            throw new RuntimeException("Não encontrei {$this->caminho}. Confira MATRICULA_PATH no .env do painel.");
        }

        $this->tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($this->caminho)),
            function ($t) {
                return ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
            }
        ));

        $this->posicionarNoReturnDoMetodo('commands');
        $lista = $this->valor();

        if (! is_array($lista)) {
            throw new RuntimeException($this->falha('o método commands() não devolve um array.'));
        }

        return array_values(array_filter($lista, function ($c) {
            return is_array($c) && is_string($c['command'] ?? null) && $c['command'] !== '';
        }));
    }

    private function posicionarNoReturnDoMetodo(string $metodo): void
    {
        $total = count($this->tokens);

        for ($i = 0; $i < $total - 1; $i++) {
            if ($this->eh($i, T_FUNCTION) && $this->eh($i + 1, T_STRING) && $this->tokens[$i + 1][1] === $metodo) {
                for ($j = $i + 2; $j < $total; $j++) {
                    if ($this->eh($j, T_RETURN)) {
                        $this->posicao = $j + 1;

                        return;
                    }
                }
            }
        }

        throw new RuntimeException($this->falha("não encontrei o \"return\" do método {$metodo}()."));
    }

    /**
     * Interpreta um valor literal: array, string, número, true, false ou null.
     *
     * @return mixed
     */
    private function valor()
    {
        $t = $this->tokens[$this->posicao] ?? null;

        if ($t === '[') {
            return $this->array();
        }

        if (is_array($t)) {
            $this->posicao++;

            switch ($t[0]) {
                case T_CONSTANT_ENCAPSED_STRING:
                    return $this->string($t[1]);
                case T_LNUMBER:
                    return (int) $t[1];
                case T_DNUMBER:
                    return (float) $t[1];
                case T_STRING:
                    $palavra = strtolower($t[1]);
                    if (in_array($palavra, ['true', 'false', 'null'], true)) {
                        return ['true' => true, 'false' => false, 'null' => null][$palavra];
                    }
            }
        }

        $trecho = is_array($t) ? $t[1] : (string) $t;
        $linha = is_array($t) ? " (linha {$t[2]})" : '';

        throw new RuntimeException($this->falha("só sei ler valores literais e encontrei \"{$trecho}\"{$linha}."));
    }

    private function array(): array
    {
        $this->posicao++; // [
        $resultado = [];

        while (($this->tokens[$this->posicao] ?? null) !== ']') {
            if (! isset($this->tokens[$this->posicao])) {
                throw new RuntimeException($this->falha('array sem fechamento.'));
            }

            $primeiro = $this->valor();

            if ($this->eh($this->posicao, T_DOUBLE_ARROW)) {
                $this->posicao++;
                $resultado[$primeiro] = $this->valor();
            } else {
                $resultado[] = $primeiro;
            }

            if (($this->tokens[$this->posicao] ?? null) === ',') {
                $this->posicao++;
            }
        }

        $this->posicao++; // ]

        return $resultado;
    }

    private function string(string $literal): string
    {
        $conteudo = substr($literal, 1, -1);

        return $literal[0] === "'"
            ? str_replace(["\\\\", "\\'"], ["\\", "'"], $conteudo)
            : stripcslashes($conteudo);
    }

    private function eh(int $i, int $tipo): bool
    {
        return is_array($this->tokens[$i] ?? null) && $this->tokens[$i][0] === $tipo;
    }

    private function falha(string $motivo): string
    {
        return "Não consegui ler a lista de commands em {$this->caminho}: {$motivo}";
    }
}
