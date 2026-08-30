<?php

namespace Tests\Feature;

use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Support\TotaisPedido;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TotaisPedidoTest extends TestCase
{
    /** @param array<int, array{valor: float, desconto: float}> $linhas */
    private function itens(array $linhas): Collection
    {
        return collect($linhas)->map(fn (array $l) => new ItensPedido([
            'item_pedido_valor' => $l['valor'],
            'item_pedido_desconto' => $l['desconto'],
        ]));
    }

    public function test_bruto_e_soma_do_liquido_com_o_desconto_dos_itens(): void
    {
        // Meia a meia: ½ com desconto (24,95 líquido / 13,00 desconto) + ½ comum (37,95).
        $totais = TotaisPedido::paraItens($this->itens([
            ['valor' => 24.95, 'desconto' => 13.00],
            ['valor' => 37.95, 'desconto' => 0.0],
        ]), opcao: null);

        $this->assertEqualsWithDelta(75.90, $totais['itens'], 0.001);
        $this->assertEqualsWithDelta(13.00, $totais['desconto'], 0.001);
        $this->assertEqualsWithDelta(0.0, $totais['frete'], 0.001);
        $this->assertEqualsWithDelta(62.90, $totais['total'], 0.001);
    }

    public function test_desconto_do_pedido_entra_no_total_uma_unica_vez(): void
    {
        $totais = TotaisPedido::paraItens($this->itens([
            ['valor' => 100.00, 'desconto' => 0.0],
        ]), opcao: null, descontoPedido: 10.00);

        $this->assertEqualsWithDelta(100.00, $totais['itens'], 0.001);
        $this->assertEqualsWithDelta(10.00, $totais['desconto'], 0.001);
        $this->assertEqualsWithDelta(90.00, $totais['total'], 0.001);
    }

    public function test_frete_gratis_quando_liquido_atinge_o_minimo(): void
    {
        $opcao = new OpcoesEntregas([
            'opcaoentrega_valor_frete' => 4.00,
            'opcaoentrega_min_valor_frete' => 50.00,
        ]);

        $this->assertEqualsWithDelta(0.0, TotaisPedido::frete($opcao, 62.90), 0.001);
        $this->assertEqualsWithDelta(0.0, TotaisPedido::frete($opcao, 50.00), 0.001);
        $this->assertEqualsWithDelta(4.00, TotaisPedido::frete($opcao, 49.99), 0.001);
    }

    public function test_frete_sempre_cobrado_quando_nao_ha_minimo(): void
    {
        $opcao = new OpcoesEntregas([
            'opcaoentrega_valor_frete' => 6.00,
            'opcaoentrega_min_valor_frete' => 0.00,
        ]);

        $this->assertEqualsWithDelta(6.00, TotaisPedido::frete($opcao, 500.00), 0.001);
    }

    public function test_opcao_sem_valor_de_frete_nao_cobra(): void
    {
        $opcao = new OpcoesEntregas([
            'opcaoentrega_valor_frete' => 0.00,
            'opcaoentrega_min_valor_frete' => 0.00,
        ]);

        $this->assertEqualsWithDelta(0.0, TotaisPedido::frete($opcao, 10.00), 0.001);
        $this->assertEqualsWithDelta(0.0, TotaisPedido::frete(null, 10.00), 0.001);
    }

    public function test_frete_indevido_entra_apenas_uma_vez_no_total(): void
    {
        $opcao = new OpcoesEntregas([
            'opcaoentrega_valor_frete' => 4.00,
            'opcaoentrega_min_valor_frete' => 50.00,
        ]);

        // Líquido 45,00 (< 50) → cobra frete.
        $totais = TotaisPedido::paraItens($this->itens([
            ['valor' => 45.00, 'desconto' => 5.00],
        ]), $opcao);

        $this->assertEqualsWithDelta(50.00, $totais['itens'], 0.001);
        $this->assertEqualsWithDelta(5.00, $totais['desconto'], 0.001);
        $this->assertEqualsWithDelta(4.00, $totais['frete'], 0.001);
        $this->assertEqualsWithDelta(49.00, $totais['total'], 0.001);
    }
}
