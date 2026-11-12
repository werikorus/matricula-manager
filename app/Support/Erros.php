<?php

namespace App\Support;

class Erros
{
    /**
     * O ds_erro guarda a exceção inteira do RM (com stack trace). Para a
     * tabela basta a mensagem: o trecho entre "*** ERROS ***" e "Mais
     * detalhes", ou a primeira linha sem os prefixos "System.Exception:".
     */
    public static function resumir(string $erro): string
    {
        $erro = trim($erro);
        if ($erro === '') {
            return '';
        }

        if (preg_match('/\*\*\* ERROS \*\*\*(.*?)(Mais detalhes|\s+at\s|$)/s', $erro, $m)) {
            $texto = $m[1];
        } else {
            $texto = strtok($erro, "\n");
        }

        $texto = preg_replace('/^(\s*[\w.]*Exception:\s*)+/', '', (string) $texto);
        $texto = preg_replace('/^\s*\*+\s*/', '', $texto);
        $texto = preg_replace('/^\s*-\s*/m', '', $texto);
        $texto = trim(preg_replace('/\s+/', ' ', $texto));

        return mb_strimwidth($texto !== '' ? $texto : $erro, 0, 220, '…');
    }
}
