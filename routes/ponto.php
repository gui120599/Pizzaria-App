<?php

use App\Http\Controllers\PontoImpressaoController;
use App\Http\Controllers\PontoQuiosqueController;
use App\Livewire\Ponto\Quiosque;
use Illuminate\Support\Facades\Route;

/*
| Impressões do módulo de ponto (página com "Imprimir" / salvar em PDF, no
| mesmo padrão de resources/views/relatorios). Autorização no controller:
| recibo pela RepassePolicy (o colaborador imprime o próprio), espelho por
| colaborador (verEspelho).
*/
Route::middleware('auth')->prefix('ponto')->name('ponto.')->group(function () {
    Route::get('/repasses/{repasse}/recibo', [PontoImpressaoController::class, 'recibo'])->name('repasses.recibo');
    Route::get('/espelho/imprimir', [PontoImpressaoController::class, 'espelho'])->name('espelho.imprimir');
});

/*
| Quiosque (tablet da loja, sem login): autorizado pelo cookie de pareamento,
| conferido dentro do componente a cada requisição. O pareamento é limitado
| para não virar adivinhação de token.
*/
Route::prefix('ponto/quiosque')->name('ponto.')->group(function () {
    Route::get('/', Quiosque::class)->name('quiosque');
    Route::get('/parear/{token}', [PontoQuiosqueController::class, 'parear'])->middleware('throttle:10,1')->name('quiosque.parear');
});
