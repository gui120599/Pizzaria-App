<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Exceptions\VendaNaoFinalizavelException;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Models\Venda;
use App\Services\FinalizacaoVendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinalizacaoVendaServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinalizacaoVendaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->service = app(FinalizacaoVendaService::class);
    }

    private function sessaoCaixaAberta(float $saldoInicial = 100.00): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => $saldoInicial,
            'sessaocaixa_user_id' => auth()->id(),
        ]);
    }

    public function test_finaliza_venda_com_pagamento_exato_e_atualiza_saldo_do_caixa(): void
    {
        $sessaoCaixa = $this->sessaoCaixaAberta(100.00);
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 50.00,
            'venda_valor_pago' => 50.00,
            'venda_valor_troco' => 0,
        ]);

        $vendaFinalizada = $this->service->finalizar($venda);

        $this->assertSame('FINALIZADA', $vendaFinalizada->venda_status);
        $this->assertNotNull($vendaFinalizada->venda_datahora_finalizada);

        $this->assertSame(150.00, (float) $sessaoCaixa->fresh()->sessaocaixa_saldo_final);

        $this->assertDatabaseHas('movimentacoes_sessao_caixas', [
            'mov_sessaocaixa_id' => $sessaoCaixa->id,
            'mov_venda_id' => $venda->id,
            'mov_tipo' => 'ENTRADA',
        ]);
        $this->assertSame(50.00, (float) MovimentacoesSessaoCaixa::where('mov_venda_id', $venda->id)->first()->mov_valor);
    }

    public function test_finaliza_venda_com_troco_informado(): void
    {
        $sessaoCaixa = $this->sessaoCaixaAberta(0);
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 40.00,
            'venda_valor_pago' => 50.00,
            'venda_valor_troco' => 10.00,
        ]);

        $vendaFinalizada = $this->service->finalizar($venda);

        $this->assertSame('FINALIZADA', $vendaFinalizada->venda_status);
    }

    public function test_bloqueia_finalizacao_com_pagamento_insuficiente(): void
    {
        $sessaoCaixa = $this->sessaoCaixaAberta();
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 50.00,
            'venda_valor_pago' => 30.00,
            'venda_valor_troco' => 0,
        ]);

        $this->expectException(VendaNaoFinalizavelException::class);

        $this->service->finalizar($venda);

        $this->assertSame('INICIADA', $venda->fresh()->venda_status);
    }

    public function test_bloqueia_finalizacao_quando_recebido_excede_total_sem_troco_informado(): void
    {
        $sessaoCaixa = $this->sessaoCaixaAberta();
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 40.00,
            'venda_valor_pago' => 50.00,
            'venda_valor_troco' => 0,
        ]);

        $this->expectException(VendaNaoFinalizavelException::class);

        $this->service->finalizar($venda);
    }

    public function test_finaliza_venda_zerada_sem_exigir_pagamento(): void
    {
        $sessaoCaixa = $this->sessaoCaixaAberta();
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 0,
            'venda_valor_pago' => 0,
            'venda_valor_troco' => 0,
        ]);

        $vendaFinalizada = $this->service->finalizar($venda);

        $this->assertSame('FINALIZADA', $vendaFinalizada->venda_status);
    }

    public function test_resolve_cliente_ad_hoc_por_cpf_criando_novo_cliente(): void
    {
        $sessaoCaixa = $this->sessaoCaixaAberta();
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 0,
            'venda_valor_pago' => 0,
            'venda_valor_troco' => 0,
        ]);

        $vendaFinalizada = $this->service->finalizar($venda, [
            'cpf' => '123.456.789-00',
            'nome' => 'Cliente Balcão',
        ]);

        $this->assertNotNull($vendaFinalizada->venda_cliente_id);
        $cliente = Cliente::find($vendaFinalizada->venda_cliente_id);
        $this->assertSame('12345678900', $cliente->cliente_cpf);
        $this->assertSame('Cliente Balcão', $cliente->cliente_nome);
    }

    public function test_resolve_cliente_ad_hoc_reaproveitando_cliente_existente_pelo_cpf(): void
    {
        $clienteExistente = Cliente::create([
            'cliente_nome' => 'Já Cadastrado',
            'cliente_cpf' => '11122233344',
            'cliente_tipo' => 'Física',
        ]);

        $sessaoCaixa = $this->sessaoCaixaAberta();
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 0,
            'venda_valor_pago' => 0,
            'venda_valor_troco' => 0,
        ]);

        $vendaFinalizada = $this->service->finalizar($venda, ['cpf' => '111.222.333-44']);

        $this->assertSame($clienteExistente->id, $vendaFinalizada->venda_cliente_id);
        $this->assertSame(1, Cliente::count());
    }

    public function test_finalizar_lanca_validation_exception_quando_dados_do_cliente_ad_hoc_sao_invalidos(): void
    {
        $sessaoCaixa = $this->sessaoCaixaAberta();
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 0,
            'venda_valor_pago' => 0,
            'venda_valor_troco' => 0,
        ]);

        $this->expectException(ValidationException::class);

        // Sem cpf/cnpj, o nome vira o default; forçamos falha via email inválido.
        $this->service->finalizar($venda, ['cpf' => '999.888.777-66', 'email' => 'nao-e-um-email']);
    }

    public function test_finalizar_libera_mesa_e_finaliza_pedidos_vinculados_a_sessao(): void
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA']);
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);
        $mesa->update(['mesa_status' => 'OCUPADA', 'mesa_sessao_atual_id' => $sessaoMesa->id]);

        $pedido = Pedido::create([
            'pedido_sessao_mesa_id' => $sessaoMesa->id,
            'pedido_status' => 'ENTREGUE',
        ]);

        $sessaoCaixa = $this->sessaoCaixaAberta();
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 0,
            'venda_valor_pago' => 0,
            'venda_valor_troco' => 0,
        ]);

        $this->service->finalizar($venda, null, [$sessaoMesa->id]);

        $this->assertSame('FINALIZADA', $sessaoMesa->fresh()->sessao_mesa_status);
        $this->assertSame('LIBERADA', $mesa->fresh()->mesa_status);
        $this->assertNull($mesa->fresh()->mesa_sessao_atual_id);

        $pedidoFresh = $pedido->fresh();
        $this->assertSame('FINALIZADO', $pedidoFresh->pedido_status);
        $this->assertSame($venda->id, $pedidoFresh->pedido_venda_id);
    }

    public function test_finalizar_nao_libera_mesa_ja_ocupada_por_outra_sessao(): void
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA']);
        $sessaoAntiga = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'FECHADA',
        ]);
        $sessaoNova = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);
        // A mesa já foi reocupada por uma nova sessão antes desta venda ser finalizada.
        $mesa->update(['mesa_status' => 'OCUPADA', 'mesa_sessao_atual_id' => $sessaoNova->id]);

        $sessaoCaixa = $this->sessaoCaixaAberta();
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 0,
            'venda_valor_pago' => 0,
            'venda_valor_troco' => 0,
        ]);

        $this->service->finalizar($venda, null, [$sessaoAntiga->id]);

        $this->assertSame('FINALIZADA', $sessaoAntiga->fresh()->sessao_mesa_status);
        $this->assertSame('OCUPADA', $mesa->fresh()->mesa_status);
        $this->assertSame($sessaoNova->id, $mesa->fresh()->mesa_sessao_atual_id);
    }

    public function test_finalizar_pedido_avulso_individual(): void
    {
        $pedido = Pedido::create(['pedido_status' => 'EM TRANSPORTE']);

        $sessaoCaixa = $this->sessaoCaixaAberta();
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 0,
            'venda_valor_pago' => 0,
            'venda_valor_troco' => 0,
        ]);

        $this->service->finalizar($venda, null, [], [$pedido->id]);

        $pedidoFresh = $pedido->fresh();
        $this->assertSame('FINALIZADO', $pedidoFresh->pedido_status);
        $this->assertSame($venda->id, $pedidoFresh->pedido_venda_id);
    }

    public function test_cancelar_venda_libera_itens_de_pedido_vinculados(): void
    {
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
        ]);
        $pedido = Pedido::create(['pedido_status' => 'ABERTO']);
        $item = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => null,
        ]);

        $sessaoCaixa = $this->sessaoCaixaAberta();
        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
        ]);

        $item->update(['item_pedido_venda_id' => $venda->id]);

        $vendaCancelada = $this->service->cancelar($venda, MotivoCancelamentoEnum::CLIENTE_DESISTIU->value);

        $this->assertSame('CANCELADA', $vendaCancelada->venda_status);
        $this->assertSame(MotivoCancelamentoEnum::CLIENTE_DESISTIU, $vendaCancelada->venda_motivo_cancelamento);
        $this->assertNull($item->fresh()->item_pedido_venda_id);
    }
}
