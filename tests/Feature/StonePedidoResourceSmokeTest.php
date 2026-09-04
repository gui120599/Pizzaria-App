<?php

namespace Tests\Feature;

use App\Filament\Resources\StonePedidos\Pages\ManageStonePedidos;
use App\Filament\Resources\StonePedidos\StonePedidoResource;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StonePedidoResourceSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_navegacao_so_aparece_com_integracao_stone_configurada(): void
    {
        config(['services.stone.secret_key' => null]);
        $this->assertFalse(StonePedidoResource::shouldRegisterNavigation());

        config(['services.stone.secret_key' => 'sk_test']);
        $this->assertTrue(StonePedidoResource::shouldRegisterNavigation());
    }

    public function test_listagem_monta_sem_erro(): void
    {
        $venda = Venda::create(['venda_status' => 'INICIADA']);
        StonePedido::create([
            'stp_venda_id' => $venda->id,
            'stp_order_id' => 'or_1',
            'stp_order_code' => 'ABC1',
            'stp_valor_solicitado' => 20.00,
            'stp_valor_pago' => 20.00,
            'stp_status' => 'pago',
        ]);

        Livewire::test(ManageStonePedidos::class)->assertOk();
    }
}
