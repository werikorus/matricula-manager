<?php

namespace App\Models;

/**
 * Uma execução de command e o histórico delas.
 *
 * Decisão: cada execução é um arquivo JSON em storage/app/execucoes, ao lado
 * do log de saída. Assim o painel não precisa de banco de dados, de migrations
 * nem de extensões extras do PHP (como pdo_sqlite). Para uma ferramenta de um
 * usuário só, com poucas execuções por dia, é o suficiente.
 *
 * @property-read int $id
 * @property-read string $comando
 * @property-read string[] $argumentos
 * @property-read string $status
 * @property-read int|null $codigo_saida
 * @property-read int|null $pid
 * @property-read int|null $pid_executor
 * @property-read string $usuario
 * @property-read string|null $ambiente
 * @property-read string|null $caminho
 * @property-read string $criado_em
 * @property-read string|null $iniciado_em
 * @property-read string|null $finalizado_em
 * @property-read string|null $interrupcao_solicitada_em
 */
class Execucao
{
    public const ATIVOS = ['pendente', 'executando'];

    /** @var array */
    private $dados;

    private function __construct(array $dados)
    {
        $this->dados = $dados;
    }

    public function __get(string $nome)
    {
        return $this->dados[$nome] ?? null;
    }

    public static function pasta(): string
    {
        return storage_path('app/execucoes');
    }

    public static function agora(): string
    {
        return date(DATE_ATOM);
    }

    /**
     * @param  string[]  $argumentos  nome do command seguido dos parâmetros
     */
    public static function criar(string $comando, array $argumentos, string $usuario): self
    {
        if (! is_dir(self::pasta())) {
            mkdir(self::pasta(), 0775, true);
        }

        // O contador fica travado enquanto o próximo ID é reservado.
        $contador = fopen(self::pasta().'/contador', 'c+');
        flock($contador, LOCK_EX);
        $id = (int) stream_get_contents($contador) + 1;
        ftruncate($contador, 0);
        rewind($contador);
        fwrite($contador, (string) $id);
        fflush($contador);
        flock($contador, LOCK_UN);
        fclose($contador);

        $execucao = new self([
            'id' => $id,
            'comando' => $comando,
            'argumentos' => array_values($argumentos),
            // Onde rodar: fixado na criação, para uma troca de ambiente depois não mudar o destino.
            'ambiente' => (string) config('painel.matricula.ambiente'),
            'caminho' => (string) config('painel.matricula.caminho'),
            'status' => 'pendente',
            'codigo_saida' => null,
            'pid' => null,
            'pid_executor' => null,
            'usuario' => $usuario,
            'criado_em' => self::agora(),
            'iniciado_em' => null,
            'finalizado_em' => null,
            'interrupcao_solicitada_em' => null,
        ]);

        file_put_contents($execucao->caminhoDados(), self::codificar($execucao->dados), LOCK_EX);

        return $execucao;
    }

    /**
     * @param  int|string  $id
     */
    public static function buscar($id): ?self
    {
        if (! preg_match('/^[0-9]+$/', (string) $id)) {
            return null;
        }

        $dados = self::ler(self::pasta()."/{$id}.json");

        return $dados === null ? null : new self($dados);
    }

    /**
     * As execuções mais recentes primeiro.
     *
     * @return self[]
     */
    public static function recentes(int $limite = 30): array
    {
        $ids = array_map(function (string $arquivo) {
            return (int) basename($arquivo, '.json');
        }, glob(self::pasta().'/*.json') ?: []);

        rsort($ids);

        return array_values(array_filter(array_map(function (int $id) {
            return self::buscar($id);
        }, array_slice($ids, 0, $limite))));
    }

    public static function existeAtivaDoComando(string $comando): bool
    {
        return self::ativaDoComando($comando) !== null;
    }

    public static function ativaDoComando(string $comando): ?self
    {
        foreach (self::recentes(50) as $execucao) {
            if ($execucao->comando === $comando && $execucao->ativa()) {
                return $execucao;
            }
        }

        return null;
    }

    /**
     * Grava as mudanças por cima do que está no disco, com o arquivo travado:
     * o painel e o executor em segundo plano atualizam a mesma execução.
     */
    public function atualizar(array $mudancas): void
    {
        $arquivo = fopen($this->caminhoDados(), 'c+');
        flock($arquivo, LOCK_EX);

        $atual = json_decode((string) stream_get_contents($arquivo), true);
        $this->dados = array_merge(is_array($atual) ? $atual : $this->dados, $mudancas);

        ftruncate($arquivo, 0);
        rewind($arquivo);
        fwrite($arquivo, self::codificar($this->dados));
        fflush($arquivo);
        flock($arquivo, LOCK_UN);
        fclose($arquivo);
    }

    public function recarregar(): void
    {
        $dados = self::ler($this->caminhoDados());

        if ($dados !== null) {
            $this->dados = $dados;
        }
    }

    public function caminhoDados(): string
    {
        return self::pasta()."/{$this->id}.json";
    }

    public function caminhoLog(): string
    {
        return self::pasta()."/{$this->id}.log";
    }

    public function ativa(): bool
    {
        return in_array($this->status, self::ATIVOS, true);
    }

    /** Linha de comando equivalente, só para exibição. */
    public function linha(): string
    {
        $partes = array_map(function (string $parte) {
            return preg_match('/^[\w:=.,@\/-]+$/', $parte) ? $parte : escapeshellarg($parte);
        }, $this->argumentos ?: []);

        return 'php artisan '.implode(' ', $partes);
    }

    /**
     * Dispara o executor em segundo plano (veja ExecutarCommand).
     */
    public function disparar(): void
    {
        $php = (new \Symfony\Component\Process\PhpExecutableFinder)->find(false) ?: 'php';

        exec(sprintf(
            'nohup %s %s painel:executar %d > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            $this->id
        ));
    }

    public function anexarAoLog(string $texto): void
    {
        file_put_contents($this->caminhoLog(), $texto, FILE_APPEND);
    }

    public function resumo(): array
    {
        return [
            'id' => $this->id,
            'comando' => $this->comando,
            'linha' => $this->linha(),
            'status' => $this->status,
            'ativa' => $this->ativa(),
            'codigo_saida' => $this->codigo_saida,
            'usuario' => $this->usuario,
            'ambiente' => $this->ambiente,
            'criado_em' => $this->criado_em,
            'iniciado_em' => $this->iniciado_em,
            'finalizado_em' => $this->finalizado_em,
        ];
    }

    /**
     * Corrige execuções que ficaram "ativas" sem processo por trás, por
     * exemplo depois de um "wsl --shutdown" no meio de uma rotina.
     */
    public static function sincronizarOrfas(): void
    {
        foreach (self::recentes(50) as $execucao) {
            if ($execucao->status === 'executando'
                && $execucao->pid_executor
                && ! is_dir("/proc/{$execucao->pid_executor}")) {
                $execucao->anexarAoLog("\n[painel] O processo não está mais rodando e não registrou o término.\n");
                $execucao->atualizar(['status' => 'interrompido', 'finalizado_em' => self::agora()]);
            }

            if ($execucao->status === 'pendente' && strtotime($execucao->criado_em) < time() - 20) {
                $execucao->anexarAoLog("[painel] O executor não iniciou. Veja storage/logs/laravel.log do painel.\n");
                $execucao->atualizar(['status' => 'falha', 'finalizado_em' => self::agora()]);
            }
        }
    }

    private static function ler(string $caminho): ?array
    {
        if (! is_file($caminho)) {
            return null;
        }

        $arquivo = fopen($caminho, 'r');
        flock($arquivo, LOCK_SH);
        $dados = json_decode((string) stream_get_contents($arquivo), true);
        flock($arquivo, LOCK_UN);
        fclose($arquivo);

        return is_array($dados) ? $dados : null;
    }

    private static function codificar(array $dados): string
    {
        return json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
