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
        //
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
