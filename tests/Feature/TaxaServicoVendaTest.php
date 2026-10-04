<?php

namespace Tests\Feature;

use App\Enums\AcaoAutorizadaEnum;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\OperarVenda;
use App\Models\AutorizacaoGerente;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Models\Venda;
use App\Services\NfeIoService;
use App\Services\SessaoMesaService;
use App\Services\TaxaServicoVendaService;
use App\Services\VendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * "Tirar taxa de serviço" no caixa (OperarVenda): flag na venda, com a mesma
 * autorização por PIN de gerente do Painel do Garçom.
 */
class TaxaServicoVendaTest extends TestCase
{
    use RefreshDatabase;

    private User $caixa;

    private SessaoMesa $sessao;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pizzaria.salao.taxa_servico_percentual' => 10, 'pizzaria.salao.taxa_servico_padrao_ligada' => true]);

        // Usuário do caixa sem remover_taxa:sessao_mesa: precisa do PIN de gerente.
        Permission::firstOrCreate(['name' => 'operar:venda', 'guard_name' => 'web']);
        $this->caixa = User::factory()->caixa()->create(['name_first' => 'Caixa']);
        $this->caixa->givePermissionTo('operar:venda');
        $this->actingAs($this->caixa);

        SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => $this->caixa->id,
        ]);

        $mesa = Mesa::create(['mesa_nome' => 'Mesa 7', 'mesa_status' => 'LIBERADA']);
        $this->sessao = app(SessaoMesaService::class)->abrir($mesa->id, $this->caixa->id);
        $this->rodada(50.00);
    }

    private function rodada(float $valor): void
    {
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $valor,
        ]);
        $pedido = Pedido::create(['pedido_sessao_mesa_id' => $this->sessao->id, 'pedido_status' => 'ENTREGUE', 'pedido_valor_total' => $valor]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $valor,
            'item_pedido_valor' => $valor,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    private function vendaComMesaLancada(): Venda
    {
        Livewire::test(OperarVenda::class)->call('lancarItensDaMesa', $this->sessao->id);

        return Venda::sole();
    }

    public function test_caixa_tira_a_taxa_com_pin_de_gerente_e_a_auditoria_fica_na_venda(): void
    {
        $gerente = User::factory()->gerente()->comPin('4321')->create(['name_first' => 'Gerente']);
        $venda = $this->vendaComMesaLancada();
        $this->assertEquals(55.00, $venda->venda_valor_total);

        Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->callAction('removerTaxaServico', data: ['motivo' => 'Cliente reclamou', 'autorizador_id' => $gerente->id, 'pin' => '4321'])
            ->assertNotified('Taxa de serviço retirada.');

        $venda->refresh();
        $this->assertTrue($venda->venda_taxa_servico_removida);
        $this->assertEquals(0, $venda->venda_valor_taxa_servico);
        $this->assertEquals(50.00, $venda->venda_valor_total);
        $this->assertDatabaseHas(AutorizacaoGerente::class, [
            'autorizacao_acao' => AcaoAutorizadaEnum::REMOVER_TAXA_SERVICO->value,
            'autorizacao_solicitante_id' => $this->caixa->id,
            'autorizacao_autorizador_id' => $gerente->id,
            'autorizacao_auditavel_type' => $venda->getMorphClass(),
            'autorizacao_auditavel_id' => $venda->id,
            'autorizacao_motivo' => 'Cliente reclamou',
        ]);
    }

    public function test_pin_errado_mantem_a_taxa_e_nada_e_gravado(): void
    {
        $gerente = User::factory()->gerente()->comPin('4321')->create(['name_first' => 'Gerente']);
        $venda = $this->vendaComMesaLancada();

        Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->callAction('removerTaxaServico', data: ['autorizador_id' => $gerente->id, 'pin' => '0000']);

        $venda->refresh();
        $this->assertFalse($venda->venda_taxa_servico_removida);
        $this->assertEquals(55.00, $venda->venda_valor_total);
        $this->assertDatabaseCount(AutorizacaoGerente::class, 0);
    }

    public function test_recalculo_da_venda_nao_traz_a_taxa_de_volta_e_incluir_restaura(): void
    {
        $venda = $this->vendaComMesaLancada();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        app(TaxaServicoVendaService::class)->remover($venda, $gerente, null, null, null);

        $this->rodada(30.00);
        $component = Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->call('lancarItensDaMesa', $this->sessao->id);

        $this->assertEquals(80.00, $venda->fresh()->venda_valor_total);

        $component->call('restaurarTaxaServico');

        $this->assertFalse($venda->fresh()->venda_taxa_servico_removida);
        $this->assertEquals(88.00, $venda->fresh()->venda_valor_total);
    }

    public function test_nao_tira_a_taxa_se_o_pago_passaria_do_total(): void
    {
        $venda = $this->vendaComMesaLancada();
        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_valor_pagamento' => 55.00,
            'pg_venda_valor_pago_pelo_cliente' => 55.00,
        ]);
        app(VendaService::class)->atualizarValoresdaVenda($venda->id);

        $this->expectException(RuntimeException::class);

        try {
            app(TaxaServicoVendaService::class)->remover($venda, User::factory()->gerente()->create(['name_first' => 'Gerente']), null, null, null);
        } finally {
            $this->assertFalse($venda->fresh()->venda_taxa_servico_removida);
        }
    }

    public function test_nfce_sem_a_taxa_quando_o_caixa_tirou(): void
    {
        $venda = $this->vendaComMesaLancada();
        app(TaxaServicoVendaService::class)->remover($venda, User::factory()->gerente()->create(['name_first' => 'Gerente']), null, null, null);

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);

        Http::preventStrayRequests();
        Http::fake(['api.nfse.io/*' => Http::response(['id' => 'inv-1', 'status' => 'Processing'])]);

        app(NfeIoService::class)->emitir($venda->fresh());

        Http::assertSent(fn ($request) => round(array_sum(array_column($request['items'], 'othersAmount')), 2) === 0.0);
    }
}
