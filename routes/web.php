<?php

use App\Http\Controllers\AlunoController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommandController;
use App\Http\Controllers\ExecucaoController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MatriculaController;
use App\Http\Controllers\PabController;
use App\Http\Controllers\ReservaController;
use App\Http\Controllers\SolicitacaoController;
use App\Http\Middleware\Autenticado;
use App\Http\Middleware\RegistrarAcesso;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'login'])->name('login');
Route::get('/auth/redirect', [AuthController::class, 'redirecionar'])->name('auth.redirect');
Route::get('/auth/callback', [AuthController::class, 'callback'])->name('auth.callback');

Route::middleware([Autenticado::class, RegistrarAcesso::class])->group(function () {
    Route::get('/', [InicioController::class, 'index'])->name('inicio');

    Route::get('/commands', [CommandController::class, 'index'])->name('commands');
    Route::get('/logs', LogController::class)->name('logs');

    Route::get('/alunos', [AlunoController::class, 'index'])->name('alunos');

    Route::get('/pabs', [PabController::class, 'index'])->name('pabs');
    Route::post('/pabs/reprocessar-todos', [PabController::class, 'reprocessarTodos'])->name('pabs.reprocessar-todos');

    Route::get('/matriculas', [MatriculaController::class, 'index'])->name('matriculas');
    Route::post('/matriculas/reprocessar-todos', [MatriculaController::class, 'reprocessarTodos'])->name('matriculas.reprocessar-todos');
    Route::post('/matriculas/{codigo}/reprocessar', [MatriculaController::class, 'reprocessar'])->where('codigo', '[0-9]+')->name('matriculas.reprocessar');

    Route::get('/solicitacoes/{codigo}', [SolicitacaoController::class, 'editar'])->where('codigo', '[0-9]+')->name('solicitacoes.editar');
    Route::post('/solicitacoes/{codigo}', [SolicitacaoController::class, 'salvar'])->where('codigo', '[0-9]+')->name('solicitacoes.salvar');

    Route::get('/reservas', [ReservaController::class, 'index'])->name('reservas');
    Route::post('/reservas/processar', [ReservaController::class, 'processar'])->name('reservas.processar');

    Route::get('/execucoes', [ExecucaoController::class, 'index'])->name('execucoes.index');
    Route::post('/execucoes', [ExecucaoController::class, 'store'])->name('execucoes.store');
    Route::get('/execucoes/{id}/saida', [ExecucaoController::class, 'saida'])->where('id', '[0-9]+')->name('execucoes.saida');
    Route::post('/execucoes/{id}/parar', [ExecucaoController::class, 'parar'])->where('id', '[0-9]+')->name('execucoes.parar');

    Route::post('/logout', [AuthController::class, 'sair'])->name('logout');
});
