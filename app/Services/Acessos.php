<?php

namespace App\Services;

/**
 * Uso do painel por usuário, para a tela Início: quantas vezes cada tela foi
 * aberta, as últimas solicitações abertas e as últimas buscas de aluno.
 *
 * Decisão: um arquivo JSON por usuário em storage/app/acessos, como o
 * histórico de execuções. Sem banco e sem depender do navegador (o app
 * instalado e o navegador comum somam no mesmo lugar).
 */
class Acessos
{
    private const RECENTES = 8;

    public function registrarTela(string $rota): void
    {
        $this->alterar(function (array $dados) use ($rota) {
            $tela = $dados['telas'][$rota] ?? ['total' => 0, 'ultimo' => null];
            $tela['total']++;
            $tela['ultimo'] = date(DATE_ATOM);
            $dados['telas'][$rota] = $tela;

            return $dados;
        });
    }

    public function registrarSolicitacao(int $codigo, ?string $aluno, $situacao): void
    {
        $this->alterar(function (array $dados) use ($codigo, $aluno, $situacao) {
            $lista = array_values(array_filter($dados['solicitacoes'] ?? [], function ($s) use ($codigo) {
                return (int) $s['codigo'] !== $codigo;
            }));
            array_unshift($lista, [
                'codigo' => $codigo,
                'aluno' => $aluno,
                'situacao' => $situacao === null ? null : (string) $situacao,
                'quando' => date(DATE_ATOM),
            ]);
            $dados['solicitacoes'] = array_slice($lista, 0, self::RECENTES);

            return $dados;
        });
    }

    public function registrarBusca(string $termo, int $resultados): void
    {
        $this->alterar(function (array $dados) use ($termo, $resultados) {
            $lista = array_values(array_filter($dados['buscas'] ?? [], function ($b) use ($termo) {
                return mb_strtolower($b['termo']) !== mb_strtolower($termo);
            }));
            array_unshift($lista, ['termo' => $termo, 'resultados' => $resultados, 'quando' => date(DATE_ATOM)]);
            $dados['buscas'] = array_slice($lista, 0, self::RECENTES);

            return $dados;
        });
    }

    /** @return array{telas: array, solicitacoes: array, buscas: array} */
    public function dados(): array
    {
        $arquivo = $this->arquivo();
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;

        return $this->completar(is_array($dados) ? $dados : []);
    }

    private function alterar(callable $mudanca): void
    {
        $arquivo = $this->arquivo();
        if (! is_dir(dirname($arquivo))) {
            mkdir(dirname($arquivo), 0775, true);
        }

        // Travado entre ler e gravar: duas abas abertas não perdem contagem.
        $ponteiro = fopen($arquivo, 'c+');
        if (! $ponteiro) {
            return;
        }
        flock($ponteiro, LOCK_EX);
        $atual = json_decode((string) stream_get_contents($ponteiro), true);
        $novo = $mudanca($this->completar(is_array($atual) ? $atual : []));
        ftruncate($ponteiro, 0);
        rewind($ponteiro);
        fwrite($ponteiro, json_encode($novo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($ponteiro);
        flock($ponteiro, LOCK_UN);
        fclose($ponteiro);
    }

    private function completar(array $dados): array
    {
        return [
            'telas' => (array) ($dados['telas'] ?? []),
            'solicitacoes' => (array) ($dados['solicitacoes'] ?? []),
            'buscas' => (array) ($dados['buscas'] ?? []),
        ];
    }

    private function arquivo(): string
    {
        $usuario = (string) (session('usuario.email') ?: 'acesso-livre');

        return storage_path('app/acessos/'.sha1(mb_strtolower($usuario)).'.json');
    }
}
