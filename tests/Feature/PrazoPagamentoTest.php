<?php

namespace Tests\Feature;

use App\Models\PrazoPagamento;
use App\Models\PrazoPagamentoParcela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrazoPagamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_prazo_com_parcelas_ordenadas(): void
    {
        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => '30/60/90']);
        $prazo->parcelas()->createMany([
            ['parcela_numero' => 2, 'parcela_dias' => 60, 'parcela_percentual' => 33.33],
            ['parcela_numero' => 1, 'parcela_dias' => 30, 'parcela_percentual' => 33.34],
            ['parcela_numero' => 3, 'parcela_dias' => 90, 'parcela_percentual' => 33.33],
        ]);

        $parcelas = $prazo->parcelas;

        $this->assertCount(3, $parcelas);
        // Relação ordena por parcela_numero, independentemente da ordem de criação.
        $this->assertSame([1, 2, 3], $parcelas->pluck('parcela_numero')->all());
        $this->assertSame([30, 60, 90], $parcelas->pluck('parcela_dias')->all());
    }

    public function test_scope_ativos_filtra_apenas_prazos_ativos(): void
    {
        PrazoPagamento::create(['prazo_pagamento_nome' => 'Ativo', 'prazo_pagamento_ativo' => true]);
        PrazoPagamento::create(['prazo_pagamento_nome' => 'Inativo', 'prazo_pagamento_ativo' => false]);

        $ativos = PrazoPagamento::ativos()->pluck('prazo_pagamento_nome')->all();

        $this->assertSame(['Ativo'], $ativos);
    }

    public function test_excluir_prazo_apaga_parcelas_em_cascata(): void
    {
        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => '10x com juros']);
        $prazo->parcelas()->create(['parcela_numero' => 1, 'parcela_dias' => 30, 'parcela_percentual' => 10.5]);

        $prazo->forceDelete();

        $this->assertSame(0, PrazoPagamentoParcela::where('prazo_pagamento_id', $prazo->id)->count());
    }
}
