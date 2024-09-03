<?php

use App\Http\Controllers\CaixaController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ItensVendaController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\ItensPedidoController;
use App\Http\Controllers\MovimentacaoProdutoController;
use App\Http\Controllers\OpcoesEntregasController;
use App\Http\Controllers\OpcoesPagamentoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\MesaController;
use App\Http\Controllers\PagamentosVendaController;
use App\Http\Controllers\PDFController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\SessaoCaixaController;
use App\Http\Controllers\SessaoMesaController;
use App\Http\Controllers\VendaController;
use App\Models\ItensVenda;
use App\Http\Controllers\EmpresaController;
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

Route::get('/Pedido/{id}/Imprimir', [PDFController::class, 'pedidoPDF'])->name('pedido.imprimir');
Route::get('/sessaoMesaPDF/{id}/Imprimir', [PDFController::class, 'sessaoMesaPDF'])->name('sessaoMesa.imprimir');


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


    Route::get('/Empresa', [EmpresaController::class, 'index'])->name('empresa');
    Route::get('/Empresas-Inativas', [EmpresaController::class, 'inactive'])->name('empresa.inactive');
    Route::post('/Empresa', [EmpresaController::class, 'store'])->name('empresa.store');
    Route::get('/Empresa/{empresa}', [EmpresaController::class, 'show'])->name('empresa.show');
    Route::get('/Empresa/{empresa}/Editar', [EmpresaController::class, 'edit'])->name('empresa.edit');
    Route::patch('/Empresa/{empresa}', [EmpresaController::class, 'update'])->name('empresa.update');
    Route::get('/Ativar-Empresa/{id}', [EmpresaController::class, 'active'])->name('empresa.active');
    Route::delete('/Empresa/{id}', [EmpresaController::class, 'destroy'])->name('empresa.destroy');


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

    Route::get('/SessaoCaixa', [SessaoCaixaController::class, 'index'])->name('sessao_caixa');
    Route::get('/SessaoCaixas-Inativas', [SessaoCaixaController::class, 'inactive'])->name('sessao_caixa.inactive');
    Route::post('/SessaoCaixa', [SessaoCaixaController::class, 'store'])->name('sessao_caixa.store');
    Route::get('/SessaoCaixa/{sessao_caixa}', [SessaoCaixaController::class, 'show'])->name('sessao_caixa.show');
    Route::get('/SessaoCaixa/{sessao_caixa}/Editar', [SessaoCaixaController::class, 'edit'])->name('sessao_caixa.edit');
    Route::patch('/SessaoCaixa/{sessao_caixa}', [SessaoCaixaController::class, 'update'])->name('sessao_caixa.update');
    Route::get('/Ativar-SessaoCaixa/{id}', [SessaoCaixaController::class, 'active'])->name('sessao_caixa.active');
    Route::delete('/SessaoCaixa/{id}', [SessaoCaixaController::class, 'destroy'])->name('sessao_caixa.destroy');
    Route::get('/SessaoCaixa/{sessao_caixa}/Vendas', [SessaoCaixaController::class, 'listarVendasSessaoCaixa'])->name('sessao_caixa.vendas');

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
    Route::get('/Pedidos-Abertos', [PedidoController::class, 'PedidosAbertos'])->name('pedidos.abertos');
    Route::get('/Pedidos-Abertos-Lista', [PedidoController::class, 'PedidosAbertosLista'])->name('pedidos_abertos.lista');
    Route::get('/Pedidos-Preparando-Lista', [PedidoController::class, 'PedidosPreparandoLista'])->name('pedidos_preparando.lista');
    Route::get('/Pedidos-Pronto-Lista', [PedidoController::class, 'PedidosProntoLista'])->name('pedidos_pronto.lista');
    Route::get('/Pedidos-Transporte-Lista', [PedidoController::class, 'PedidosEmTransporteLista'])->name('pedidos_transporte.lista');
    Route::get('/Pedidos-Entregue-Lista', [PedidoController::class, 'PedidosEntregueLista'])->name('pedidos_entregue.lista');
    Route::post('/Aceitar-Pedido', [PedidoController::class, 'AceitarPedido'])->name('aceitar_pedido');
    Route::post('/Avancar-Pedido-Pronto', [PedidoController::class, 'AvancarPedidoPronto'])->name('avancar_pedido_pronto');
    Route::post('/Avancar-Pedido-Transporte', [PedidoController::class, 'AvancarPedidoEmTransporte'])->name('avancar_pedido_transporte');
    Route::post('/Avancar-Pedido-Entregue', [PedidoController::class, 'AvancarPedidoEntregue'])->name('avancar_pedido_entregue');
    Route::post('/Pedido/{id}/Edit', [PedidoController::class, 'SalvarPedido'])->name('pedido.salvar_pedido');
    Route::post('/Pedido-Mesa/{id}/Edit', [PedidoController::class, 'SalvarPedidoMesa'])->name('pedido.salvar_pedido_mesa');
    Route::patch('/Pedido/{pedido}', [PedidoController::class, 'update'])->name('pedido.update');
    Route::delete('/Pedido/{pedido}', [PedidoController::class, 'destroy'])->name('pedido.destroy');
    Route::post('/iniciar-pedido', [PedidoController::class, 'iniciarPedido'])->name('pedido.iniciar');

    Route::post('/ItemPedido', [ItensPedidoController::class, 'store'])->name('item_pedido.store');
    Route::post('/ItemPedido/AtualizarQtdValor', [ItensPedidoController::class, 'AtualizarQtdValor'])->name('item_pedido.update_qtd_valor');
    Route::post('/ItemPedido/AtualizarObservacao', [ItensPedidoController::class, 'AtualizarObservacao'])->name('item_pedido.update_observacao');
    Route::post('/ItemPedido/AtualizarDesconto', [ItensPedidoController::class, 'AtualizarDesconto'])->name('item_pedido.update_desconto');
    Route::post('/ItemPedido/RemoverItem', [ItensPedidoController::class, 'RemoverItem'])->name('item_pedido.remove');
    Route::get('/ItemPedido/ListarItensPedido', [ItensPedidoController::class, 'listarProdutosInseridosNoPedido'])->name('itens_pedido.lista');
    Route::get('/calcular-valor-total-pedido', [ItensPedidoController::class, 'calcularValorTotalPedido'])->name('calcular_valor_total_pedido');

    Route::get('/SessaoMesa/{mesa_id}/Abrir-Sessao', [SessaoMesaController::class, 'index'])->name('sessaoMesa');
    Route::get('/SessaoMesa/{mesa_id}/Pedido-Mesa', [SessaoMesaController::class, 'PedidoMesa'])->name('sessaoMesa.pedidoMesa');
    Route::get('/SessaoMesa/{item_pedido_id}/{pedido_id}/Remover-Item-Pedido', [SessaoMesaController::class, 'RemoverItemPedidoMesa'])->name('removerItemPedidoMesa');
    Route::get('/SessaoMesa-Inativas', [SessaoMesaController::class, 'inactive'])->name('sessaoMesa.inactive');
    Route::get('/SessaoMesa/Create', [SessaoMesaController::class, 'create'])->name('sessaoMesa.create');
    Route::post('/Abrir-SessaoMesa', [SessaoMesaController::class, 'AbrirSessaoMesa'])->name('sessaoMesa.abrir');
    Route::get('/SessaoMesa/{sessaoMesa}', [SessaoMesaController::class, 'show'])->name('sessaoMesa.show');
    Route::get('/SessaoMesa/{sessaoMesa}/Edit', [SessaoMesaController::class, 'edit'])->name('sessaoMesa.edit');
    Route::patch('/SessaoMesa/{sessaoMesa}', [SessaoMesaController::class, 'update'])->name('sessaoMesa.update');
    Route::delete('/SessaoMesa/{sessaoMesa}', [SessaoMesaController::class, 'destroy'])->name('sessaoMesa.destroy');
    Route::get('/Ativar-SessaoMesa/{id}', [SessaoMesaController::class, 'active'])->name('sessaoMesa.active');

    Route::get('/Venda', [VendaController::class, 'index'])->name('venda');
    Route::get('/Venda-listar', [VendaController::class, 'ListarVenda'])->name('venda.listar');
    Route::post('/Venda', [VendaController::class, 'store'])->name('venda.store');
    Route::post('/Venda-cancelar', [VendaController::class, 'cancelarVenda'])->name('venda.cancelar');
    Route::post('/iniciar-venda', [VendaController::class, 'iniciarVenda'])->name('venda.iniciar');
    Route::post('/Venda/{id}/Edit', [VendaController::class, 'SalvarVenda'])->name('venda.salvar_venda');
    Route::post('/Venda//EditValorFrete', [VendaController::class, 'AtualizarValorFrete'])->name('venda.update_valor_frete');
    Route::get('AtualizarValoresdaVenda', [VendaController::class, 'AtualizarValoresdaVenda']);
    Route::get('/Venda/{id}/Gerar-NFE', [VendaController::class, 'enviarNfe'])->name('venda.gerar_NFE');
    Route::get('/Venda/{venda}/Imprimir-NFE', [VendaController::class, 'imprimirNFE'])->name('venda.imprimir_NFE');
    Route::get('/Venda/{venda}/Buscar-NFE', [VendaController::class, 'buscarNFE'])->name('venda.buscar_NFE');

    Route::post('/ItemVenda/AddSessaoMesa', [ItensVendaController::class, 'adicionarItensSessaoMesa'])->name('item_venda.add_item_sessaoMesa');
    Route::post('/ItemVenda/RemoveSessaoMesa', [ItensVendaController::class, 'removerItensSessaoMesa'])->name('item_venda.remove_item_sessaoMesa');
    Route::post('/ItemVenda/AddPedido', [ItensVendaController::class, 'adicionarItensPedido'])->name('item_venda.add_item_pedido');
    Route::post('/ItemVenda/RemovePedido', [ItensVendaController::class, 'removerItensPedido'])->name('item_venda.remove_item_pedido');
    Route::post('/ItemVenda/AddProduto', [ItensVendaController::class, 'adicionarProduto'])->name('item_venda.add_produto');
    Route::post('/ItemVenda/RemoveProduto', [ItensVendaController::class, 'removerProduto'])->name('item_venda.remove_produto');
    Route::post('/ItemVenda/AtualziarDesconto', [ItensVendaController::class, 'atualizarDescontoItemVenda'])->name('item_venda.update_desconto');

    Route::post('/PagamentoVenda', [PagamentosVendaController::class, 'store'])->name('pagamento_venda.store');
    Route::post('/RemoverPagamentoVenda', [PagamentosVendaController::class, 'destroy'])->name('pagamento_venda.destroy');


    Route::get('/ItemVenda', [ItensVendaController::class, 'index'])->name('item_venda.add_item_pedido2');
    Route::get('/ItemVenda2', [ItensVendaController::class, 'listarItensVenda'])->name('item_venda.listar');

    Route::post('/render-toast', function () {
        return response()->json([
            'html' => view('app.components.toast')->render(),
        ]);
    })->name('render.toast');

});

require __DIR__ . '/auth.php';
