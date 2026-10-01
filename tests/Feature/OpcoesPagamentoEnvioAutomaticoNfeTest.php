<?php

namespace Tests\Feature;

use App\Filament\Resources\OpcoesPagamento\Pages\ManageOpcoesPagamento;
use App\Models\OpcoesPagamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OpcoesPagamentoEnvioAutomaticoNfeTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadastro_salva_flag_de_envio_automatico_e_tabela_exibe_a_coluna(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(ManageOpcoesPagamento::class)
            ->callTableAction('create', data: [
                'opcaopag_nome' => 'Pix',
                'opcaopag_desc_nfe' => 'InstantPayment',
                'opcaopag_tipo_taxa' => 'N/A',
                'opcaopag_valor_percentual_taxa' => 0,
                'opcaopag_envio_automatico_nfe' => true,
            ])
            ->assertHasNoTableActionErrors()
            ->assertTableColumnExists('opcaopag_envio_automatico_nfe');

        $this->assertTrue(OpcoesPagamento::where('opcaopag_nome', 'Pix')->value('opcaopag_envio_automatico_nfe'));
    }
}
