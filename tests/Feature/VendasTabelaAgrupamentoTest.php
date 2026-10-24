<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Vendas\Pages\ListVendas;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ItensVenda;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tabela de vendas: pagamentos em lista expansível e agrupamento por sessão
 * de caixa (ligado por padrão, grupos colapsáveis, com subtotais).
 */
class VendasTabelaAgrupamentoTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixa $sessao;

    private OpcoesPagamento $pix;

    private OpcoesPagamento $dinheiro;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->admin()->create(['name_first' => 'Ana', 'name' => 'Ana']);
        $this->actingAs($admin);

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(2),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $admin->id,
        ]);
        $this->pix = OpcoesPagamento::create(['opcaopag_nome' => 'Pix', 'opcaopag_desc_nfe' => 'InstantPayment']);
        $this->dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'Cash']);
        $categoria = Categoria::create(['categoria_nome' => 'Geral']);
        $this->produto = Produto::create(['produto_descricao' => 'Calabresa', 'produto_categoria_id' => $categoria->id, 'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value, 'produto_preco_venda' => 40]);
    }

    private function venda(?SessaoCaixa $sessao, float $total, array $pagamentos = []): Venda
    {
        $venda = Venda::create([
            'venda_status' => 'FINALIZADA', 'venda_sessao_caixa_id' => $sessao?->id, 'venda_valor_itens' => $total,
            'venda_valor_total' => $total, 'venda_valor_pago' => $total, 'venda_datahora_iniciada' => now()->subHour(), 'venda_datahora_finalizada' => now(),
        ]);
        ItensVenda::create(['item_numero' => 1, 'item_venda_venda_id' => $venda->id, 'item_venda_produto_id' => $this->produto->id, 'item_venda_quantidade' => 1, 'item_venda_quantidade_tributavel' => 1, 'item_venda_valor_unitario' => $total, 'item_venda_valor' => $total, 'item_venda_status' => 'INSERIDO']);

        foreach ($pagamentos as [$forma, $valor, $troco]) {
            PagamentosVenda::create(['pg_venda_venda_id' => $venda->id, 'pg_venda_opcaopagamento_id' => $forma->id, 'pg_venda_valor_pagamento' => $valor, 'pg_venda_valor_pago_pelo_cliente' => $valor + $troco, 'pg_venda_valor_troco' => $troco]);
        }

        return $venda;
    }

    public function test_pagamentos_aparecem_em_lista_que_expande(): void
    {
        $venda = $this->venda($this->sessao, 50, [[$this->pix, 30, 0], [$this->dinheiro, 20, 5]]);

        Livewire::test(ListVendas::class)
            ->assertCanSeeTableRecords([$venda])
            ->assertTableColumnStateSet('pagamentos', ['Pix — R$ 30,00', 'Dinheiro — R$ 20,00 · troco R$ 5,00'], $venda)
            ->assertSee('Pix — R$ 30,00');
    }

    public function test_agrupa_por_sessao_com_titulo_subtotal_e_grupo_sem_sessao(): void
    {
        $outra = SessaoCaixa::create(['sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 2'])->id, 'sessaocaixa_status' => 'FECHADA', 'sessaocaixa_data_hora_abertura' => now()->subDay(), 'sessaocaixa_saldo_inicial' => 0, 'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Beto', 'name' => 'Beto'])->id]);
        $a = $this->venda($this->sessao, 30);
        $b = $this->venda($this->sessao, 20);
        $c = $this->venda($outra, 10);
        $semSessao = $this->venda(null, 5);

        $tabela = Livewire::test(ListVendas::class)
            ->assertSet('tableGrouping', 'venda_sessao_caixa_id:desc')
            ->assertCanSeeTableRecords([$a, $b, $c, $semSessao])
            ->assertSee("Sessão #{$this->sessao->id} — Caixa 1 — Ana")
            ->assertSee("Sessão #{$outra->id} — Caixa 2 — Beto")
            ->assertSee('Sem sessão de caixa')
            // Subtotal da sessão #1 (R$ 30 + R$ 20): só aparece no resumo do grupo.
            ->assertSee("R\$\u{00A0}50,00");

        // O subtotal do grupo soma só as vendas da sessão.
        $this->assertEqualsWithDelta(50.0, (float) Venda::where('venda_sessao_caixa_id', $this->sessao->id)->sum('venda_valor_total'), 0.001);

        // Desligar o agrupamento volta à lista plana.
        $tabela->set('tableGrouping', null)->assertCanSeeTableRecords([$a, $b, $c, $semSessao]);
    }
}
