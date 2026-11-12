@extends('layouts.base')

@section('titulo', 'Entrar')

@section('corpo')
<div class="entrada">
    <main class="entrada-form">
        <div class="marca">
            <span class="marca-selo" aria-hidden="true">&gt;_</span>
            <div class="marca-nome">Painel Matrícula</div>
        </div>

        <h1>Suas rotinas do matrícula em um só lugar</h1>
        <p>Entre com a conta Microsoft da empresa para executar commands e acompanhar a saída de cada um.</p>

        @if (session('erro'))
            <div class="aviso aviso-falha" role="alert">
                <h2>Não foi possível entrar</h2>
                <p>{{ session('erro') }}</p>
            </div>
        @elseif (! $configurado)
            <div class="aviso" role="status">
                <h2>Falta configurar o login</h2>
                <p>Preencha ENTRA_TENANT_ID, ENTRA_CLIENT_ID e ENTRA_CLIENT_SECRET no .env do painel. O passo a passo está no README.</p>
            </div>
        @endif

        <a class="botao botao-primario" href="{{ route('auth.redirect') }}">
            <svg class="ms" viewBox="0 0 21 21" aria-hidden="true"><path fill="#f25022" d="M1 1h9v9H1z"/><path fill="#7fba00" d="M11 1h9v9h-9z"/><path fill="#00a4ef" d="M1 11h9v9H1z"/><path fill="#ffb900" d="M11 11h9v9h-9z"/></svg>
            Entrar com a conta Microsoft
        </a>
    </main>

    <aside class="entrada-console" aria-hidden="true">
<pre><span class="fraco">$</span> php artisan list
<span class="fraco">  lendo os commands liberados…</span>
<span class="bom">  pronto</span>

<span class="fraco">$</span> <span class="cursor"></span></pre>
    </aside>
</div>
@endsection
