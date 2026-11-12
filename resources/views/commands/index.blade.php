@extends('layouts.app')

@section('titulo', 'Commands')

@section('conteudo')
<header class="topo">
    <h1>Commands</h1>
    <div class="topo-meio">
        <span class="ambiente" title="Os commands rodam nesta pasta do matrícula">
            <strong>{{ $ambiente }}</strong>
            <code>{{ $caminho }}</code>
        </span>
    </div>
</header>

@if ($erro)
    <div class="aviso aviso-falha" role="alert">
        <h2>Não consegui ler os commands do matrícula</h2>
        <p>O painel usa a mesma lista da tela "Dev &gt; Commands" do matrícula. Corrija o problema abaixo e recarregue a página.</p>
        <pre>{{ $erro }}</pre>
    </div>
@elseif (count($comandos) === 0)
    <div class="aviso">
        <h2>Nenhum command na tela do matrícula</h2>
        <p>Os commands vêm do método <code>commands()</code> em <code>{{ \App\Services\CatalogoDoMatricula::ARQUIVO }}</code> do matrícula.</p>
    </div>
@endif

@if (count($comandos))
<div class="bancada">
    {{-- Esquerda: commands. O selecionado abre os parâmetros ali mesmo. --}}
    <section class="painel-comandos" aria-labelledby="lista-titulo">
        <header class="painel-comandos-topo">
            <h2 id="lista-titulo">Commands <span class="contagem">{{ count($comandos) }}</span></h2>
            <input type="search" class="lista-busca" id="lista-busca" placeholder="Filtrar por nome ou descrição" aria-label="Filtrar commands" autocomplete="off" spellcheck="false">
        </header>
        <ul class="lista" id="lista"></ul>
        <p class="vazio" id="lista-vazia" hidden>Nenhum command encontrado.</p>

        <form class="comando-form" id="form-comando" hidden novalidate>
            <p class="comando-form-descricao" id="comando-descricao"></p>
            <div class="campos" id="campos"></div>
            <div class="acoes">
                <button type="submit" class="botao botao-primario" id="botao-executar">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>
                    Executar
                </button>
                <span class="acoes-erro" id="form-erro" role="alert"></span>
            </div>
        </form>
    </section>

    {{-- Direita: saída da execução e logs do matrícula. --}}
    <div class="terminais">
        <section class="console" id="console" data-status="vazio" aria-label="Saída da execução">
            <header class="console-topo">
                <span class="console-status" id="console-status" role="status">Nenhuma execução aberta</span>
                <code class="console-linha" id="console-linha"></code>
                <span class="console-tempo" id="console-tempo"></span>
                <button type="button" class="botao botao-console" id="console-parar" hidden>Parar</button>
            </header>
            <pre class="console-saida" id="console-saida" tabindex="0" data-vazio="Escolha um command à esquerda e execute: a saída aparece aqui, ao vivo."></pre>
        </section>

        <section class="console logs" aria-labelledby="logs-titulo">
            <header class="console-topo">
                <h2 class="logs-titulo" id="logs-titulo">Logs</h2>
                <code class="console-linha" id="logs-arquivo">—</code>
                <select class="seletor-console" id="logs-fonte" aria-label="Arquivo de log">
                    @foreach ($logs as $chave => $titulo)
                        <option value="{{ $chave }}">{{ $titulo }}</option>
                    @endforeach
                </select>
                <select class="seletor-console" id="logs-linhas" aria-label="Quantidade de linhas">
                    <option value="100">100 linhas</option>
                    <option value="200" selected>200 linhas</option>
                    <option value="500">500 linhas</option>
                    <option value="1000">1000 linhas</option>
                </select>
                <button type="button" class="botao botao-console botao-icone" id="logs-auto" aria-pressed="true" title="Atualização automática (3 s): ligada">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.6-6.4M21 4v5h-5"/></svg>
                    <span class="sr">Atualização automática</span>
                </button>
                <button type="button" class="botao botao-console botao-icone" id="logs-fim" title="Ir para o final">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg>
                    <span class="sr">Ir para o final</span>
                </button>
            </header>
            <pre class="console-saida logs-saida" id="logs-saida" tabindex="0"></pre>
        </section>
    </div>
</div>

<section class="historico" aria-labelledby="historico-titulo">
    <div class="historico-topo">
        <h2 id="historico-titulo">Últimas execuções</h2>
        <span class="historico-nota">Clique em uma linha para rever a saída.</span>
    </div>
    <div class="folha">
        <p class="vazio" id="historico-vazio">Nada foi executado pelo painel ainda.</p>
        <div class="tabela-rolagem" id="historico-tabela" hidden>
            <table>
                <thead>
                    <tr><th>Situação</th><th>Command</th><th>Início</th><th>Duração</th><th>Código de saída</th></tr>
                </thead>
                <tbody id="historico-corpo"></tbody>
            </table>
        </div>
    </div>
</section>

<dialog id="confirmar" aria-labelledby="confirmar-titulo">
    <form method="dialog" class="dialogo">
        <h2 id="confirmar-titulo">Executar em {{ $ambiente }}?</h2>
        <p>Este command roda no matrícula de {{ $ambiente }} sem pedir confirmações no meio do caminho (<code>--no-interaction</code>).</p>
        <pre id="confirmar-linha"></pre>
        <div class="dialogo-acoes">
            <button type="submit" class="botao" value="cancelar">Cancelar</button>
            <button type="submit" class="botao botao-primario" value="executar">Executar command</button>
        </div>
    </form>
</dialog>

@push('scripts')
    <script>window.PAINEL = @json($painel);</script>
    <script src="{{ asset('js/painel.js') }}?v={{ filemtime(public_path('js/painel.js')) }}"></script>
@endpush
@endif
@endsection
