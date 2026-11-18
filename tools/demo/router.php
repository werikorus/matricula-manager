<?php

/*
 * Instância de DEMONSTRAÇÃO do painel: mesma aplicação, mas com
 *  - banco do matrícula trocado por dados fictícios (Demo\BancoFalso);
 *  - catálogo de commands fictício (tools/demo/matricula);
 *  - storage próprio (tools/demo/storage), sem tocar no histórico real;
 *  - nenhum command executado e nenhuma escrita no banco.
 *
 * Uso: bash tools/demo/subir.sh   (veja docs/claude/demonstracao.md)
 */

$painel = dirname(__DIR__, 2);
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($caminho !== '/' && is_file($painel.'/public'.$caminho)) {
    return false;
}

// Só leitura: um POST (Executar, Reprocessar, Salvar…) dispararia o executor
// do painel real (artisan painel:executar), fora deste storage.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Demonstração: só leitura. Nada é executado nem gravado.'], JSON_UNESCAPED_UNICODE);

    return true;
}

foreach ([
    'MATRICULA_PATH' => __DIR__.'/matricula', 'MATRICULA_PATH_PRD' => __DIR__.'/matricula',
    'MATRICULA_PATH_HML' => __DIR__.'/matricula', 'MATRICULA_AMBIENTE' => 'PRD',
    'VALIDA_LOGIN_POR_MICROSOFT' => 'false', 'APP_DEBUG' => 'true',
    'CACHE_DRIVER' => 'file', 'SESSION_DRIVER' => 'file', 'PAINEL_IP_VPN' => '10.0.0.1',
] as $chave => $valor) {
    putenv("{$chave}={$valor}");
    $_ENV[$chave] = $valor;
    $_SERVER[$chave] = $valor;
}

require $painel.'/vendor/autoload.php';
require __DIR__.'/fakes.php';

$app = require $painel.'/bootstrap/app.php';
$app->useStoragePath(__DIR__.'/storage');
// Os fakes entram DEPOIS dos providers: o AppServiceProvider registra o
// BancoDoMatricula real como singleton e, antes disso, sobrescreveria o fake.
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class, function ($app) {
    $app->singleton(App\Services\BancoDoMatricula::class, function () {
        return new Demo\BancoFalso;
    });
    $app->singleton(App\Services\MatriculaArtisan::class, function () {
        return new Demo\ArtisanFalso;
    });
    $app->bind(App\Services\EditorDeSolicitacao::class, function ($app) {
        return new Demo\EditorFalso($app->make(App\Services\BancoDoMatricula::class), $app->make(App\Services\IpDaVpn::class));
    });
});
$app->booted(function () use ($app) {
    if (! $app->make(App\Services\BancoDoMatricula::class) instanceof Demo\BancoFalso) {
        throw new RuntimeException('Demonstração: o banco falso não foi aplicado. Nada foi consultado.');
    }
    // Caminho exibido nas telas (o real é a pasta tools/demo/matricula).
    config(['painel.matricula.caminho' => Demo\ArtisanFalso::EXIBIDO]);
});

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request = Illuminate\Http\Request::capture())->send();
$kernel->terminate($request, $response);
