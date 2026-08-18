<?php

namespace Tests\Feature;

use App\Filament\Resources\NfeWebhooks\NfeWebhookResource;
use App\Filament\Resources\NfeWebhooks\Pages\ManageNfeWebhooks;
use App\Models\Empresa;
use App\Models\NfeWebhook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NfeWebhookResourceSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_navegacao_so_aparece_com_nfeio_configurada(): void
    {
        $this->assertFalse(NfeWebhookResource::shouldRegisterNavigation());

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);

        $this->assertTrue(NfeWebhookResource::shouldRegisterNavigation());
    }

    public function test_listagem_monta_sem_erro(): void
    {
        NfeWebhook::create([
            'nfw_evento' => 'consumerinvoice.Issued',
            'nfw_invoice_id' => 'inv-123',
            'nfw_payload' => ['id' => 'inv-123', 'status' => 'Issued'],
            'nfw_assinatura_valida' => true,
            'nfw_processado_em' => now(),
        ]);

        Livewire::test(ManageNfeWebhooks::class)->assertOk();
    }

    public function test_acao_ver_payload_abre_sem_erro(): void
    {
        $webhook = NfeWebhook::create([
            'nfw_invoice_id' => 'inv-123',
            'nfw_payload' => ['id' => 'inv-123', 'status' => 'Issued'],
        ]);

        Livewire::test(ManageNfeWebhooks::class)
            ->mountTableAction('verPayload', $webhook)
            ->assertOk();
    }
}
