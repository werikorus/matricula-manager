@extends('layouts.app')

@section('titulo', 'Matrículas paradas')

@php
    use App\Http\Controllers\MatriculaController;

    $data = function ($valor, $formato = 'd/m/Y H:i') {
        $tempo = $valor ? strtotime((string) $valor) : false;

        return $tempo ? date($formato, $tempo) : '—';
    };
    $comErro = array_filter($matriculas, function ($m) { return (int) $m['status'] === 16; });
    $aguardando = count($matriculas) - count($comErro);
    $antigas = array_filter($matriculas, function ($m) { return (int) $m['dias_corridos'] >= 7; });

    // Erros que se repetem viram atalhos de filtro.
    $frequentes = array_count_values(array_filter(array_column($matriculas, 'erro_resumo')));
    arsort($frequentes);
    $frequentes = array_slice(array_filter($frequentes, function ($n) { return $n >= 3; }), 0, 6, true);
@endphp

@section('conteudo')
<header class="topo">
    <h1>Matrículas paradas</h1>
    <div class="topo-meio">
        <span class="ambiente" title="Consulta direta no banco do matrícula">
            <strong>{{ $ambiente }}</strong>
            <code>tb_solicitacao</code>
        </span>
        @include('components.filtro-ano', ['ano' => $ano])
        <span class="filtro-ano-nota" title="Solicitações do ano letivo {{ $ano }} incluídas desde 1º de janeiro de {{ min($ano, (int) date('Y')) }} (as de um ano futuro são feitas no ano atual)">incluídas desde {{ $data($desde, 'd/m/Y') }}</span>
    </div>
    <span class="historico-nota">Consultado às {{ $consultadoEm->format('H:i:s') }}</span>
    <a class="botao" href="{{ route('matriculas', ['ano' => $ano]) }}" data-carregando="Consultando matrículas paradas…">Atualizar</a>
    @if (! $erro)
        @if ($emAndamento)
            <a class="botao botao-primario" href="{{ route('commands', ['comando' => $emAndamento->comando]) }}">
                <span class="pulso pulso-claro" aria-hidden="true"></span> Integração em andamento
            </a>
        @else
            <button type="button" class="botao botao-primario" id="reprocessar-todos">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.6-6.4M21 4v5h-5"/></svg>
                Reprocessar todos
            </button>
        @endif
    @endif
</header>

@if ($errors->any())
    <div class="aviso aviso-falha" role="alert">
        <h2>Não foi possível reprocessar</h2>
        <p>{{ $errors->first() }}</p>
    </div>
@endif

@if (session('erro'))
    <div class="aviso aviso-falha" role="alert">
        <h2>Não foi possível reprocessar</h2>
        <p>{{ session('erro') }}</p>
    </div>
@endif

@if ($erro)
    <div class="aviso aviso-falha" role="alert">
        <h2>Não consegui consultar as matrículas</h2>
        <p>A lista vem direto do banco do matrícula, com as credenciais do .env de lá.</p>
        <pre>{{ $erro }}</pre>
    </div>
@else
    <div class="resumo" role="status">
        <span class="resumo-item"><strong>{{ count($matriculas) }}</strong> {{ count($matriculas) === 1 ? 'matrícula parada' : 'matrículas paradas' }}</span>
        <span class="resumo-item resumo-aviso"><strong>{{ $aguardando }}</strong> aguardando integrar</span>
        <span class="resumo-item resumo-falha"><strong>{{ count($comErro) }}</strong> com erro de integração</span>
        <span class="resumo-item"><strong>{{ count($antigas) }}</strong> há 7 dias ou mais</span>
        <input type="search" class="lista-busca resumo-busca" id="tabela-busca" placeholder="Filtrar por aluno, RA, solicitação, processo ou erro" aria-label="Filtrar matrículas" autocomplete="off" spellcheck="false">
    </div>

    @if (count($frequentes))
        <div class="atalhos" aria-label="Erros mais comuns">
            <span class="atalhos-titulo">Erros mais comuns:</span>
            @foreach ($frequentes as $texto => $quantidade)
                <button type="button" class="atalho" data-filtro="{{ $texto }}" title="{{ $texto }}">
                    <strong>{{ $quantidade }}</strong> {{ \Illuminate\Support\Str::limit($texto, 70) }}
                </button>
            @endforeach
        </div>
    @endif

    <div class="folha">
        @if (count($matriculas) === 0)
            <p class="vazio">Nenhuma matrícula parada. Tudo integrado. 🎉</p>
        @else
            <nav class="paginacao paginacao-topo" data-paginacao aria-label="Paginação" hidden></nav>
            <div class="tabela-rolagem">
                <table class="tabela-pabs tabela-matriculas">
                    <thead>
                        <tr>
                            <th>Solicitação</th>
                            <th>Aluno</th>
                            <th>Processo</th>
                            <th>Situação e erro</th>
                            <th class="num">Tentativas</th>
                            <th class="num">Incluída em</th>
                            <th class="num">Parada há</th>
                            <th><span class="sr">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody id="tabela-corpo">
                        @foreach ($matriculas as $m)
                        @php
                            $status = (int) $m['status'];
                            $dias = (int) $m['dias_corridos'];
                        @endphp
                        <tr data-busca="{{ mb_strtolower($m['aluno'].' '.$m['nu_ra'].' '.$m['cd_solicitacao'].' '.$m['processo'].' '.$m['erro_resumo']) }}">
                            <td><code title="Contrato: {{ $m['cd_contrato'] ?: '—' }}">{{ $m['cd_solicitacao'] }}</code></td>
                            <td>
                                <span class="aluno-linha">
                                    <span class="pab-aluno">{{ $m['aluno'] ?: '—' }}</span>
                                    <a class="link-editar" href="{{ route('solicitacoes.editar', ['codigo' => $m['cd_solicitacao'], 'voltar' => request()->getRequestUri()]) }}"
                                       data-carregando="Abrindo a solicitação {{ $m['cd_solicitacao'] }}…"
                                       title="Ver e editar a solicitação {{ $m['cd_solicitacao'] }} (tb_solicitacao)" aria-label="Editar a solicitação {{ $m['cd_solicitacao'] }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                                    </a>
                                </span>
                                <span class="pab-ra">RA {{ $m['nu_ra'] ?: '—' }}</span>
                            </td>
                            <td class="mat-processo">
                                <span class="mat-processo-nome" title="{{ $m['processo'] }}">{{ $m['processo'] }}</span>
                                <span class="pab-ra">{{ $m['ano'] }} · col {{ $m['cd_coligada'] }}/fil {{ $m['cd_filial'] }} ·
                                    @if ($emAndamento)
                                        proc {{ $m['cd_processo'] }}
                                    @else
                                        <button type="button" class="link-processo" data-lote-processo="{{ $m['cd_processo'] }}" title="Reintegrar em lote só este processo">proc {{ $m['cd_processo'] }}</button>
                                    @endif
                                </span>
                            </td>
                            <td class="mat-erro">
                                <span class="selo {{ $status === 16 ? 'selo-falha' : 'selo-interrompido' }}" title="Código {{ $status }}">{{ $m['descricao_status'] }}</span>
                                @if ($m['erro_resumo'] !== '')
                                    <details>
                                        <summary>{{ $m['erro_resumo'] }}</summary>
                                        <pre>{{ trim($m['ds_erro']) }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td class="num">{{ $m['tentativas'] === null ? '—' : $m['tentativas'] }}</td>
                            <td class="num">{{ $data($m['data_inclusao']) }}</td>
                            <td class="num"><span class="{{ $dias >= 7 ? 'dias-alto' : '' }}">{{ $dias }} {{ $dias === 1 ? 'dia' : 'dias' }}</span></td>
                            <td class="num">
                                @if ($emAndamento)
                                    <span class="botao botao-mini" aria-disabled="true"
                                          title="Já há um integrador rodando ({{ $emAndamento->comando }}). Espere terminar.">Reprocessar</span>
                                @else
                                    <button type="button" class="botao botao-mini"
                                            data-reprocessar-solicitacao="{{ $m['cd_solicitacao'] }}"
                                            data-aluno="{{ $m['aluno'] ?: '—' }}"
                                            data-situacao="{{ $m['status'] }} · {{ $m['descricao_status'] }}"
                                            data-tentativas="{{ $m['tentativas'] === null ? 'vazio' : $m['tentativas'] }}"
                                            title="Volta a solicitação para 14, zera as tentativas, limpa o erro e roda o integradorCommand">Reprocessar</button>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="vazio" id="tabela-vazia" hidden>Nenhuma matrícula corresponde ao filtro.</p>
            <nav class="paginacao" data-paginacao aria-label="Paginação (rodapé)" hidden></nav>
        @endif
    </div>
@endif

@if (! $erro && ! $emAndamento)
@php
    $processosDaLista = array_count_values(array_column($matriculas, 'cd_processo'));
    // Na escolha por processo, primeiro os que aparecem nesta lista.
    $opcoesProcesso = $porProcesso;
    usort($opcoesProcesso, function ($a, $b) use ($processosDaLista) {
        return [isset($processosDaLista[$b['cd_processo']]), $b['ano'], $b['total']]
            <=> [isset($processosDaLista[$a['cd_processo']]), $a['ano'], $a['total']];
    });
@endphp
<dialog id="confirmar-todos" aria-labelledby="confirmar-todos-titulo">
    <form method="POST" action="{{ route('matriculas.reprocessar-todos') }}" class="dialogo" data-carregando="Iniciando a integração em lote…" id="form-lote">
        @csrf
        <input type="hidden" name="ano" value="{{ $ano }}">
        <h2 id="confirmar-todos-titulo">Reintegrar matrículas em {{ $ambiente }}?</h2>
        <p>Roda o integrador em lote do matrícula. Ele <strong>zera as tentativas</strong>, volta as com erro para "Aguardando integrar RM" e tenta integrar todas com o RM.</p>

        <fieldset class="escolha-filtro">
            <legend>Quais solicitações?</legend>
            <label class="escolha">
                <input type="radio" name="filtro" value="anoLetivo" checked>
                <span>
                    <strong>Todas do ano letivo {{ $ano }}</strong>
                    <small>{{ $noLoteDoAno }} {{ $noLoteDoAno === 1 ? 'solicitação' : 'solicitações' }} na situação 14 ou 16</small>
                </span>
            </label>
            <label class="escolha">
                <input type="radio" name="filtro" value="processo">
                <span>
                    <strong>Só de um processo</strong>
                    <select name="processo" id="lote-processo" aria-label="Processo" disabled>
                        <option value="">Selecione o processo…</option>
                        @foreach ($opcoesProcesso as $p)
                            <option value="{{ $p['cd_processo'] }}" data-total="{{ $p['total'] }}">
                                {{ $p['cd_processo'] }} · {{ $p['processo'] }} ({{ $p['total'] }})
                            </option>
                        @endforeach
                    </select>
                </span>
            </label>
        </fieldset>

        <pre id="lote-linha" data-comando="php artisan {{ MatriculaController::COMANDO_LOTE }}" data-ano="{{ $ano }}">php artisan {{ MatriculaController::COMANDO_LOTE }} anoLetivo {{ $ano }}</pre>
        <p class="dialogo-nota" id="lote-nota">O command não filtra pela data de inclusão: pode pegar solicitações que não aparecem nesta lista.</p>

        <div class="dialogo-acoes">
            <button type="button" class="botao" onclick="this.closest('dialog').close()">Cancelar</button>
            <button type="submit" class="botao botao-primario" id="confirmar-todos-enviar">Reprocessar</button>
        </div>
    </form>
</dialog>
@endif

@if (! $erro && ! $emAndamento)
    @include('components.reprocessar-solicitacao', ['ambiente' => $ambiente])
@endif

@push('scripts')
<script src="{{ asset('js/tabela.js') }}?v={{ filemtime(public_path('js/tabela.js')) }}"></script>
<script src="{{ asset('js/copiar.js') }}?v={{ filemtime(public_path('js/copiar.js')) }}"></script>
@endpush
@endsection
