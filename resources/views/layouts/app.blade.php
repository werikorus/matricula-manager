@extends('layouts.base')

@section('corpo')
<div class="app">
    @php
        $itensMenu = \App\Support\Telas::menu();
        $nomeUsuario = (string) session('usuario.nome');
        $iniciais = collect(preg_split('/\s+/', trim($nomeUsuario)) ?: [])
            ->filter()->take(2)->map(function ($parte) { return mb_strtoupper(mb_substr($parte, 0, 1)); })->implode('') ?: '?';
    @endphp
    <aside class="lateral" id="lateral" aria-label="Barra lateral">
        <div class="marca">
            <span class="marca-selo" aria-hidden="true">&gt;_</span>
            <div class="marca-textos">
                <div class="marca-nome">Painel Matrícula</div>
                <div class="marca-sub">Rotinas do dia</div>
            </div>
        </div>

        <nav class="menu" aria-label="Menu principal">
            @foreach ($itensMenu as $item)
                <a href="{{ route($item['rota']) }}" data-carregando="{{ $item['carregando'] }}" data-dica="{{ $item['texto'] }}"
                   @if (request()->routeIs($item['rota'])) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $item['icone'] !!}</svg>
                    <span class="menu-texto">{{ $item['texto'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="lateral-rodape">
            <button type="button" class="botao botao-discreto botao-lateral" data-alternar-menu aria-controls="lateral" aria-expanded="true" data-dica="Recolher menu (Ctrl+B)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/><path class="seta-menu" d="M15.5 10 13.5 12l2 2"/></svg>
                <span class="menu-texto" data-menu-texto>Recolher menu</span>
            </button>

            <button type="button" class="botao botao-discreto botao-lateral botao-tema" data-alternar-tema aria-label="Alternar tema" data-dica="Tema escuro">
                <svg class="icone-sol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                <svg class="icone-lua" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                <span class="menu-texto botao-tema-texto" data-tema-texto>Tema escuro</span>
            </button>

            <div class="conta" data-dica="{{ $nomeUsuario }} · {{ session('usuario.email') }}">
                <span class="conta-avatar" aria-hidden="true">{{ $iniciais }}</span>
                <div class="conta-dados">
                    <div class="conta-nome">{{ $nomeUsuario }}</div>
                    <div class="conta-email">{{ session('usuario.email') }}</div>
                </div>
                @if (config('painel.valida_login_microsoft'))
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="botao botao-discreto botao-sair" aria-label="Sair" data-dica="Sair">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </aside>

    <main class="principal">
        @yield('conteudo')
    </main>
</div>
@include('components.carregando')

@push('scripts')
    <script src="{{ asset('js/carregando.js') }}?v={{ filemtime(public_path('js/carregando.js')) }}"></script>
    <script src="{{ asset('js/menu.js') }}?v={{ filemtime(public_path('js/menu.js')) }}"></script>
@endpush
@endsection
