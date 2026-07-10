<?php

namespace Tests\Feature;

use App\Filament\Resources\Lancamentos\Pages\CreateLancamento;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LancamentoFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name_first' => 'Admin']));
    }

    /**
     * O campo "tipo" usa options(TipoLancamento::class), que registra um EnumStateCast:
     * $get('tipo') devolve a instância do enum, não a string. Estes testes garantem que
     * a visibilidade condicional continua reagindo à troca de tipo (regressão do bug em
     * que comparar com ->value escondia todos os campos condicionais).
     */
    public function test_tipo_pagar_exibe_despesa_e_fornecedor_e_esconde_receita_e_cliente(): void
    {
        Livewire::test(CreateLancamento::class)
            ->fillForm(['tipo' => 'pagar'])
            ->assertFormFieldVisible('plano_despesa_id')
            ->assertFormFieldVisible('favorecido_id')
            ->assertFormFieldHidden('plano_receita_id')
            ->assertFormFieldHidden('cliente_id');
    }

    public function test_tipo_receber_exibe_receita_e_cliente_e_esconde_despesa_e_fornecedor(): void
    {
        Livewire::test(CreateLancamento::class)
            ->fillForm(['tipo' => 'receber'])
            ->assertFormFieldVisible('plano_receita_id')
            ->assertFormFieldVisible('cliente_id')
            ->assertFormFieldHidden('plano_despesa_id')
            ->assertFormFieldHidden('favorecido_id');
    }

    /**
     * Fornecedor PJ pode ter a coluna `nome` nula (só razão social / nome fantasia).
     * O Select deve usar o accessor nome_exibicao e montar sem quebrar (regressão do
     * erro "isOptionDisabled(): Argument #2 (\$label) must be of type string, null given").
     */
    public function test_fornecedor_sem_nome_nao_quebra_o_select(): void
    {
        Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora Sem Nome LTDA',
            'nome' => null,
        ]);

        Livewire::test(CreateLancamento::class)
            ->fillForm(['tipo' => 'pagar'])
            ->assertFormFieldVisible('favorecido_id')
            ->assertOk();
    }
}
