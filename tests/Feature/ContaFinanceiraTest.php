<?php

namespace Tests\Feature;

use App\Models\ContaFinanceira;
use App\Models\MovimentacaoFinanceira;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContaFinanceiraTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejeita_segunda_conta_caixa_pdv(): void
    {
        ContaFinanceira::factory()->caixaPdv()->create();

        $this->expectException(ValidationException::class);

        ContaFinanceira::factory()->caixaPdv()->create(['nome' => 'Outro caixa']);
    }

    public function test_caixa_pdv_desativado_libera_cadastro_de_outro(): void
    {
        ContaFinanceira::factory()->caixaPdv()->create()->delete();

        $novo = ContaFinanceira::factory()->caixaPdv()->create(['nome' => 'Caixa PDV novo']);

        $this->assertSame($novo->id, ContaFinanceira::caixaPdv()?->id);
    }

    public function test_saldo_na_data_soma_so_o_que_foi_movimentado_entre_o_saldo_inicial_e_a_data(): void
    {
        $conta = ContaFinanceira::factory()->comSaldo(1000)->create(['data_saldo_inicial' => '2026-10-01']);
        $movimentar = fn (string $tipo, float $valor, string $data) => MovimentacaoFinanceira::factory()->{$tipo}()
            ->for($conta, 'conta')
            ->create(['valor' => $valor, 'data_movimento' => $data]);
        $movimentar('saida', 999, '2026-09-30');
        $movimentar('entrada', 250, '2026-10-02');
        $movimentar('saida', 100.50, '2026-10-05');
        $movimentar('saida', 400, '2026-10-06');

        $saldo = $conta->saldoEm(Carbon::parse('2026-10-05'));

        $this->assertSame(1149.5, $saldo);
    }
}
