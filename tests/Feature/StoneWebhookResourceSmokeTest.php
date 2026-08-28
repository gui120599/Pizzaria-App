<?php

namespace Tests\Feature;

use App\Filament\Resources\StoneWebhooks\Pages\ManageStoneWebhooks;
use App\Filament\Resources\StoneWebhooks\StoneWebhookResource;
use App\Models\StoneWebhook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoneWebhookResourceSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_navegacao_so_aparece_com_integracao_stone_configurada(): void
    {
        config(['services.stone.webhook_user' => null, 'services.stone.secret_key' => null]);
        $this->assertFalse(StoneWebhookResource::shouldRegisterNavigation());

        config(['services.stone.webhook_user' => 'stone']);
        $this->assertTrue(StoneWebhookResource::shouldRegisterNavigation());
    }

    public function test_listagem_monta_sem_erro(): void
    {
        StoneWebhook::create([
            'stw_evento' => 'charge.paid',
            'stw_hook_id' => 'hook_123',
            'stw_charge_id' => 'ch_123',
            'stw_order_code' => 'D3MGIQI835',
            'stw_payload' => ['type' => 'charge.paid'],
            'stw_autenticado' => true,
            'stw_processado_em' => now(),
        ]);

        Livewire::test(ManageStoneWebhooks::class)->assertOk();
    }

    public function test_acao_ver_payload_abre_sem_erro(): void
    {
        $webhook = StoneWebhook::create([
            'stw_order_code' => 'D3MGIQI835',
            'stw_payload' => ['type' => 'charge.paid'],
        ]);

        Livewire::test(ManageStoneWebhooks::class)
            ->mountTableAction('verPayload', $webhook)
            ->assertOk();
    }
}
