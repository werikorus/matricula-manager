@extends('layouts.app')

@section('titulo', 'Gráficos')

@section('conteudo')
<div class="graficos">
<header class="topo">
    <h1>Gráficos</h1>
    <div class="topo-meio">
        <span class="ambiente" title="Reprocessamentos feitos pelo painel, com a situação atual de cada registro no banco">
            <strong>{{ $ambiente }}</strong>
            <code>reprocessamentos do painel</code>
        </span>
    </div>
    <span class="historico-nota">Consultado às {{ $consultadoEm->format('H:i:s') }}</span>
    <a class="botao" href="{{ route('graficos') }}" data-carregando="Consultando os reprocessamentos…">Atualizar</a>
</header>

@if ($erro)
    <div class="aviso aviso-falha" role="alert">
        <h2>Não consegui consultar a situação atual dos registros</h2>
        <p>Os gráficos comparam o que o painel reprocessou com o banco do matrícula, com as credenciais do .env de lá.</p>
        <pre>{{ $erro }}</pre>
    </div>
@endif

<div class="filtros-graficos">
    <div class="filtro-grafico"><span>Período</span>
        <div class="segmentos" data-filtro="periodo" role="group" aria-label="Período">
            <button type="button" data-valor="28" aria-pressed="true">4 semanas</button>
            <button type="button" data-valor="91" aria-pressed="false">13 semanas</button>
        </div>
    </div>
    <div class="filtro-grafico"><span>Tipo</span>
        <div class="segmentos" data-filtro="tipo" role="group" aria-label="Tipo de registro">
            <button type="button" data-valor="todos" aria-pressed="true">Todos</button>
            <button type="button" data-valor="matriculas" aria-pressed="false">Matrículas</button>
            <button type="button" data-valor="pabs" aria-pressed="false">PABs</button>
            <button type="button" data-valor="reservas" aria-pressed="false">Reservas</button>
        </div>
    </div>
</div>

<section class="kpis" id="kpis" aria-label="Resumo do período"></section>

<div class="grade-graficos">
    <section class="cartao-grafico">
        <h2>O que aconteceu com cada reprocessamento</h2>
        <p class="cartao-sub">Registros reprocessados por semana e a situação deles agora no banco.</p>
        <div class="legenda">
            <span><i class="chave" style="background:var(--st-resolvido)"></i>Resolvido</span>
            <span><i class="chave" style="background:var(--st-parado)"></i>Ainda parado</span>
            <span><i class="chave" style="background:var(--st-voltou)"></i>Voltou a dar erro</span>
        </div>
        <div class="area-grafico" id="semanas"></div>
        <details><summary>Ver como tabela</summary><table id="semanas-tabela"></table></details>
    </section>

    <section class="cartao-grafico">
        <h2>Taxa de resolução por tipo</h2>
        <p class="cartao-sub">Dos reprocessados no período, quantos saíram da fila de erro.</p>
        <div class="medidores" id="medidores"></div>
    </section>

    <section class="cartao-grafico">
        <h2>Paradas em aberto</h2>
        <p class="cartao-sub">Registros parados em cada dia, do ano letivo atual em diante. A contagem é feita quando uma tela de acompanhamento é aberta.</p>
        <div class="legenda" id="linhas-legenda"></div>
        <div class="area-grafico" id="linhas"></div>
        <details><summary>Ver como tabela</summary><table id="linhas-tabela"></table></details>
    </section>

    <section class="cartao-grafico">
        <h2>Erros que mais voltam</h2>
        <p class="cartao-sub">Matrículas e reservas reprocessadas que estão com erro de novo, pelo resumo do <code>ds_erro</code>.</p>
        <div class="area-grafico" id="erros"></div>
    </section>
</div>

<p class="graficos-nota">
    Entram os reprocessamentos feitos pelo painel com o código do registro (linha das telas ou tela Commands) e o
    integrador em lote. O "Reprocessar todos" de PABs e de reservas não entra: sem código, esses commands escolhem
    sozinhos até 3 e 2 registros. Cada registro conta uma vez, no último reprocessamento.
</p>
</div>
@endsection

@push('scripts')
    <script>window.GRAFICOS = @json($dados);</script>
    <script src="{{ asset('js/graficos.js') }}?v={{ filemtime(public_path('js/graficos.js')) }}"></script>
@endpush
