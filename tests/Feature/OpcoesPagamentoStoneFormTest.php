<?php

namespace Tests\Feature;

use App\Filament\Resources\OpcoesPagamento\Pages\ManageOpcoesPagamento;
use App\Models\OpcoesPagamento;
use App\Models\PlanoReceita;
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
            'plano_receita_id' => PlanoReceita::firstOrCreate(['nome' => 'Balcão'])->id,
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

    /** #6c — duas formas que permitem Stone com o mesmo Código NF-e deixam o pagamento da maquininha ambíguo. */
    public function test_duas_formas_que_permitem_stone_com_o_mesmo_codigo_nfe_sao_rejeitadas(): void
    {
        $existente = OpcoesPagamento::create($this->dadosBase('creditCard'));

        Livewire::test(ManageOpcoesPagamento::class)
            ->callTableAction('create', data: ['opcaopag_nome' => 'Crédito 2'] + $this->dadosBase('creditCard'))
            ->assertHasTableActionErrors(['opcaopag_stone_integrada']);

        $this->assertSame(1, OpcoesPagamento::where('opcaopag_stone_integrada', true)->count());
        $this->assertTrue($existente->fresh()->opcaopag_stone_integrada);
    }

    public function test_forma_de_outro_codigo_nfe_ou_sem_o_flag_nao_conflita(): void
    {
        OpcoesPagamento::create($this->dadosBase('creditCard'));

        Livewire::test(ManageOpcoesPagamento::class)
            ->callTableAction('create', data: $this->dadosBase('debitCard'))
            ->assertHasNoTableActionErrors();

        Livewire::test(ManageOpcoesPagamento::class)
            ->callTableAction('create', data: ['opcaopag_nome' => 'Crédito manual', 'opcaopag_stone_integrada' => false] + $this->dadosBase('creditCard'))
            ->assertHasNoTableActionErrors();
    }

    public function test_editar_a_propria_forma_que_permite_stone_nao_conflita_consigo_mesma(): void
    {
        $existente = OpcoesPagamento::create($this->dadosBase('creditCard'));

        Livewire::test(ManageOpcoesPagamento::class)
            ->callTableAction('edit', $existente, data: ['opcaopag_nome' => 'Crédito renomeado'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Crédito renomeado', $existente->fresh()->opcaopag_nome);
    }
}
