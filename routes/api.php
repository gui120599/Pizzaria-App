<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::post('/webhook/nfe-status', function(Request $request){
    //Recebe os dados
    $data = $request->all();

    //Log para depuração
    Log::info('Webhook recebido: ', $data);

    //Verifica se há um ID de nota fiscal válido
    if(!isset($data['id']) || !isset($data['status'])){
        return response()->json(['message' => 'Dados inválidos!']);
    }

    //Atualiza o status no banco de dados
    DB::table('vendas')->where('venda_id_nfe', $data['id'])->update(['venda_status_nfe' => $data['status']]);

    return response()->json(['message' => 'Atualizado com sucesso!']);
});

Route::apiResource('cardapio', \App\Http\Controllers\api\CardapioController::class);
Route::apiResource('categorias', \App\Http\Controllers\api\CategoriaController::class);
