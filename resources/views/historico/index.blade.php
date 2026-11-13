@extends('layouts.app')

@section('titulo', 'Histórico de commands')

@php
    $filtros = ['sucesso' => 'Concluídos', 'falha' => 'Falharam', 'interrompido' => 'Interrompidos', 'executando' => 'Em andamento'];
    $data = function ($iso) {
        $t = $iso ? strtotime((string) $iso) : false;

        return $t ? date('d/m/Y H:i', $t) : '—';
    };
    $duracao = function (array $e) {
        if (! $e['iniciado_em']) return '—';
        if ($e['ativa']) return 'em andamento';
        $s = max(0, strtotime((string) ($e['finalizado_em'] ?: date(DATE_ATOM))) - strtotime((string) $e['iniciado_em']));
        if ($s < 60) return $s.' s';
        if ($s < 3600) return intdiv($s, 60).' min '.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT).' s';
        return intdiv($s, 3600).' h '.str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT).' min';
    };
@endphp

@section('conteudo')
<header class="topo">
    <h1>Histórico de commands</h1>
    <div class="topo-meio">
        <span class="historico-nota">Execuções feitas pelo painel (as {{ $limite }} mais recentes). Clique em uma linha para ver a saída.</span>
    </div>
    <a class="botao" href="{{ route('historico') }}" data-carregando="Atualizando o histórico…">Atualizar</a>
</header>

@if (count($execucoes) === 0)
    <div class="folha"><p class="vazio">Nada foi executado pelo painel ainda.</p></div>
@else
    <div class="resumo" role="status">
        <span class="resumo-item"><strong>{{ count($execucoes) }}</strong> {{ count($execucoes) === 1 ? 'execução' : 'execuções' }}</span>
        <span class="resumo-item"><strong>{{ $porStatus['sucesso'] ?? 0 }}</strong> concluídas</span>
        <span class="resumo-item resumo-falha"><strong>{{ $porStatus['falha'] ?? 0 }}</strong> falharam</span>
        <span class="resumo-item resumo-aviso"><strong>{{ $porStatus['interrompido'] ?? 0 }}</strong> interrompidas</span>
        @if (($porStatus['executando'] ?? 0) + ($porStatus['pendente'] ?? 0))
            <span class="resumo-item"><strong>{{ ($porStatus['executando'] ?? 0) + ($porStatus['pendente'] ?? 0) }}</strong> em andamento</span>
        @endif
        <input type="search" class="lista-busca resumo-busca" id="tabela-busca" placeholder="Filtrar por command, parâmetro ou usuário" aria-label="Filtrar execuções" autocomplete="off" spellcheck="false">
    </div>

    <div class="atalhos" aria-label="Filtrar por situação">
        <span class="atalhos-titulo">Situação:</span>
        @foreach ($filtros as $status => $texto)
            @if (! empty($porStatus[$status]))
                <button type="button" class="atalho" data-filtro="status:{{ $status }}"><strong>{{ $porStatus[$status] }}</strong> {{ $texto }}</button>
            @endif
        @endforeach
    </div>

    <div class="historico-bancada">
        <div class="folha historico-lista">
            <nav class="paginacao paginacao-topo" data-paginacao aria-label="Paginação" hidden></nav>
            <div class="tabela-rolagem">
                <table class="tabela-historico">
                    <thead>
                        <tr><th class="num">#</th><th>Situação</th><th>Command</th><th class="num">Quando</th><th class="num" title="Código de saída">Cód.</th></tr>
                    </thead>
                    <tbody id="tabela-corpo">
                        @foreach ($execucoes as $e)
                            <tr tabindex="0" data-execucao="{{ $e['id'] }}"
                                data-busca="{{ mb_strtolower('status:'.$e['status'].' '.$e['titulo'].' '.$e['linha'].' '.$e['usuario'].' '.($e['ambiente'] ?? '').' #'.$e['id']) }}">
                                <td class="num">{{ $e['id'] }}</td>
                                <td><span class="selo selo-{{ $e['status'] }}">{{ $rotulos[$e['status']] ?? $e['status'] }}</span></td>
                                <td class="historico-comando">
                                    <strong>{{ $e['titulo'] }}@if (! empty($e['ambiente'])) <span class="ambiente-chave ambiente-chave-{{ strtolower($e['ambiente']) }}">{{ $e['ambiente'] }}</span>@endif</strong>
                                    <code title="{{ $e['linha'] }}">{{ preg_replace('/^php artisan /', '', $e['linha']) }}</code>
                                    <span class="historico-usuario">{{ $e['usuario'] ?: '—' }}</span>
                                </td>
                                <td class="num historico-quando">
                                    {{ $data($e['iniciado_em'] ?: $e['criado_em']) }}
                                    <span>{{ $duracao($e) }}</span>
                                </td>
                                <td class="num">{{ $e['codigo_saida'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="vazio" id="tabela-vazia" hidden>Nenhuma execução corresponde ao filtro.</p>
            <nav class="paginacao" data-paginacao aria-label="Paginação (rodapé)" hidden></nav>
        </div>

        <section class="console historico-console" id="console" data-status="vazio" aria-label="Saída da execução">
            <header class="console-topo">
                <span class="console-status" id="console-status" role="status">Nenhuma execução selecionada</span>
                <code class="console-linha" id="console-linha"></code>
                <span class="console-tempo" id="console-tempo"></span>
            </header>
            <div class="historico-acoes" id="console-acoes" hidden>
                <a class="botao botao-console" id="acao-repetir" href="#" data-carregando="Abrindo commands…" title="Abre o command com os mesmos parâmetros, para conferir e executar">Repetir</a>
                <a class="botao botao-console" id="acao-acompanhar" href="#" data-carregando="Abrindo o console…" hidden>Acompanhar em Commands</a>
                <button type="button" class="botao botao-console" id="acao-copiar">Copiar saída</button>
                <button type="button" class="botao botao-console" id="acao-parar" hidden>Parar</button>
            </div>
            <pre class="console-saida" id="console-saida" tabindex="0" data-vazio="Escolha uma execução na lista para ver a saída."></pre>
        </section>
    </div>
@endif

@push('scripts')
    <script>
        window.HISTORICO = @json($dadosJs);
    </script>
    <script src="{{ asset('js/tabela.js') }}?v={{ filemtime(public_path('js/tabela.js')) }}"></script>
    <script src="{{ asset('js/console-terminal.js') }}?v={{ filemtime(public_path('js/console-terminal.js')) }}"></script>
    <script src="{{ asset('js/historico.js') }}?v={{ filemtime(public_path('js/historico.js')) }}"></script>
@endpush
@endsection
