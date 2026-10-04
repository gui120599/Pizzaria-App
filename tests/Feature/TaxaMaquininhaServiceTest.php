<?php

namespace Tests\Feature;

use App\Enums\OperadoraMaquininha;
use App\Filament\Resources\Maquininhas\Pages\EditMaquininha;
use App\Filament\Resources\Maquininhas\RelationManagers\TaxasRelationManager;
use App\Models\CartoesPagamento;
use App\Models\Maquininha;
use App\Models\MaquininhaTaxa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\User;
use App\Models\Venda;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Taxa da adquirente gravada em cada pagamento: maquininha × bandeira × tipo.
 */
class TaxaMaquininhaServiceTest extends TestCase
{
    use RefreshDatabase;

    private Venda $venda;

    private Maquininha $maquininha;

    private CartoesPagamento $visa;

    private CartoesPagamento $master;

    protected function setUp(): void
    {
        parent::setUp();

        $this->venda = Venda::create(['venda_status' => 'INICIADA']);
        $this->maquininha = Maquininha::create(['nome' => 'Stone 1', 'operadora' => OperadoraMaquininha::Stone]);
        $this->visa = CartoesPagamento::create(['cartao_bandeira' => 'Visa']);
        $this->master = CartoesPagamento::create(['cartao_bandeira' => 'MasterCard']);

        $this->taxa('credito', 3.00);
        $this->taxa('credito', 2.50, $this->visa);
        $this->taxa('pix', 1.00);
    }

    private function taxa(string $tipo, float $percentual, ?CartoesPagamento $bandeira = null, ?Maquininha $maquininha = null): MaquininhaTaxa
    {
        return MaquininhaTaxa::create([
            'mt_maquininha_id' => ($maquininha ?? $this->maquininha)->id,
            'mt_cartao_id' => $bandeira?->id,
            'mt_tipo' => $tipo,
            'mt_percentual' => $percentual,
        ]);
    }

    private function pagamento(string $descNfe, ?CartoesPagamento $bandeira = null, ?Maquininha $maquininha = null, float $valor = 100.00): PagamentosVenda
    {
        $opcao = OpcoesPagamento::create([
            'opcaopag_nome' => $descNfe,
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_desc_nfe' => $descNfe,
        ]);

        return PagamentosVenda::create([
            'pg_venda_venda_id' => $this->venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_cartao_id' => $bandeira?->id,
            'pg_venda_maquininha_id' => $maquininha?->id,
            'pg_venda_valor_pagamento' => $valor,
        ])->fresh();
    }

    public function test_usa_a_taxa_da_bandeira_e_cai_na_taxa_sem_bandeira_do_tipo(): void
    {
        $visa = $this->pagamento('creditCard', $this->visa, $this->maquininha);
        $master = $this->pagamento('creditCard', $this->master, $this->maquininha);
        $pix = $this->pagamento('InstantPayment', null, $this->maquininha);

        $this->assertEquals(2.50, $visa->pg_venda_taxa_maquininha_percentual);
        $this->assertEquals(2.50, $visa->pg_venda_taxa_maquininha_valor);
        $this->assertEquals(3.00, $master->pg_venda_taxa_maquininha_percentual);
        $this->assertEquals(1.00, $pix->pg_venda_taxa_maquininha_valor);
    }

    public function test_dinheiro_nao_tem_taxa_e_cartao_sem_taxa_cadastrada_fica_desconhecido(): void
    {
        $dinheiro = $this->pagamento('cash');
        $debito = $this->pagamento('debitCard', $this->visa, $this->maquininha);

        $this->assertEquals(0, $dinheiro->pg_venda_taxa_maquininha_valor);
        $this->assertNull($debito->pg_venda_taxa_maquininha_percentual);
        $this->assertNull($debito->pg_venda_taxa_maquininha_valor);
    }

    public function test_pagamento_sem_maquininha_usa_a_maquininha_padrao(): void
    {
        $padrao = Maquininha::create(['nome' => 'Cielo', 'operadora' => OperadoraMaquininha::Cielo, 'maquininha_padrao' => true]);
        $this->taxa('credito', 4.00, null, $padrao);

        $pagamento = $this->pagamento('creditCard', $this->master);

        $this->assertNull($pagamento->pg_venda_maquininha_id);
        $this->assertEquals(4.00, $pagamento->pg_venda_taxa_maquininha_percentual);
    }

    public function test_so_uma_maquininha_fica_como_padrao(): void
    {
        $primeira = Maquininha::create(['nome' => 'A', 'operadora' => OperadoraMaquininha::Cielo, 'maquininha_padrao' => true]);
        $segunda = Maquininha::create(['nome' => 'B', 'operadora' => OperadoraMaquininha::Rede, 'maquininha_padrao' => true]);

        $this->assertFalse($primeira->fresh()->maquininha_padrao);
        $this->assertTrue($segunda->fresh()->maquininha_padrao);
    }

    public function test_mudar_a_taxa_depois_nao_altera_o_pagamento_ja_gravado(): void
    {
        $pagamento = $this->pagamento('creditCard', $this->visa, $this->maquininha);

        MaquininhaTaxa::where('mt_cartao_id', $this->visa->id)->update(['mt_percentual' => 5.00]);

        $this->assertEquals(2.50, $pagamento->fresh()->pg_venda_taxa_maquininha_valor);
    }

    public function test_cadastro_de_taxas_na_maquininha_recusa_taxa_repetida(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $relationManager = Livewire::test(TaxasRelationManager::class, [
            'ownerRecord' => $this->maquininha,
            'pageClass' => EditMaquininha::class,
        ]);

        $relationManager
            ->callAction(TestAction::make('create')->table(), ['mt_tipo' => 'debito', 'mt_cartao_id' => $this->master->id, 'mt_percentual' => 1.99])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(MaquininhaTaxa::class, ['mt_maquininha_id' => $this->maquininha->id, 'mt_tipo' => 'debito', 'mt_cartao_id' => $this->master->id]);

        // Já existe crédito sem bandeira (setUp).
        $relationManager
            ->callAction(TestAction::make('create')->table(), ['mt_tipo' => 'credito', 'mt_cartao_id' => null, 'mt_percentual' => 9])
            ->assertHasFormErrors(['mt_tipo' => 'unique']);
    }
}
