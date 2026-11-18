<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Uma conexão por requisição: telas que consultam por mais de um serviço
        // (ex.: lista + HistoricoDeParadas) não abrem um segundo login no banco.
        $this->app->singleton(\App\Services\BancoDoMatricula::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Ambiente do matrícula escolhido em Configurações (Produção/Homologação).
        $this->app->make(\App\Services\Configuracoes::class)->aplicar();
    }
}
