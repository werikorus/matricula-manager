<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        // Aplica tema e menu salvos antes de desenhar a página (evita piscar).
        // Sem escolha salva, segue o modo claro/escuro do sistema.
        try {
            var tema = localStorage.getItem('painel.tema');
            if (tema === 'claro' || tema === 'escuro') document.documentElement.dataset.tema = tema;
            // Uma tela pode pedir a barra lateral recolhida ao abrir (seção "menu_inicial" da view).
            var menuInicial = @json(trim($__env->yieldContent('menu_inicial')));
            if (menuInicial === 'recolhido' || localStorage.getItem('painel.menu') === 'recolhido') document.documentElement.dataset.menu = 'recolhido';
        } catch (e) {}
    </script>
    <title>@yield('titulo', 'Painel') | Painel Matrícula</title>
    <link rel="icon" href="{{ asset('img/app/icone.svg') }}" type="image/svg+xml">
    <link rel="alternate icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('img/app/icone-apple-180.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="application-name" content="Painel Matrícula">
    <meta name="theme-color" content="#0e1430">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Schibsted+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/painel.css') }}?v={{ filemtime(public_path('css/painel.css')) }}">
</head>
<body>
    @yield('corpo')
    <script src="{{ asset('js/tema.js') }}?v={{ filemtime(public_path('js/tema.js')) }}"></script>
    @stack('scripts')
</body>
</html>
