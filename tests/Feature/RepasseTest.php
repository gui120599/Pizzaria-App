<?php

namespace Tests\Feature;

use App\Enums\Ponto\NaturezaItem;
use App\Enums\Ponto\TipoItemRepasse;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Lancamento;
use App\Models\Repasse;
use App\Models\RepasseItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepasseTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalcular_totais_soma_proventos_e_tira_descontos(): void
    {
        $repasse = Repasse::factory()->create();
        RepasseItem::factory()->for($repasse)->natureza(NaturezaItem::Diarias, 600)->create();
        RepasseItem::factory()->for($repasse)->natureza(NaturezaItem::AdicionalNoturno, 45.35)->create();
        RepasseItem::factory()->for($repasse)->natureza(NaturezaItem::Adiantamento, 100)->create();
        RepasseItem::factory()->for($repasse)->natureza(NaturezaItem::Consumo, 30.10)->create();

        $repasse->recalcularTotais();

        $repasse->refresh();
        $this->assertSame('645.35', $repasse->total_proventos);
        $this->assertSame('130.10', $repasse->total_descontos);
        $this->assertSame('515.25', $repasse->valor_liquido);
    }

    public function test_tipo_do_item_segue_a_natureza(): void
    {
        $item = RepasseItem::factory()->create([
            'natureza' => NaturezaItem::Inss,
            'tipo' => TipoItemRepasse::Provento,
        ]);

        $this->assertSame(TipoItemRepasse::Desconto, $item->fresh()->tipo);
    }

    public function test_lancamento_vigente_ignora_o_titulo_cancelado(): void
    {
        $repasse = Repasse::factory()->create();
        $titulo = fn (StatusLancamento $status): Lancamento => Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'repasse_id' => $repasse->id,
            'descricao' => 'Repasse',
            'valor' => 500,
            'vencimento' => '2026-10-20',
            'status' => $status,
        ]);
        $titulo(StatusLancamento::Cancelado);
        $vigente = $titulo(StatusLancamento::Pendente);

        $this->assertSame($vigente->id, $repasse->lancamento?->id);
        $this->assertSame(2, $repasse->lancamentos()->count());
    }
}
