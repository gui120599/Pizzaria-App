<?php

use App\Http\Controllers\Compras\AnexoComprovanteController;
use Illuminate\Support\Facades\Route;

/*
| Comprovante da compra avulsa (Fase 5): link assinado de 24 h gerado pelo
| confirmar_compra_avulsa, aberto no celular sem login; a imagem no painel
| exige login e permissão de ver a compra.
*/
Route::middleware('signed')->group(function () {
    Route::get('/compras/{compra}/comprovante', [AnexoComprovanteController::class, 'formulario'])->name('compras.anexo.formulario');
    Route::post('/compras/{compra}/comprovante', [AnexoComprovanteController::class, 'enviar'])->name('compras.anexo.enviar');
});

Route::get('/compras/anexos/{anexo}', [AnexoComprovanteController::class, 'ver'])
    ->middleware('auth')
    ->name('compras.anexo.ver');
