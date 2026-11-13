<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sistema de matrículas
    |--------------------------------------------------------------------------
    | O painel não carrega o código do matrícula: ele só executa
    | "php artisan ..." dentro da pasta abaixo, como você faz no terminal.
    |
    | "php" é o binário usado para rodar o MATRÍCULA (não o painel). Se o
    | matrícula precisa de uma versão diferente da do painel, aponte para ela,
    | por exemplo: MATRICULA_PHP=/usr/bin/php7.4
    */
    'matricula' => [
        'caminho' => env('MATRICULA_PATH', '/home/werik/development/PRD/matricula'),
        'php' => env('MATRICULA_PHP', 'php'),
        'ambiente' => env('MATRICULA_AMBIENTE', 'PRD'),
    ],

    /*
    | Ambientes que podem ser escolhidos em Configurações. O escolhido
    | substitui a chave "matricula" acima em cada requisição (veja
    | App\Services\Configuracoes). O banco de cada um vem do .env da pasta.
    | Os caminhos abaixo são o padrão: a tela de Configurações pode trocá-los.
    */
    'ambientes' => [
        'PRD' => [
            'titulo' => 'Produção',
            'caminho' => env('MATRICULA_PATH_PRD', env('MATRICULA_PATH', '/home/werik/development/PRD/matricula')),
            'php' => env('MATRICULA_PHP', 'php'),
        ],
        'HML' => [
            'titulo' => 'Homologação',
            'caminho' => env('MATRICULA_PATH_HML', '/home/werik/development/matricula'),
            'php' => env('MATRICULA_PHP', 'php'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Commands
    |--------------------------------------------------------------------------
    | A lista de commands, títulos e parâmetros vem da tela "Dev > Commands"
    | do próprio matrícula (app/Http/Controllers/Dev/CommandsController.php).
    | Para liberar um command novo, adicione-o lá: o painel acompanha.
    |
    | Logs exibidos ao lado da execução, com caminho relativo à pasta do
    | matrícula. Com o driver "daily" o painel abre o arquivo do dia.
    */
    'logs' => [
        'laravel' => ['titulo' => 'Laravel (geral)', 'arquivo' => 'storage/logs/laravel.log'],
        'integrador' => ['titulo' => 'Integrador', 'arquivo' => 'storage/logs/integrador/integrador.log'],
        'integradorColegio' => ['titulo' => 'Integrador Colégio', 'arquivo' => 'storage/logs/integradorColegio/integradorColegio.log'],
        'integradorRematricula' => ['titulo' => 'Integrador Rematrícula', 'arquivo' => 'storage/logs/integradorRematricula/integradorRematricula.log'],
        'parcelaDao' => ['titulo' => 'Parcela DAO', 'arquivo' => 'storage/logs/parcelaDao/parcelaDao.log'],
        'resultadoAdmissao' => ['titulo' => 'Resultado Admissão', 'arquivo' => 'storage/logs/resultadoAdmissao/resultadoAdmissao.log'],
        'contratoDigital' => ['titulo' => 'Contrato Digital', 'arquivo' => 'storage/logs/contratoDigital/contratoDigital.log'],
        'corrigeTermoAditivo' => ['titulo' => 'Corrige Termo Aditivo', 'arquivo' => 'storage/logs/corrigeTermoAditivo/corrigeTermoAditivo.log'],
        'logExportacaoFotos' => ['titulo' => 'Exportação de Fotos', 'arquivo' => 'storage/logs/logExportacaoFotos.log'],
    ],

    /*
    |--------------------------------------------------------------------------
    | PABs parados
    |--------------------------------------------------------------------------
    | Anos letivos considerados na lista de PABs parados (situação 3 sem
    | boleto e sem contrato). PABs com erro (situação 11) entram sempre.
    */
    'pabs' => [
        'anos_letivos' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('PAINEL_PABS_ANOS', '2026,2027'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Matrículas paradas
    |--------------------------------------------------------------------------
    | Solicitações aguardando integração (14) ou com erro de integração (16)
    | a partir deste ano letivo e incluídas depois desta data.
    */
    'matriculas' => [
        'ano_minimo' => (int) env('PAINEL_MATRICULAS_ANO_MINIMO', 2026),
        'desde' => (string) env('PAINEL_MATRICULAS_DESDE', '2026-01-01 00:00:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reservas de vaga paradas
    |--------------------------------------------------------------------------
    | Reservas com erro, travadas em processamento, sem boleto ou aguardando
    | pagamento, a partir deste ano letivo.
    */
    'reservas' => [
        'ano_minimo' => (int) env('PAINEL_RESERVAS_ANO_MINIMO', 2026),
    ],

    /*
    |--------------------------------------------------------------------------
    | IP gravado em nu_ip_alteracao
    |--------------------------------------------------------------------------
    | Vazio: o painel descobre o IP desta máquina na VPN (rota do Windows até
    | o banco do matrícula). Preencha para fixar um valor.
    */
    'ip_vpn' => env('PAINEL_IP_VPN', ''),

    /*
    |--------------------------------------------------------------------------
    | Login com Microsoft Entra ID (Azure AD)
    |--------------------------------------------------------------------------
    | Com VALIDA_LOGIN_POR_MICROSOFT=false o painel fica aberto: ninguém
    | precisa entrar e as execuções são registradas como "acesso-livre".
    */
    'valida_login_microsoft' => filter_var(env('VALIDA_LOGIN_POR_MICROSOFT', false), FILTER_VALIDATE_BOOLEAN),

    'entra' => [
        'tenant' => env('ENTRA_TENANT_ID'),
        'client_id' => env('ENTRA_CLIENT_ID'),
        'client_secret' => env('ENTRA_CLIENT_SECRET'),
        'redirect' => env('ENTRA_REDIRECT_URI', rtrim((string) env('APP_URL', 'http://localhost:8010'), '/').'/auth/callback'),
    ],

    /*
    | Contas que podem entrar, separadas por vírgula. Lista vazia bloqueia
    | todo mundo: estar no AD da empresa não basta para acessar o painel.
    */
    'emails_permitidos' => array_values(array_filter(array_map(
        'trim',
        explode(',', strtolower((string) env('PAINEL_EMAILS_PERMITIDOS', '')))
    ))),

];
