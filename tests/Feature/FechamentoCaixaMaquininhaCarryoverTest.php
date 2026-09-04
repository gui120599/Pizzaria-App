<?php

namespace Tests\Feature;

use App\Enums\StatusFechamentoCaixa;
use App\Models\Caixa;
use App\Models\FechamentoCaixa;
use App\Models\FechamentoCaixaMaquininha;
use App\Models\Maquininha;
use App\Models\SessaoCaixa;
use App\Models\SessaoCaixaMaquininha;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O carryover (saldo que a maquininha já tinha antes do turno) é registrado
 * na abertura (SessaoCaixaMaquininha) e abatido do apurado do fechamento
 * (FechamentoCaixa::totalDebito/totalCredito/totalPix), por categoria e por
 * maquininha — não mais só do total geral.
 */
class FechamentoCaixaMaquininhaCarryoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function sessaoFechada(): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    private function maquininha(string $nome = 'Maquininha 1'): Maquininha
    {
        return Maquininha::create(['nome' => $nome, 'operadora' => 'stone']);
    }

    public function test_subtrai_carryover_por_categoria_e_por_maquininha(): void
    {
        $sessao = $this->sessaoFechada();
        $maquininhaA = $this->maquininha('A');
        $maquininhaB = $this->maquininha('B');

        SessaoCaixaMaquininha::create([
            'sessao_caixa_id' => $sessao->id,
            'maquininha_id' => $maquininhaA->id,
            'valor_debito' => 100,
            'valor_credito' => 0,
            'valor_pix' => 0,
        ]);
        SessaoCaixaMaquininha::create([
            'sessao_caixa_id' => $sessao->id,
            'maquininha_id' => $maquininhaB->id,
            'valor_debito' => 0,
            'valor_credito' => 20,
            'valor_pix' => 0,
        ]);

        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);

        FechamentoCaixaMaquininha::create([
            'fechamento_caixa_id' => $fechamento->id,
            'maquininha_id' => $maquininhaA->id,
            'valor_debito' => 350, // 100 carryover + 250 deste turno
            'valor_credito' => 0,
            'valor_pix' => 0,
        ]);
        FechamentoCaixaMaquininha::create([
            'fechamento_caixa_id' => $fechamento->id,
            'maquininha_id' => $maquininhaB->id,
            'valor_debito' => 0,
            'valor_credito' => 70, // 20 carryover + 50 deste turno
            'valor_pix' => 0,
        ]);

        $fechamento->refresh();

        $this->assertEqualsWithDelta(250.0, $fechamento->totalDebito, 0.01);
        $this->assertEqualsWithDelta(50.0, $fechamento->totalCredito, 0.01);
        $this->assertEqualsWithDelta(0.0, $fechamento->totalPix, 0.01);
        $this->assertEqualsWithDelta(300.0, $fechamento->totalMaquininhasLiquido, 0.01);
    }

    public function test_maquininha_sem_carryover_registrado_na_abertura_nao_quebra(): void
    {
        $sessao = $this->sessaoFechada();
        $maquininha = $this->maquininha();
        // Nenhum SessaoCaixaMaquininha criado — maquininha usada no fechamento
        // sem ter sido registrada na abertura.

        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);

        FechamentoCaixaMaquininha::create([
            'fechamento_caixa_id' => $fechamento->id,
            'maquininha_id' => $maquininha->id,
            'valor_debito' => 80,
            'valor_credito' => 0,
            'valor_pix' => 0,
        ]);

        $fechamento->refresh();

        $this->assertEqualsWithDelta(80.0, $fechamento->totalDebito, 0.01);
    }
}
