<?php

use App\Http\Controllers\AdicionaisItemPedidoController;
use App\Http\Controllers\AdicionaisProdutoController;
use App\Http\Controllers\AdicionalController;
use App\Http\Controllers\CaixaController;
use App\Http\Controllers\CardapioCheckoutController;
use App\Http\Controllers\CardapioController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\EntregaScanController;
use App\Http\Controllers\ItensPedidoController;
use App\Http\Controllers\ItensVendaController;
use App\Http\Controllers\MesaController;
use App\Http\Controllers\MovimentacoesSessaoCaixaController;
use App\Http\Controllers\NfeVendaController;
use App\Http\Controllers\NotaFiscalController;
use App\Http\Controllers\OpcoesEntregasController;
use App\Http\Controllers\OpcoesPagamentoController;
use App\Http\Controllers\PagamentosVendaController;
use App\Http\Controllers\PDFController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RelatorioContasPagarReceberController;
use App\Http\Controllers\SessaoCaixaController;
use App\Http\Controllers\SessaoMesaController;
use App\Http\Controllers\VendaCancelarVaziaController;
use App\Http\Controllers\VendaController;
use Illuminate\Support\Facades\Auth;
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
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('cardapio');
});

Route::get('/Cardapio', [CardapioController::class, 'index'])->name('cardapio');
Route::get('/cardapio/lookup-cliente', [CardapioCheckoutController::class, 'lookupCliente'])->name('cardapio.lookup_cliente');
Route::post('/cardapio/checkout', [CardapioCheckoutController::class, 'checkout'])->name('cardapio.checkout');
Route::get('/acompanhar/{id}', fn ($id) => view('app.acompanhamento.index', ['pedidoId' => (int) $id]))->name('pedido.acompanhar');
Route::get('/Produto/{produto}', [ProdutoController::class, 'show'])->name('produto.show');

Route::get('/dashboard', [Dashboard::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

// Scan de QR code do ticket: assinatura garante que só quem escaneou o QR
// (impresso ou exibido no painel) acessa — 'signed' roda depois de 'auth'
// pra reaproveitar o redirect->intended() do login se o entregador ainda
// não estiver logado nesse celular.
Route::get('/Entregador/Scan/{pedido}', [EntregaScanController::class, 'show'])->name('entregador.scan')->middleware(['auth', 'signed', 'role:Entregador']);
Route::post('/Entregador/Scan/{pedido}', [EntregaScanController::class, 'confirmar'])->name('entregador.scan.confirmar')->middleware(['auth', 'role:Entregador']);

// Impressão do ticket de pedido fica fora do grupo auth de propósito: o teste
// PedidoPDFQrCodeTest confirma que essa rota é acessada sem sessão (impressão
// na cozinha/balcão do ticket com QR code que o entregador escaneia depois —
// ver rota entregador.scan). As outras 4 rotas de PDF não tinham esse uso
// comprovado, então ficaram atrás de auth.
Route::get('/Pedido/{id}/Imprimir', [PDFController::class, 'pedidoPDF'])->name('pedido.imprimir');

Route::middleware('auth')->group(function () {
    Route::get('/sessaoMesaPDF/{id}/Imprimir', [PDFController::class, 'sessaoMesaPDF'])->name('sessaoMesa.imprimir')->middleware('permission:view:sessao_mesa');
    Route::get('/sessaoCaixaPDF/{id}/Imprimir', [PDFController::class, 'sessaoCaixaPDF'])->name('sessaoCaixa.imprimir');
    Route::get('/LancamentoFiado/{id}/Imprimir', [PDFController::class, 'vendaPendentePDF'])->name('lancamento.imprimir_fiado');
    Route::get('/pedidosEntreguesFinalizadosCanceladosPDF/{datahora_abertura}/Imprimir', [PDFController::class, 'pedidosEntreguesFinalizadosCanceladosPDF'])->name('pedidosEntreguesFinalizadosCanceladosPDF.imprimir');
    Route::get('/pedidosEntregasPDF/{datahora_abertura}/Imprimir', [PDFController::class, 'pedidosEntregasPDF'])->name('pedidosEntregasPDF.imprimir');
    Route::get('/relatorios/contas-pagar-receber/imprimir', [RelatorioContasPagarReceberController::class, 'imprimir'])->name('relatorios.contas_pagar_receber.imprimir')->middleware('permission:view:relatorio_financeiro');
    Route::get('/relatorios/contas-pagar-receber/pdf', [RelatorioContasPagarReceberController::class, 'pdf'])->name('relatorios.contas_pagar_receber.pdf')->middleware('permission:view:relatorio_financeiro');

    // accept:pedido: mesma permission de quem confirma/rejeita pedido do cardápio
    // (Gerente/Atendente hoje) — bloqueia Entregador e também Caixa, que nunca
    // teve essa ação de fato (só não era barrado antes por falta de checagem).
    Route::get('/confirmacoes', fn () => view('app.confirmacoes.index'))->name('confirmacoes')->middleware('permission:accept:pedido');
    Route::get('/Entregador', fn () => view('app.entregador.index'))->name('entregador.painel')->middleware('role:Entregador');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/Categoria', [CategoriaController::class, 'index'])->name('categoria');
    Route::get('/Categorias-Inativas', [CategoriaController::class, 'inactive'])->name('categoria.inactive');
    Route::post('/Categoria', [CategoriaController::class, 'store'])->name('categoria.store')->middleware('permission:create:categoria');
    Route::get('/Categoria/{categoria}', [CategoriaController::class, 'show'])->name('categoria.show');
    Route::get('/Categoria/{categoria}/Editar', [CategoriaController::class, 'edit'])->name('categoria.edit');
    Route::patch('/Categoria/{categoria}', [CategoriaController::class, 'update'])->name('categoria.update')->middleware('permission:update:categoria');
    Route::get('/Ativar-Categoria/{id}', [CategoriaController::class, 'active'])->name('categoria.active')->middleware('permission:update:categoria');
    Route::delete('/Categoria/{id}', [CategoriaController::class, 'destroy'])->name('categoria.destroy')->middleware('permission:delete:categoria');

    Route::get('/Empresa', [EmpresaController::class, 'index'])->name('empresa');
    Route::get('/Empresas-Inativas', [EmpresaController::class, 'inactive'])->name('empresa.inactive');
    Route::post('/Empresa', [EmpresaController::class, 'store'])->name('empresa.store')->middleware('permission:create:empresa');
    Route::get('/Empresa/{empresa}', [EmpresaController::class, 'show'])->name('empresa.show');
    Route::get('/Empresa/{empresa}/Editar', [EmpresaController::class, 'edit'])->name('empresa.edit');
    Route::patch('/Empresa/{empresa}', [EmpresaController::class, 'update'])->name('empresa.update')->middleware('permission:update:empresa');
    Route::get('/Ativar-Empresa/{id}', [EmpresaController::class, 'active'])->name('empresa.active')->middleware('permission:update:empresa');
    Route::delete('/Empresa/{id}', [EmpresaController::class, 'destroy'])->name('empresa.destroy')->middleware('permission:delete:empresa');

    Route::get('/Produto', [ProdutoController::class, 'index'])->name('produto');
    Route::get('/Produtos-Inativos', [ProdutoController::class, 'inactive'])->name('produto.inactive');
    Route::post('/Produto', [ProdutoController::class, 'store'])->name('produto.store')->middleware('permission:create:produto');
    Route::get('/Produto/{produto}/Editar', [ProdutoController::class, 'edit'])->name('produto.edit');
    Route::patch('/Produto/{produto}', [ProdutoController::class, 'update'])->name('produto.update')->middleware('permission:update:produto');
    Route::get('/Ativar-Produto/{id}', [ProdutoController::class, 'active'])->name('produto.active')->middleware('permission:update:produto');
    Route::delete('/Produto/{id}', [ProdutoController::class, 'destroy'])->name('produto.destroy')->middleware('permission:delete:produto');
    Route::post('/produto/adicional/toggle', [ProdutoController::class, 'toggleAdicional'])->name('produto_adicional')->middleware('permission:update:produto');

    Route::get('/Cliente', [ClienteController::class, 'index'])->name('cliente');
    Route::get('/Clientes-Inativos', [ClienteController::class, 'inactive'])->name('cliente.inactive');
    Route::post('/Cliente', [ClienteController::class, 'store'])->name('cliente.store')->middleware('permission:create:cliente');
    Route::get('/Cliente/{cliente}', [ClienteController::class, 'show'])->name('cliente.show');
    Route::get('/Cliente/{cliente}/Editar', [ClienteController::class, 'edit'])->name('cliente.edit');
    Route::patch('/Cliente/{cliente}', [ClienteController::class, 'update'])->name('cliente.update')->middleware('permission:update:cliente');
    Route::get('/Ativar-Cliente/{id}', [ClienteController::class, 'active'])->name('cliente.active')->middleware('permission:update:cliente');
    Route::delete('/Cliente/{id}', [ClienteController::class, 'destroy'])->name('cliente.destroy')->middleware('permission:delete:cliente');

    Route::get('/Caixa', [CaixaController::class, 'index'])->name('caixa');
    Route::get('/Caixas-Inativas', [CaixaController::class, 'inactive'])->name('caixa.inactive');
    Route::post('/Caixa', [CaixaController::class, 'store'])->name('caixa.store')->middleware('permission:create:caixa');
    Route::get('/Caixa/{caixa}', [CaixaController::class, 'show'])->name('caixa.show');
    Route::get('/Caixa/{caixa}/Editar', [CaixaController::class, 'edit'])->name('caixa.edit');
    Route::patch('/Caixa/{caixa}', [CaixaController::class, 'update'])->name('caixa.update')->middleware('permission:update:caixa');
    Route::get('/Ativar-Caixa/{id}', [CaixaController::class, 'active'])->name('caixa.active')->middleware('permission:update:caixa');
    Route::delete('/Caixa/{id}', [CaixaController::class, 'destroy'])->name('caixa.destroy')->middleware('permission:delete:caixa');

    Route::get('/Adicional', [AdicionalController::class, 'index'])->name('adicional');
    Route::get('/Adicionais-Inativos', [AdicionalController::class, 'inactive'])->name('adicional.inactive');
    Route::post('/Adicional', [AdicionalController::class, 'store'])->name('adicional.store')->middleware('permission:create:adicional');
    Route::get('/Adicional/{adicional}', [AdicionalController::class, 'show'])->name('adicional.show');
    Route::get('/Adicional/{adicional}/Editar', [AdicionalController::class, 'edit'])->name('adicional.edit');
    Route::patch('/Adicional/{adicional}', [AdicionalController::class, 'update'])->name('adicional.update')->middleware('permission:update:adicional');
    Route::get('/Ativar-Adicional/{id}', [AdicionalController::class, 'active'])->name('adicional.active')->middleware('permission:update:adicional');
    Route::delete('/Adicional/{id}', [AdicionalController::class, 'destroy'])->name('adicional.destroy')->middleware('permission:delete:adicional');

    Route::post('/AdicionalProduto', [AdicionaisProdutoController::class, 'ativar'])->name('adicional_produto.ativar')->middleware('permission:update:produto');

    // role: (não permission:) porque RoleMiddleware não passa pelo Gate — precisa
    // listar Admin explicitamente, o bypass via super_admin não se aplica aqui.
    Route::get('/SessaoCaixa', [SessaoCaixaController::class, 'index'])->name('sessao_caixa')->middleware('role:Admin|Gerente|Caixa');
    Route::get('/SessaoCaixas-Inativas', [SessaoCaixaController::class, 'inactive'])->name('sessao_caixa.inactive')->middleware('role:Admin|Gerente|Caixa');
    Route::post('/SessaoCaixa', [SessaoCaixaController::class, 'store'])->name('sessao_caixa.store')->middleware('role:Admin|Gerente|Caixa');
    Route::get('/SessaoCaixa/{sessao_caixa}/Finalizar', [SessaoCaixaController::class, 'finalizar'])->name('sessao_caixa.finalizar')->middleware('role:Admin|Gerente|Caixa');
    Route::get('/SessaoCaixa/{sessao_caixa}/Editar', [SessaoCaixaController::class, 'edit'])->name('sessao_caixa.edit')->middleware('role:Admin|Gerente|Caixa');
    Route::patch('/SessaoCaixa/{sessao_caixa}', [SessaoCaixaController::class, 'update'])->name('sessao_caixa.update')->middleware('role:Admin|Gerente|Caixa');
    Route::get('/Ativar-SessaoCaixa/{id}', [SessaoCaixaController::class, 'active'])->name('sessao_caixa.active')->middleware('role:Admin|Gerente|Caixa');
    // Só Admin: excluir sessão de caixa quebra trilha de auditoria financeira (ver SessaoCaixaPolicy::delete).
    Route::delete('/SessaoCaixa/{id}', [SessaoCaixaController::class, 'destroy'])->name('sessao_caixa.destroy')->middleware('role:Admin');
    Route::get('/SessaoCaixa/{sessao_caixa}/Vendas', [SessaoCaixaController::class, 'listarVendasSessaoCaixa'])->name('sessao_caixa.vendas')->middleware('role:Admin|Gerente|Caixa');

    Route::get('/OpcoesPagamento', [OpcoesPagamentoController::class, 'index'])->name('opcoes_pagamento');
    Route::get('/OpcoesPagamentos-Inativas', [OpcoesPagamentoController::class, 'inactive'])->name('opcoes_pagamento.inactive');
    Route::post('/OpcoesPagamento', [OpcoesPagamentoController::class, 'store'])->name('opcoes_pagamento.store')->middleware('permission:create:opcoes_pagamento');
    Route::get('/OpcoesPagamento/{opcoes_pagamento}', [OpcoesPagamentoController::class, 'show'])->name('opcoes_pagamento.show');
    Route::get('/OpcoesPagamento/{opcoes_pagamento}/Editar', [OpcoesPagamentoController::class, 'edit'])->name('opcoes_pagamento.edit');
    Route::patch('/OpcoesPagamento/{opcoes_pagamento}', [OpcoesPagamentoController::class, 'update'])->name('opcoes_pagamento.update')->middleware('permission:update:opcoes_pagamento');
    Route::get('/Ativar-OpcoesPagamento/{id}', [OpcoesPagamentoController::class, 'active'])->name('opcoes_pagamento.active')->middleware('permission:update:opcoes_pagamento');
    Route::delete('/OpcoesPagamento/{id}', [OpcoesPagamentoController::class, 'destroy'])->name('opcoes_pagamento.destroy')->middleware('permission:delete:opcoes_pagamento');

    Route::get('/OpcoesEntregas', [OpcoesEntregasController::class, 'index'])->name('opcoes_entregas');
    Route::get('/OpcoesEntregas-Inativas', [OpcoesEntregasController::class, 'inactive'])->name('opcoes_entregas.inactive');
    Route::post('/OpcoesEntregas', [OpcoesEntregasController::class, 'store'])->name('opcoes_entregas.store')->middleware('permission:create:opcoes_entregas');
    Route::get('/OpcoesEntregas/{opcoes_entregas}', [OpcoesEntregasController::class, 'show'])->name('opcoes_entregas.show');
    Route::get('/OpcoesEntregas/{opcoes_entregas}/Editar', [OpcoesEntregasController::class, 'edit'])->name('opcoes_entregas.edit');
    Route::patch('/OpcoesEntregas/{opcoes_entregas}', [OpcoesEntregasController::class, 'update'])->name('opcoes_entregas.update')->middleware('permission:update:opcoes_entregas');
    Route::get('/Ativar-OpcoesEntregas/{id}', [OpcoesEntregasController::class, 'active'])->name('opcoes_entregas.active')->middleware('permission:update:opcoes_entregas');
    Route::delete('/OpcoesEntregas/{id}', [OpcoesEntregasController::class, 'destroy'])->name('opcoes_entregas.destroy')->middleware('permission:delete:opcoes_entregas');

    Route::get('/Mesa', [MesaController::class, 'index'])->name('mesa');
    Route::get('/Mesa-Inativas', [MesaController::class, 'inactive'])->name('mesa.inactive');
    Route::get('/Mesa/Create', [MesaController::class, 'create'])->name('mesa.create');
    Route::post('/Mesa', [MesaController::class, 'store'])->name('mesa.store')->middleware('permission:create:mesa');
    Route::get('/Mesa/{mesa}', [MesaController::class, 'show'])->name('mesa.show');
    Route::get('/Mesa/{mesa}/Edit', [MesaController::class, 'edit'])->name('mesa.edit');
    Route::patch('/Mesa/{mesa}', [MesaController::class, 'update'])->name('mesa.update')->middleware('permission:update:mesa');
    Route::delete('/Mesa/{mesa}', [MesaController::class, 'destroy'])->name('mesa.destroy')->middleware('permission:delete:mesa');
    Route::get('/Ativar-Mesa/{id}', [MesaController::class, 'active'])->name('mesa.active')->middleware('permission:update:mesa');

    Route::get('/Pedido', [PedidoController::class, 'index'])->name('pedido');
    Route::get('/Pedido-relatorio', [PedidoController::class, 'relatorio'])->name('pedido.relatorio')->middleware('permission:view:relatorio_financeiro');
    Route::get('/Pedido-relatorio-pdf', [PedidoController::class, 'relatorioLista'])->name('pedido.relatorioPDF')->middleware('permission:view:relatorio_financeiro');
    Route::get('/Pedidos', [PedidoController::class, 'list'])->name('pedidos');
    Route::get('/Pedido/Create', [PedidoController::class, 'create'])->name('pedido.create')->middleware('permission:create:pedido');
    Route::get('/Clientes/Buscar-Nome', [CardapioCheckoutController::class, 'buscarClientesPorNome'])->name('cliente.buscar_nome');
    Route::post('/Pedido', [PedidoController::class, 'store'])->name('pedido.store')->middleware('permission:update:pedido');
    Route::get('/Pedido/{pedido}', [PedidoController::class, 'show'])->name('pedido.show');
    // permission:view_any:pedido bloqueia o Entregador (não tem essa permission
    // hoje) — a página e os feeds AJAX do Kanban de pedidos abertos.
    Route::get('/Pedidos-Abertos', [PedidoController::class, 'PedidosAbertos'])->name('pedidos.abertos')->middleware('permission:view_any:pedido');
    Route::get('/Pedidos-Abertos-Lista', [PedidoController::class, 'PedidosAbertosLista'])->name('pedidos_abertos.lista')->middleware('permission:view_any:pedido');
    Route::get('/Pedidos-Preparando-Lista', [PedidoController::class, 'PedidosPreparandoLista'])->name('pedidos_preparando.lista')->middleware('permission:view_any:pedido');
    Route::get('/Pedidos-Pronto-Lista', [PedidoController::class, 'PedidosProntoLista'])->name('pedidos_pronto.lista')->middleware('permission:view_any:pedido');
    Route::get('/Pedidos-Transporte-Lista', [PedidoController::class, 'PedidosEmTransporteLista'])->name('pedidos_transporte.lista')->middleware('permission:view_any:pedido');
    Route::get('/Pedidos-Entregue-Lista', [PedidoController::class, 'PedidosEntregueLista'])->name('pedidos_entregue.lista')->middleware('permission:view_any:pedido');
    // permission: (Gate-routed) respeita o bypass de Admin; a ownership fina
    // (ex: só o garçom dono cancela) fica no $this->authorize() do controller.
    Route::post('/Aceitar-Pedido', [PedidoController::class, 'AceitarPedido'])->name('aceitar_pedido')->middleware('permission:accept:pedido');
    Route::post('/Rejeitar-Pedido', [PedidoController::class, 'RejeitarPedido'])->name('rejeitar_pedido')->middleware('permission:reject:pedido');
    Route::post('/Cancelar-Pedido/{id}', [PedidoController::class, 'CancelarPedido'])->name('pedido.cancelar')->middleware('permission:cancel:pedido');
    Route::post('/Restaurar-Pedido/{id}', [PedidoController::class, 'RestaurarPedido'])->name('pedido.restaurar')->middleware('permission:cancel:pedido');
    Route::post('/Avancar-Pedido-Pronto', [PedidoController::class, 'AvancarPedidoPronto'])->name('avancar_pedido_pronto')->middleware('permission:advance:pedido');
    Route::post('/Avancar-Pedido-Transporte', [PedidoController::class, 'AvancarPedidoEmTransporte'])->name('avancar_pedido_transporte')->middleware('permission:advance:pedido');
    Route::post('/Avancar-Pedido-Entregue', [PedidoController::class, 'AvancarPedidoEntregue'])->name('avancar_pedido_entregue')->middleware('permission:advance:pedido');
    Route::post('/Pedido/{id}/Edit', [PedidoController::class, 'SalvarPedido'])->name('pedido.salvar_pedido')->middleware('permission:update:pedido');
    Route::post('/Pedido-Mesa/{id}/Edit', [PedidoController::class, 'SalvarPedidoMesa'])->name('pedido.salvar_pedido_mesa')->middleware('permission:update:pedido');
    Route::get('/Pedido/{id}/Editar', [PedidoController::class, 'editarPedido'])->name('pedido.editar')->middleware('permission:update:pedido');
    Route::patch('/Pedido/{id}/Editar', [PedidoController::class, 'salvarEdicaoPedido'])->name('pedido.salvar_edicao')->middleware('permission:update:pedido');
    Route::patch('/Pedido/{pedido}', [PedidoController::class, 'update'])->name('pedido.update')->middleware('permission:update:pedido');
    Route::delete('/Pedido/{pedido}', [PedidoController::class, 'destroy'])->name('pedido.destroy')->middleware('permission:delete:pedido');
    Route::post('/iniciar-pedido', [PedidoController::class, 'iniciarPedido'])->name('pedido.iniciar')->middleware('permission:create:pedido');

    Route::post('/ItemPedido', [ItensPedidoController::class, 'store'])->name('item_pedido.store')->middleware('permission:update:pedido');
    Route::post('/ItemPedido/AtualizarQtdValor', [ItensPedidoController::class, 'AtualizarQtdValor'])->name('item_pedido.update_qtd_valor')->middleware('permission:update:pedido');
    Route::post('/ItemPedido/AtualizarObservacao', [ItensPedidoController::class, 'AtualizarObservacao'])->name('item_pedido.update_observacao')->middleware('permission:update:pedido');
    Route::post('/ItemPedido/AtualizarDesconto', [ItensPedidoController::class, 'AtualizarDesconto'])->name('item_pedido.update_desconto')->middleware('permission:update:pedido');
    Route::post('/ItemPedido/RemoverItem', [ItensPedidoController::class, 'RemoverItem'])->name('item_pedido.remove')->middleware('permission:update:pedido');
    Route::get('/ItemPedido/ListarItensPedido', [ItensPedidoController::class, 'listarProdutosInseridosNoPedido'])->name('itens_pedido.lista')->middleware('permission:update:pedido');
    Route::get('/calcular-valor-total-pedido', [ItensPedidoController::class, 'calcularValorTotalPedido'])->name('calcular_valor_total_pedido')->middleware('permission:update:pedido');

    Route::post('AdicionalItemPedido', [AdicionaisItemPedidoController::class, 'store'])->name('adicional_item_pedido.store');
    Route::get('ListarAdicionaisItemPedido', [AdicionaisItemPedidoController::class, 'ListarAdicioanis'])->name('listar.adicionais_item_pedido');

    // view:sessao_mesa bloqueia o módulo inteiro pro Entregador (não tem essa
    // permission hoje) — nova permission, Gerente/Atendente/Caixa recebida via
    // PermissionSeeder. Ações mais sensíveis (update/delete) continuam com
    // checagem fina via SessaoMesaPolicy nos controllers.
    Route::middleware('permission:view:sessao_mesa')->group(function () {
        Route::get('/SessaoMesa/{mesa_id}/Abrir-Sessao', [SessaoMesaController::class, 'index'])->name('sessaoMesa');
        Route::get('/SessaoMesa/{mesa_id}/Pedidos', [SessaoMesaController::class, 'PedidosMesa'])->name('sessaoMesa.pedidosMesa');
        Route::get('/SessaoMesa/{mesa_id}/Realizar-Pedido', [SessaoMesaController::class, 'PedidoMesa'])->name('sessaoMesa.pedidoMesa');
        Route::post('/SessaoMesa/{mesa_id}/Salvar-Novo-Pedido', [SessaoMesaController::class, 'salvarNovoPedidoMesa'])->name('sessaoMesa.salvarNovoPedidoMesa');
        Route::get('/SessaoMesa/{mesa_id}/Pedido/{pedido}/Editar', [SessaoMesaController::class, 'editarPedidoMesa'])->name('sessaoMesa.editarPedido');
        Route::patch('/SessaoMesa/{mesa_id}/Pedido/{pedido}/Salvar-Edicao', [SessaoMesaController::class, 'salvarEdicaoPedidoMesa'])->name('sessaoMesa.salvarEdicaoPedido');
        Route::get('/SessaoMesa/{item_pedido_id}/{pedido_id}/Remover-Item-Pedido', [SessaoMesaController::class, 'RemoverItemPedidoMesa'])->name('removerItemPedidoMesa');
        Route::get('/SessaoMesa-Inativas', [SessaoMesaController::class, 'inactive'])->name('sessaoMesa.inactive');
        Route::get('/SessaoMesa/Create', [SessaoMesaController::class, 'create'])->name('sessaoMesa.create');
        Route::post('/Abrir-SessaoMesa', [SessaoMesaController::class, 'AbrirSessaoMesa'])->name('sessaoMesa.abrir');
        Route::get('/Fechar-SessaoMesa/{sessaoMesa}', [SessaoMesaController::class, 'FecharSessaoMesa'])->name('sessaoMesa.fechar');
        Route::get('/Reabrir-SessaoMesa/{sessaoMesa}', [SessaoMesaController::class, 'ReabrirSessaoMesa'])->name('sessaoMesa.reabrir');
        Route::get('/SessaoMesa/{sessaoMesa}/Selecionar-Mesa', [SessaoMesaController::class, 'editAlterarMesaSessaMesa'])->name('sessaoMesa.editAlterarMesa');
        Route::patch('/SessaoMesa/{sessaoMesa}/Alterar-Mesa', [SessaoMesaController::class, 'updateAlterarMesaSessaMesa'])->name('sessao_mesa.updateAlterarMesa');
        Route::patch('/SessaoMesa/{sessaoMesa}/Adicona-pedidos-existentes', [SessaoMesaController::class, 'updateAdicionarPedidosExistentes'])->name('sessaoMesa.updateAdicionarExistentes');
        Route::post('/SessaoMesa/{sessaoMesa}/Adicionar-Clientes', [SessaoMesaController::class, 'adicionarClientesSessao'])->name('sessaoMesa.adicionarClientes');
        Route::delete('/SessaoMesa/{sessaoMesa}/Remover-Cliente/{sessaoMesaCliente}', [SessaoMesaController::class, 'removerClienteSessao'])->name('sessaoMesa.removerCliente');
        Route::patch('/SessaoMesa/{sessaoMesa}/Remover-pedidos-mesa', [SessaoMesaController::class, 'updateRemoverPedidosSessaoMesa'])->name('sessaoMesa.updateRemoverPedidosSessaoMesa');
        Route::get('/SessaoMesa/{sessaoMesa}', [SessaoMesaController::class, 'show'])->name('sessaoMesa.show');
        Route::get('/SessaoMesa/{sessaoMesa}/Edit', [SessaoMesaController::class, 'edit'])->name('sessaoMesa.edit');
        Route::patch('/SessaoMesa/{sessaoMesa}', [SessaoMesaController::class, 'update'])->name('sessaoMesa.update');
        Route::delete('/SessaoMesa/{sessaoMesa}', [SessaoMesaController::class, 'destroy'])->name('sessaoMesa.destroy');
        Route::get('/Ativar-SessaoMesa/{id}', [SessaoMesaController::class, 'active'])->name('sessaoMesa.active');
    });

    // operar:venda cobre o fluxo normal do PDV (abrir, montar, finalizar venda);
    // cancel:venda e emitir:nfe ficam restritos a quem também fecha caixa
    // (Gerente/Caixa) — ver PermissionSeeder.
    Route::get('/Venda/{venda}/editar', [VendaController::class, 'edit'])->name('venda.edit')->middleware('permission:operar:venda');
    Route::get('/Venda', [VendaController::class, 'index'])->name('venda')->middleware('permission:operar:venda');
    Route::get('/Venda-listar', [VendaController::class, 'ListarVenda'])->name('venda.listar')->middleware('permission:operar:venda');
    Route::post('/Venda', [VendaController::class, 'store'])->name('venda.store')->middleware('permission:operar:venda');
    Route::post('/Venda-cancelar', [VendaController::class, 'cancelarVenda'])->name('venda.cancelar')->middleware('permission:cancel:venda');
    Route::post('/Venda/{venda}/cancelar', [VendaController::class, 'cancelarVendaWeb'])->name('venda.cancelar_web')->middleware('permission:cancel:venda');
    Route::post('/iniciar-venda', [VendaController::class, 'iniciarVenda'])->name('venda.iniciar')->middleware('permission:operar:venda');
    Route::post('/Venda/{id}/Edit', [VendaController::class, 'SalvarVenda'])->name('venda.salvar_venda')->middleware('permission:operar:venda');
    Route::post('/Venda//EditValorFrete', [VendaController::class, 'AtualizarValorFrete'])->name('venda.update_valor_frete')->middleware('permission:operar:venda');
    Route::get('AtualizarValoresdaVenda', [VendaController::class, 'AtualizarValoresdaVenda'])->middleware('permission:operar:venda');
    Route::get('/Venda/{id}/Gerar-NFE', [NfeVendaController::class, 'enviarNfe'])->name('venda.gerar_NFE')->middleware('permission:emitir:nfe');
    Route::get('/Venda/{id}/Gerar-JSONNFE', [NfeVendaController::class, 'jsonNFE'])->name('venda.gerar_JSONNFE')->middleware('permission:emitir:nfe');
    Route::get('/Venda/{id_nfe}/Imprimir-NFE', [NfeVendaController::class, 'imprimirNFE'])->name('venda.imprimir_NFE')->middleware('permission:emitir:nfe');
    Route::get('/Venda/{venda}/Buscar-NFE', [NfeVendaController::class, 'buscarNFE'])->name('venda.buscar_NFE')->middleware('permission:emitir:nfe');
    Route::get('/Venda/remover/{vendaId}/{idNfe}', [NfeVendaController::class, 'removerIdNfe'])->name('venda.removerIdNfe')->middleware('permission:emitir:nfe');
    Route::get('/Venda/relatorio-vendas-mensal', [VendaController::class, 'showVendasMensal'])->name('venda.relatorioMensal')->middleware('permission:view:relatorio_financeiro');
    Route::get('/Venda/vendasMensalPDF/Imprimir', [VendaController::class, 'listarVendasMensal'])->name('vendasMensal.imprimir')->middleware('permission:view:relatorio_financeiro');

    // Beacon do PDV (OperarVenda): cancela a venda ao fechar a aba se ainda
    // estiver vazia (sendBeacon não consegue disparar uma ação Livewire).
    Route::post('/Venda/{venda}/cancelar-vazia', VendaCancelarVaziaController::class)->name('venda.cancelar_vazia')->middleware('permission:operar:venda');

    // Mesmo grupo de roles da SessaoCaixa (RoleMiddleware não passa pelo Gate,
    // precisa listar Admin explicitamente) — saída de caixa é operação financeira.
    Route::get('/Saidas', [MovimentacoesSessaoCaixaController::class, 'index'])->name('mov_saida')->middleware('role:Admin|Gerente|Caixa');
    Route::post('/Saidas', [MovimentacoesSessaoCaixaController::class, 'store'])->name('mov_saida.store')->middleware('role:Admin|Gerente|Caixa');
    Route::get('/Saidas/{id}', [MovimentacoesSessaoCaixaController::class, 'edit'])->name('mov_saida.edit')->middleware('role:Admin|Gerente|Caixa');
    Route::patch('/Saidas/{id}/Editar', [MovimentacoesSessaoCaixaController::class, 'update'])->name('mov_saida.update')->middleware('role:Admin|Gerente|Caixa');
    Route::delete('/Saidas-cancelar/{id}', [MovimentacoesSessaoCaixaController::class, 'destroy'])->name('mov_saida.destroy')->middleware('role:Admin|Gerente|Caixa');

    Route::post('/ItemVenda/AddSessaoMesa', [ItensVendaController::class, 'adicionarItensSessaoMesa'])->name('item_venda.add_item_sessaoMesa')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/AddSessaoMesaPorCliente', [ItensVendaController::class, 'adicionarItensSessaoMesaPorCliente'])->name('item_venda.add_sessaomesa_por_cliente')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/AddPorSelecao', [ItensVendaController::class, 'adicionarItensPorSelecao'])->name('item_venda.add_por_selecao')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/RemoveSessaoMesa', [ItensVendaController::class, 'removerItensSessaoMesa'])->name('item_venda.remove_item_sessaoMesa')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/AddPedido', [ItensVendaController::class, 'adicionarItensPedido'])->name('item_venda.add_item_pedido')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/RemovePedido', [ItensVendaController::class, 'removerItensPedido'])->name('item_venda.remove_item_pedido')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/AddProduto', [ItensVendaController::class, 'adicionarProduto'])->name('item_venda.add_produto')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/RemoveProduto', [ItensVendaController::class, 'removerProduto'])->name('item_venda.remove_produto')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/AtualziarDesconto', [ItensVendaController::class, 'atualizarDescontoItemVenda'])->name('item_venda.update_desconto')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/AplicarDescontoPercentual', [ItensVendaController::class, 'aplicarDescontoPercentualVenda'])->name('item_venda.aplicar_desconto_percentual')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/DesfazerDescontoPercentual', [ItensVendaController::class, 'desfazerDescontoPercentualVenda'])->name('item_venda.desfazer_desconto_percentual')->middleware('permission:operar:venda');
    Route::post('/ItemVenda/AtualizarQtdValor', [ItensVendaController::class, 'atualizarQtdValorItemVenda'])->name('item_venda.update_qtd_valor')->middleware('permission:operar:venda');

    Route::post('/PagamentoVenda', [PagamentosVendaController::class, 'store'])->name('pagamento_venda.store')->middleware('permission:operar:venda');
    Route::post('/RemoverPagamentoVenda', [PagamentosVendaController::class, 'destroy'])->name('pagamento_venda.destroy')->middleware('permission:cancel:venda');

    Route::get('/ItemVenda', [ItensVendaController::class, 'index'])->name('item_venda.add_item_pedido2')->middleware('permission:operar:venda');
    Route::get('/ItemVenda2', [ItensVendaController::class, 'listarItensVenda'])->name('item_venda.listar')->middleware('permission:operar:venda');

    Route::get('/Nota-Fiscal', [NotaFiscalController::class, 'index'])->name('nota_fiscal')->middleware('permission:view_any:nota_fiscal');
    Route::get('/Nota-Fiscal/{id}/Eventos', [NotaFiscalController::class, 'Eventos'])->name('nota_fiscal.eventos')->middleware('permission:view:nota_fiscal');

    Route::post('/render-toast', function () {
        return response()->json([
            'html' => view('app.components.toast')->render(),
        ]);
    })->name('render.toast');
});

require __DIR__.'/auth.php';
