<?php

use App\Http\Controllers\CaixaController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\ItensPedidoController;
use App\Http\Controllers\MovimentacaoProdutoController;
use App\Http\Controllers\OpcoesEntregasController;
use App\Http\Controllers\OpcoesPagamentoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\MesaController;
use App\Http\Controllers\PedidoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [Dashboard::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/Categoria', [CategoriaController::class, 'index'])->name('categoria');
    Route::get('/Categorias-Inativas', [CategoriaController::class, 'inactive'])->name('categoria.inactive');
    Route::post('/Categoria', [CategoriaController::class, 'store'])->name('categoria.store');
    Route::get('/Categoria/{categoria}', [CategoriaController::class, 'show'])->name('categoria.show');
    Route::get('/Categoria/{categoria}/Editar', [CategoriaController::class, 'edit'])->name('categoria.edit');
    Route::patch('/Categoria/{categoria}', [CategoriaController::class, 'update'])->name('categoria.update');
    Route::get('/Ativar-Categoria/{id}', [CategoriaController::class, 'active'])->name('categoria.active');
    Route::delete('/Categoria/{id}', [CategoriaController::class, 'destroy'])->name('categoria.destroy');

    Route::get('/Produto', [ProdutoController::class, 'index'])->name('produto');
    Route::get('/Produtos-Inativos', [ProdutoController::class, 'inactive'])->name('produto.inactive');
    Route::post('/Produto', [ProdutoController::class, 'store'])->name('produto.store');
    Route::get('/Produto/{produto}', [ProdutoController::class, 'show'])->name('produto.show');
    Route::get('/Produto/{produto}/Editar', [ProdutoController::class, 'edit'])->name('produto.edit');
    Route::patch('/Produto/{produto}', [ProdutoController::class, 'update'])->name('produto.update');
    Route::get('/Ativar-Produto/{id}', [ProdutoController::class, 'active'])->name('produto.active');
    Route::delete('/Produto/{id}', [ProdutoController::class, 'destroy'])->name('produto.destroy');

    Route::get('/Entrada-Produto', [MovimentacaoProdutoController::class, 'indexEntrada'])->name('entrada_produto');
    Route::post('/Entra-Produto', [MovimentacaoProdutoController::class, 'storeEntrada'])->name('mov_entrada.store');

    Route::get('/Cliente', [ClienteController::class, 'index'])->name('cliente');
    Route::get('/Clientes-Inativos', [ClienteController::class, 'inactive'])->name('cliente.inactive');
    Route::post('/Cliente', [ClienteController::class, 'store'])->name('cliente.store');
    Route::get('/Cliente/{cliente}', [ClienteController::class, 'show'])->name('cliente.show');
    Route::get('/Cliente/{cliente}/Editar', [ClienteController::class, 'edit'])->name('cliente.edit');
    Route::patch('/Cliente/{cliente}', [ClienteController::class, 'update'])->name('cliente.update');
    Route::get('/Ativar-Cliente/{id}', [ClienteController::class, 'active'])->name('cliente.active');
    Route::delete('/Cliente/{id}', [ClienteController::class, 'destroy'])->name('cliente.destroy');

    Route::get('/Caixa', [CaixaController::class, 'index'])->name('caixa');
    Route::get('/Caixas-Inativas', [CaixaController::class, 'inactive'])->name('caixa.inactive');
    Route::post('/Caixa', [CaixaController::class, 'store'])->name('caixa.store');
    Route::get('/Caixa/{caixa}', [CaixaController::class, 'show'])->name('caixa.show');
    Route::get('/Caixa/{caixa}/Editar', [CaixaController::class, 'edit'])->name('caixa.edit');
    Route::patch('/Caixa/{caixa}', [CaixaController::class, 'update'])->name('caixa.update');
    Route::get('/Ativar-Caixa/{id}', [CaixaController::class, 'active'])->name('caixa.active');
    Route::delete('/Caixa/{id}', [CaixaController::class, 'destroy'])->name('caixa.destroy');

    Route::get('/OpcoesPagamento', [OpcoesPagamentoController::class, 'index'])->name('opcoes_pagamento');
    Route::get('/OpcoesPagamentos-Inativas', [OpcoesPagamentoController::class, 'inactive'])->name('opcoes_pagamento.inactive');
    Route::post('/OpcoesPagamento', [OpcoesPagamentoController::class, 'store'])->name('opcoes_pagamento.store');
    Route::get('/OpcoesPagamento/{opcoes_pagamento}', [OpcoesPagamentoController::class, 'show'])->name('opcoes_pagamento.show');
    Route::get('/OpcoesPagamento/{opcoes_pagamento}/Editar', [OpcoesPagamentoController::class, 'edit'])->name('opcoes_pagamento.edit');
    Route::patch('/OpcoesPagamento/{opcoes_pagamento}', [OpcoesPagamentoController::class, 'update'])->name('opcoes_pagamento.update');
    Route::get('/Ativar-OpcoesPagamento/{id}', [OpcoesPagamentoController::class, 'active'])->name('opcoes_pagamento.active');
    Route::delete('/OpcoesPagamento/{id}', [OpcoesPagamentoController::class, 'destroy'])->name('opcoes_pagamento.destroy');

    Route::get('/OpcoesEntregas', [OpcoesEntregasController::class, 'index'])->name('opcoes_entregas');
    Route::get('/OpcoesEntregas-Inativas', [OpcoesEntregasController::class, 'inactive'])->name('opcoes_entregas.inactive');
    Route::post('/OpcoesEntregas', [OpcoesEntregasController::class, 'store'])->name('opcoes_entregas.store');
    Route::get('/OpcoesEntregas/{opcoes_entregas}', [OpcoesEntregasController::class, 'show'])->name('opcoes_entregas.show');
    Route::get('/OpcoesEntregas/{opcoes_entregas}/Editar', [OpcoesEntregasController::class, 'edit'])->name('opcoes_entregas.edit');
    Route::patch('/OpcoesEntregas/{opcoes_entregas}', [OpcoesEntregasController::class, 'update'])->name('opcoes_entregas.update');
    Route::get('/Ativar-OpcoesEntregas/{id}', [OpcoesEntregasController::class, 'active'])->name('opcoes_entregas.active');
    Route::delete('/OpcoesEntregas/{id}', [OpcoesEntregasController::class, 'destroy'])->name('opcoes_entregas.destroy');

    Route::get('/Mesa', [MesaController::class, 'index'])->name('mesa');
    Route::get('/Mesa-Inativas', [MesaController::class, 'inactive'])->name('mesa.inactive');
    Route::get('/Mesa/Create', [MesaController::class, 'create'])->name('mesa.create');
    Route::post('/Mesa', [MesaController::class, 'store'])->name('mesa.store');
    Route::get('/Mesa/{mesa}', [MesaController::class, 'show'])->name('mesa.show');
    Route::get('/Mesa/{mesa}/Edit', [MesaController::class, 'edit'])->name('mesa.edit');
    Route::patch('/Mesa/{mesa}', [MesaController::class, 'update'])->name('mesa.update');
    Route::delete('/Mesa/{mesa}', [MesaController::class, 'destroy'])->name('mesa.destroy');
    Route::get('/Ativar-Mesa/{id}', [MesaController::class, 'active'])->name('mesa.active');

    Route::get('/Pedido', [PedidoController::class, 'index'])->name('pedido');
    Route::get('/Pedido/Create', [PedidoController::class, 'create'])->name('pedido.create');
    Route::post('/Pedido', [PedidoController::class, 'store'])->name('pedido.store');
    Route::get('/Pedido/{pedido}', [PedidoController::class, 'show'])->name('pedido.show');
    Route::get('/Pedido/{pedido}/Edit', [PedidoController::class, 'edit'])->name('pedido.edit');
    Route::patch('/Pedido/{pedido}', [PedidoController::class, 'update'])->name('pedido.update');
    Route::delete('/Pedido/{pedido}', [PedidoController::class, 'destroy'])->name('pedido.destroy');
    Route::post('/iniciar-pedido', [PedidoController::class, 'iniciarPedido'])->name('pedido.iniciar');

    Route::post('/ItemPedido', [ItensPedidoController::class, 'store'])->name('item_pedido.store');
    Route::post('/ItemPedido/AtualizarQtdValor', [ItensPedidoController::class, 'AtualizarQtdValor'])->name('item_pedido.update_qtd_valor');
    Route::post('/ItemPedido/AtualizarObservacao', [ItensPedidoController::class, 'AtualizarObservacao'])->name('item_pedido.update_observacao');
    Route::post('/ItemPedido/RemoverItem', [ItensPedidoController::class, 'RemoverItem'])->name('item_pedido.remove');
    Route::get('/ItemPedido/ListarItensPedido', [ItensPedidoController::class, 'listarProdutosInseridosNoPedido'])->name('itens_pedido.lista');
    Route::get('/calcular-valor-total-pedido', [ItensPedidoController::class, 'calcularValorTotalPedido'])->name('calcular_valor_total_pedido');
});

require __DIR__ . '/auth.php';
