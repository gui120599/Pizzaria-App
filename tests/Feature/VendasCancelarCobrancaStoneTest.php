<?php

namespace Tests\Feature;

use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoOrigem;
use App\Enums\StonePedidoStatus;
use App\Filament\Resources\Vendas\Pages\ListVendas;
use App\Models\Maquininha;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\CenarioStone;
use Tests\TestCase;

/**
 * Venda do PDV com cobrança aberta na maquininha (inclusive a que o sistema
 * marcou como Falha mas segue aberta na Stone): cancelar pela lista de vendas.
 */
class VendasCancelarCobrancaStoneTest extends TestCase
{
    use CenarioStone;
    use RefreshDatabase;

    private Maquininha $sunmi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurarStone();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->sunmi = $this->maquininhaConnect('Sunmi', '111');
    }

    private function vendaComCobranca(StonePedidoStatus $status, array $atributos = []): array
    {
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_itens' => 40, 'venda_valor_total' => 40, 'venda_valor_pago' => 0]);
        $cobranca = StonePedido::create($atributos + [
            'stp_venda_id' => $venda->id,
            'stp_maquininha_id' => $this->sunmi->id,
            'stp_origem' => StonePedidoOrigem::Venda,
            'stp_order_id' => 'or_'.uniqid(),
            'stp_valor_solicitado' => 40,
            'stp_status' => $status,
            'stp_modo' => StonePedidoModo::Listado,
        ]);

        return [$venda, $cobranca];
    }

    private function cancelamentosEnviados(): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/closed')
            && $request['status'] === 'canceled')->count();
    }

    public function test_cancela_a_cobranca_marcada_como_falha_que_segue_aberta_na_stone(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        [$venda, $cobranca] = $this->vendaComCobranca(StonePedidoStatus::Falha, ['stp_order_id' => 'or_falha']);

        Livewire::test(ListVendas::class)
            ->assertTableActionVisible('cancelarCobrancaStone', $venda)
            ->callAction(TestAction::make('cancelarCobrancaStone')->table($venda))
            ->assertNotified('1 cobrança(s) cancelada(s) na maquininha.');

        $this->assertSame(StonePedidoStatus::Cancelado, $cobranca->fresh()->stp_status);
        $this->assertSame('INICIADA', $venda->fresh()->venda_status);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/orders/or_falha/closed'));
    }

    public function test_acao_so_aparece_para_venda_com_cobranca_aberta(): void
    {
        [$paga] = $this->vendaComCobranca(StonePedidoStatus::Pago, ['stp_valor_pago' => 40]);
        [$cancelada] = $this->vendaComCobranca(StonePedidoStatus::Cancelado);
        [$semOrder] = $this->vendaComCobranca(StonePedidoStatus::Falha, ['stp_order_id' => null]);
        $semCobranca = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 10]);

        $tela = Livewire::test(ListVendas::class);

        foreach ([$paga, $cancelada, $semOrder, $semCobranca] as $venda) {
            $tela->assertTableActionHidden('cancelarCobrancaStone', $venda);
        }
    }

    public function test_acao_exige_permissao_de_operar_venda(): void
    {
        [$venda] = $this->vendaComCobranca(StonePedidoStatus::Aguardando);
        $semPermissao = User::factory()->create(['name_first' => 'Sem']);
        $semPermissao->givePermissionTo(Permission::firstOrCreate(['name' => 'view_any:venda', 'guard_name' => 'web']));

        $this->actingAs($semPermissao);

        Livewire::test(ListVendas::class)
            ->assertTableActionHidden('cancelarCobrancaStone', $venda);
    }

    public function test_cancelamento_em_lote_cancela_as_abertas_e_ignora_as_demais(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response([], 200)]);
        [$a, $cobrancaA] = $this->vendaComCobranca(StonePedidoStatus::Aguardando, ['stp_order_id' => 'or_a']);
        [$b, $cobrancaB] = $this->vendaComCobranca(StonePedidoStatus::Falha, ['stp_order_id' => 'or_b']);
        [$c, $cobrancaC] = $this->vendaComCobranca(StonePedidoStatus::Pago, ['stp_order_id' => 'or_c', 'stp_valor_pago' => 40]);
        $semCobranca = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 10]);

        Livewire::test(ListVendas::class)
            ->selectTableRecords([$a->id, $b->id, $c->id, $semCobranca->id])
            ->callAction(TestAction::make('cancelarCobrancasStoneEmLote')->table()->bulk())
            ->assertNotified('2 cobrança(s) cancelada(s) na maquininha.');

        $this->assertSame(StonePedidoStatus::Cancelado, $cobrancaA->fresh()->stp_status);
        $this->assertSame(StonePedidoStatus::Cancelado, $cobrancaB->fresh()->stp_status);
        $this->assertSame(StonePedidoStatus::Pago, $cobrancaC->fresh()->stp_status);
        $this->assertSame(2, $this->cancelamentosEnviados());
    }

    public function test_recusa_da_stone_no_lote_e_avisada_sem_mudar_o_status(): void
    {
        Http::fake(['api.pagar.me/*' => Http::response(['message' => 'Pedido já possui cobrança paga'], 422)]);
        [$venda, $cobranca] = $this->vendaComCobranca(StonePedidoStatus::Aguardando, ['stp_order_id' => 'or_r']);

        Livewire::test(ListVendas::class)
            ->selectTableRecords([$venda->id])
            ->callAction(TestAction::make('cancelarCobrancasStoneEmLote')->table()->bulk())
            ->assertNotified('0 cobrança(s) cancelada(s) na maquininha.');

        $this->assertSame(StonePedidoStatus::Aguardando, $cobranca->fresh()->stp_status);
    }
}
