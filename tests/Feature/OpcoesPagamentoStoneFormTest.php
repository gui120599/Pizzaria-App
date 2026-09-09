<?php

namespace Tests\Feature;

use App\Filament\Resources\OpcoesPagamento\Pages\ManageOpcoesPagamento;
use App\Models\OpcoesPagamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OpcoesPagamentoStoneFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function dadosBase(string $descNfe): array
    {
        return [
            'opcaopag_nome' => 'Stone '.$descNfe,
            'opcaopag_desc_nfe' => $descNfe,
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => true,
        ];
    }

    /** @return array<string, array{string}> */
    public static function codigosAceitos(): array
    {
        return [
            'crédito' => ['creditCard'],
            'débito' => ['debitCard'],
            'pix' => ['InstantPayment'],
        ];
    }

    /** @dataProvider codigosAceitos */
    public function test_stone_integrada_aceita_cartao_e_pix(string $descNfe): void
    {
        Livewire::test(ManageOpcoesPagamento::class)
            ->callTableAction('create', data: $this->dadosBase($descNfe))
            ->assertHasNoTableActionErrors();

        $this->assertTrue((bool) OpcoesPagamento::where('opcaopag_desc_nfe', $descNfe)->value('opcaopag_stone_integrada'));
    }

    public function test_stone_integrada_rejeita_dinheiro(): void
    {
        Livewire::test(ManageOpcoesPagamento::class)
            ->callTableAction('create', data: $this->dadosBase('cash'))
            ->assertHasTableActionErrors(['opcaopag_stone_integrada']);

        $this->assertSame(0, OpcoesPagamento::count());
    }
}
