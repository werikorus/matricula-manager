<?php

namespace App\Console\Commands;

use App\Models\Execucao;
use App\Services\MatriculaArtisan;
use Illuminate\Console\Command;
use Throwable;

/**
 * Roda uma execução pendente em segundo plano.
 *
 * Decisão: o painel dispara este command como processo independente em vez de
 * usar fila. Assim não existe um "queue:work" para manter de pé, a rotina não
 * fica presa a uma requisição web e continua mesmo que a janela seja fechada.
 */
class ExecutarCommand extends Command
{
    protected $signature = 'painel:executar {execucao : ID da execução pendente}';

    protected $description = 'Executa no matrícula o command de uma execução pendente (uso interno do painel)';

    public function handle(MatriculaArtisan $matricula): int
    {
        $execucao = Execucao::buscar($this->argument('execucao'));

        if (! $execucao || $execucao->status !== 'pendente') {
            $this->error('Execução inexistente ou já iniciada.');

            return 1;
        }

        $execucao->atualizar([
            'status' => 'executando',
            'iniciado_em' => Execucao::agora(),
            'pid_executor' => getmypid(),
        ]);

        $execucao->anexarAoLog('$ '.$execucao->linha()."\n\n");
        $log = fopen($execucao->caminhoLog(), 'ab');
        $codigo = null;

        try {
            $processo = $matricula->processo($execucao->argumentos)->disableOutput();
            $processo->start(function (string $tipo, string $trecho) use ($log) {
                fwrite($log, $trecho);
            });
            $execucao->atualizar(['pid' => $processo->getPid()]);
            $codigo = $processo->wait();
        } catch (Throwable $e) {
            fwrite($log, "\n[painel] Não foi possível executar: {$e->getMessage()}\n");
        }

        $execucao->recarregar();

        if ($execucao->interrupcao_solicitada_em !== null) {
            $status = 'interrompido';
        } elseif ($codigo === 0) {
            $status = 'sucesso';
        } else {
            $status = 'falha';
        }

        fwrite($log, "\n[painel] Terminou com status \"{$status}\"".($codigo !== null ? " (código {$codigo})" : '').".\n");
        fclose($log);

        $execucao->atualizar([
            'status' => $status,
            'codigo_saida' => $codigo,
            'finalizado_em' => Execucao::agora(),
        ]);

        return 0;
    }
}
