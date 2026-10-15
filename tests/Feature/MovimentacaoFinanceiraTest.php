<?php

namespace Tests\Feature;

use App\Exceptions\RegistroImutavelException;
use App\Models\MovimentacaoFinanceira;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovimentacaoFinanceiraTest extends TestCase
{
    use RefreshDatabase;

    public function test_movimentacao_nao_pode_ser_alterada(): void
    {
        $movimentacao = MovimentacaoFinanceira::factory()->create(['valor' => 100]);

        try {
            $movimentacao->update(['valor' => 1]);
            $this->fail('A movimentação foi alterada.');
        } catch (RegistroImutavelException) {
        }

        $this->assertSame('100.00', $movimentacao->fresh()->valor);
    }

    public function test_movimentacao_nao_pode_ser_apagada(): void
    {
        $movimentacao = MovimentacaoFinanceira::factory()->create();

        try {
            $movimentacao->delete();
            $this->fail('A movimentação foi apagada.');
        } catch (RegistroImutavelException) {
        }

        $this->assertModelExists($movimentacao);
    }

    public function test_efetivas_excluem_a_estornada_e_o_estorno(): void
    {
        $estornada = MovimentacaoFinanceira::factory()->saida()->create();
        MovimentacaoFinanceira::factory()->entrada()->create([
            'conta_financeira_id' => $estornada->conta_financeira_id,
            'estorno_de_id' => $estornada->id,
        ]);
        $efetiva = MovimentacaoFinanceira::factory()->saida()->create(['conta_financeira_id' => $estornada->conta_financeira_id]);

        $ids = MovimentacaoFinanceira::efetivas()->pluck('id')->all();

        $this->assertSame([$efetiva->id], $ids);
    }
}
