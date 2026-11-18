<?php
// Histórico fictício de execuções e de uso das telas da demonstração.
// Recria tools/demo/storage/app/{execucoes,acessos} a cada subida.
$dir = __DIR__.'/storage/app/execucoes';
@mkdir($dir, 0775, true);
array_map('unlink', glob("$dir/*"));
$ano = (int) date('Y');
$execs = [
    ['integradorLoteCommand', ['integradorLoteCommand', '--', 'anoLetivo', (string) ($ano + 1)], 'sucesso', 0, 26 * 3600, 214],
    ['ProcessaAdmissaoRMCommand', ['ProcessaAdmissaoRMCommand'], 'sucesso', 0, 22 * 3600, 95],
    ['integradorCommand', ['integradorCommand', '--', '482327'], 'falha', 1, 20 * 3600, 12],
    ['ProcessaReservaRMCommand', ['ProcessaReservaRMCommand'], 'sucesso', 0, 6 * 3600, 48],
    ['alteraStatusReservaPaga', ['alteraStatusReservaPaga'], 'sucesso', 0, 5 * 3600, 31],
    ['integradorCommand', ['integradorCommand', '--', '482310'], 'sucesso', 0, 3 * 3600, 18],
    ['GeraParcelaCommand', ['GeraParcelaCommand', '--', '482344'], 'interrompido', null, 2 * 3600, 40],
    ['integradorLoteCommand', ['integradorLoteCommand', '--', 'processo', '9101'], 'sucesso', 0, 40 * 60, 132],
];
$id = 0;
foreach ($execs as [$cmd, $args, $status, $saida, $atras, $duracao]) {
    $id++;
    $ini = time() - $atras;
    file_put_contents("$dir/$id.json", json_encode([
        'id' => $id, 'comando' => $cmd, 'argumentos' => $args, 'ambiente' => 'PRD', 'caminho' => '/home/usuario/matricula',
        'status' => $status, 'codigo_saida' => $saida, 'pid' => null, 'pid_executor' => null, 'usuario' => 'acesso-livre',
        'criado_em' => date(DATE_ATOM, $ini), 'iniciado_em' => date(DATE_ATOM, $ini + 1), 'finalizado_em' => date(DATE_ATOM, $ini + $duracao),
        'interrupcao_solicitada_em' => null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $log = "Iniciando {$cmd}...\n";
    if ($cmd === 'integradorLoteCommand') {
        $log .= "Filtro: {$args[2]} = {$args[3]}\nSolicitações encontradas: 11\n\n";
        foreach ([482310, 482327, 482344, 482361, 482378, 482395, 482412, 482429, 482446, 482463, 482480] as $i => $s) {
            $log .= sprintf("[%02d/11] Solicitação %d ... %s\n", $i + 1, $s, $i === 3 ? 'ERRO: plano de pagamento não encontrado para a turma' : 'integrada no RM');
        }
        $log .= "\nResumo: 10 integradas, 1 com erro.\nConcluído em {$duracao}s.\n";
    } elseif ($status === 'falha') {
        $log .= "Solicitação {$args[2]}: aluno já possui matrícula ativa no período letivo informado.\n";
    } else {
        $log .= "Registros processados: ".(3 + $id)."\nConcluído em {$duracao}s.\n";
    }
    file_put_contents("$dir/$id.log", $log);
}
file_put_contents("$dir/contador", (string) $id);

$telas = ['commands' => 42, 'matriculas' => 35, 'alunos' => 27, 'pabs' => 18, 'historico' => 12, 'reservas' => 9];
$dados = ['telas' => [], 'solicitacoes' => [], 'buscas' => []];
foreach ($telas as $rota => $total) {
    $dados['telas'][$rota] = ['total' => $total, 'ultimo' => date(DATE_ATOM, time() - $total * 900)];
}
foreach ([[482310, 'Ana Clara Ribeiro Prado', '16'], [482327, 'Bruno Henrique Tavares', '14'], [482344, 'Carolina Mendes Farias', '2']] as $i => [$c, $a, $s]) {
    $dados['solicitacoes'][] = ['codigo' => $c, 'aluno' => $a, 'situacao' => $s, 'quando' => date(DATE_ATOM, time() - ($i + 1) * 2400)];
}
foreach ([['ana clara', 3], ['031207', 1], ['Davi Lucas', 2]] as $i => [$t, $r]) {
    $dados['buscas'][] = ['termo' => $t, 'resultados' => $r, 'quando' => date(DATE_ATOM, time() - ($i + 1) * 3000)];
}
@mkdir(__DIR__.'/storage/app/acessos', 0775, true);
file_put_contents(__DIR__.'/storage/app/acessos/'.sha1('acesso-livre').'.json', json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "ok\n";
