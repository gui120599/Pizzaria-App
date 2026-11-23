<?php

namespace Tests\Feature;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\Financeiro\TipoMovimentacao;
use App\Exceptions\FinanceiroException;
use App\Filament\Resources\ContasFinanceiras\Pages\ListContasFinanceiras;
use App\Models\ContaFinanceira;
use App\Models\MovimentacaoFinanceira;
use App\Models\User;
use App\Services\Financeiro\MovimentacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class TransferenciaEntreContasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ContaFinanceira $stone;

    private ContaFinanceira $banco;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-23 15:00:00');
        $this->admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($this->admin);

        $this->stone = ContaFinanceira::factory()->comSaldo(1000)->create(['nome' => 'Stone', 'data_saldo_inicial' => '2026-10-16']);
        $this->banco = ContaFinanceira::factory()->banco()->comSaldo(200)->create(['nome' => 'Banco', 'data_saldo_inicial' => '2026-10-16']);
    }

    public function test_transferencia_debita_a_origem_credita_o_destino_e_bate_com_o_recalculo(): void
    {
        $pernas = $this->servico()->transferir($this->stone, $this->banco, 500, today(), null, $this->admin);

        $this->assertSame(TipoMovimentacao::Saida, $pernas['saida']->tipo);
        $this->assertSame($this->stone->id, $pernas['saida']->conta_financeira_id);
        $this->assertSame(TipoMovimentacao::Entrada, $pernas['entrada']->tipo);
        $this->assertSame($this->banco->id, $pernas['entrada']->conta_financeira_id);
        $this->assertSame($pernas['saida']->transferencia_uuid, $pernas['entrada']->transferencia_uuid);
        $this->assertSaldos(500, 700);
        $this->assertRecalculoNaoMuda();
    }

    public function test_estorno_restaura_os_dois_saldos_e_nao_pode_ser_repetido_por_nenhuma_ponta(): void
    {
        $pernas = $this->servico()->transferir($this->stone, $this->banco, 500, today(), null, $this->admin);

        $estornos = $this->servico()->estornar($pernas['saida'], 'Lançada errada', $this->admin);

        $this->assertCount(2, $estornos);
        $this->assertEqualsCanonicalizing([$pernas['saida']->id, $pernas['entrada']->id], $estornos->pluck('estorno_de_id')->all());
        $this->assertSaldos(1000, 200);

        foreach ([$pernas['saida'], $pernas['entrada'], $estornos->first()] as $movimentacao) {
            try {
                $this->servico()->estornar($movimentacao, 'Lançada errada', $this->admin);
                $this->fail('Estornou duas vezes.');
            } catch (FinanceiroException) {
            }
        }

        $this->assertSame(4, MovimentacaoFinanceira::count());
        $this->assertSaldos(1000, 200);
        $this->assertRecalculoNaoMuda();
    }

    public function test_transferencia_com_data_anterior_ao_saldo_inicial_e_recusada(): void
    {
        $this->expectExceptionMessage('anterior ao saldo inicial da conta Stone');

        try {
            $this->servico()->transferir($this->stone, $this->banco, 500, Carbon::parse('2026-10-01'), null, $this->admin);
        } finally {
            $this->assertSame(0, MovimentacaoFinanceira::count());
            $this->assertSaldos(1000, 200);
        }
    }

    public function test_transferencia_anterior_ao_saldo_inicial_de_uma_so_das_contas_e_recusada(): void
    {
        $this->banco->update(['data_saldo_inicial' => '2026-11-01']);

        $this->expectExceptionMessage('anterior ao saldo inicial da conta Banco');

        try {
            $this->servico()->transferir($this->stone, $this->banco, 500, Carbon::parse('2026-10-20'), null, $this->admin);
        } finally {
            $this->assertSame(0, MovimentacaoFinanceira::count());
            $this->assertSaldos(1000, 200);
        }
    }

    /** Regressão: transferência antiga (anterior ao saldo inicial) estornada não pode mexer no saldo de hoje. */
    public function test_estorno_de_transferencia_anterior_ao_saldo_inicial_nao_mexe_no_saldo(): void
    {
        $saida = $this->pernaAntiga($this->stone, TipoMovimentacao::Saida, 9718.58, '2026-10-05', $uuid = (string) Str::uuid());
        $this->pernaAntiga($this->banco, TipoMovimentacao::Entrada, 9718.58, '2026-10-05', $uuid);

        $estornos = $this->servico()->estornar($saida, 'Saldo não mudou', $this->admin);

        $this->assertSame(['2026-10-05', '2026-10-05'], $estornos->map(fn (MovimentacaoFinanceira $estorno): string => $estorno->data_movimento->toDateString())->all());
        $this->assertSaldos(1000, 200);
        $this->assertRecalculoNaoMuda();
    }

    public function test_estorno_de_movimentacao_dentro_do_periodo_continua_datado_de_hoje(): void
    {
        $pernas = $this->servico()->transferir($this->stone, $this->banco, 100, Carbon::parse('2026-11-02'), null, $this->admin);

        $estornos = $this->servico()->estornar($pernas['entrada'], 'Lançada errada', $this->admin);

        $this->assertSame(['2026-11-23'], $estornos->map(fn (MovimentacaoFinanceira $estorno): string => $estorno->data_movimento->toDateString())->unique()->values()->all());
    }

    public function test_falha_na_saida_da_origem_nao_grava_nada(): void
    {
        $this->expectException(FinanceiroException::class);

        try {
            $this->servico()->transferir($this->stone, $this->banco, 1500, today(), null, $this->admin);
        } finally {
            $this->assertSame(0, MovimentacaoFinanceira::count());
            $this->assertSaldos(1000, 200);
        }
    }

    public function test_falha_na_entrada_do_destino_desfaz_a_saida_da_origem(): void
    {
        $destino = ContaFinanceira::query()->find($this->banco->id);
        $this->banco->delete();

        $this->expectExceptionMessage('está desativada');

        try {
            $this->servico()->transferir($this->stone, $destino, 500, today(), null, $this->admin);
        } finally {
            $this->assertSame(0, MovimentacaoFinanceira::count());
            $this->assertEquals(1000, $this->stone->fresh()->saldo_atual);
        }
    }

    public function test_falha_ao_atualizar_o_saldo_do_destino_desfaz_a_transferencia_inteira(): void
    {
        ContaFinanceira::saving(function (ContaFinanceira $conta): void {
            if ($conta->id === $this->banco->id && $conta->isDirty('saldo_atual')) {
                throw new RuntimeException('Falha simulada ao gravar o saldo.');
            }
        });

        $this->expectExceptionMessage('Falha simulada');

        try {
            $this->servico()->transferir($this->stone, $this->banco, 500, today(), null, $this->admin);
        } finally {
            $this->assertSame(0, MovimentacaoFinanceira::count());
            $this->assertSaldos(1000, 200);
        }
    }

    public function test_falha_no_estorno_da_segunda_ponta_desfaz_o_estorno_inteiro(): void
    {
        $pernas = $this->servico()->transferir($this->stone, $this->banco, 500, today(), null, $this->admin);
        $falhar = true;
        MovimentacaoFinanceira::creating(function (MovimentacaoFinanceira $movimentacao) use (&$falhar, $pernas): void {
            if ($falhar && $movimentacao->estorno_de_id === $pernas['entrada']->id) {
                throw new RuntimeException('Falha simulada no estorno.');
            }
        });

        try {
            $this->servico()->estornar($pernas['saida'], 'Lançada errada', $this->admin);
            $this->fail('O estorno deveria ter falhado.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, MovimentacaoFinanceira::query()->whereNotNull('estorno_de_id')->count());
        $this->assertSaldos(500, 700);

        $falhar = false;
        $this->servico()->estornar($pernas['saida'], 'Lançada errada', $this->admin);
        $this->assertSaldos(1000, 200);
    }

    public function test_recalculo_com_transferencias_estornos_e_movimentacoes_antigas_nao_duplica_valores(): void
    {
        $this->pernaAntiga($this->stone, TipoMovimentacao::Saida, 2237.50, '2026-10-01', $uuid = (string) Str::uuid());
        $this->pernaAntiga($this->banco, TipoMovimentacao::Entrada, 2237.50, '2026-10-01', $uuid);
        $this->servico()->transferir($this->stone, $this->banco, 300, Carbon::parse('2026-10-20'), null, $this->admin);
        $estornada = $this->servico()->transferir($this->stone, $this->banco, 150, Carbon::parse('2026-11-10'), null, $this->admin);
        $this->servico()->estornar($estornada['entrada'], 'Lançada errada', $this->admin);
        $this->servico()->transferir($this->banco, $this->stone, 50, today(), null, $this->admin);

        $this->assertSaldos(750, 450);

        foreach ([1, 2] as $vez) {
            foreach ([$this->stone, $this->banco] as $conta) {
                $this->servico()->recalcularSaldo($conta);
            }

            $this->assertSaldos(750, 450);
        }
    }

    public function test_transferencia_igual_a_uma_ja_registrada_exige_confirmacao(): void
    {
        $this->servico()->transferir($this->stone, $this->banco, 200, today(), null, $this->admin, permitirRepetida: false);

        try {
            $this->servico()->transferir($this->stone, $this->banco, 200, today(), null, $this->admin, permitirRepetida: false);
            $this->fail('Aceitou a mesma transferência duas vezes sem confirmação.');
        } catch (FinanceiroException $e) {
            $this->assertStringContainsString('Já existe uma transferência igual', $e->getMessage());
        }

        $this->assertSaldos(800, 400);

        $this->servico()->transferir($this->stone, $this->banco, 200, today(), null, $this->admin, permitirRepetida: true);

        $this->assertSaldos(600, 600);
    }

    public function test_transferencia_estornada_nao_conta_como_repetida(): void
    {
        $pernas = $this->servico()->transferir($this->stone, $this->banco, 200, today(), null, $this->admin);
        $this->servico()->estornar($pernas['saida'], 'Lançada errada', $this->admin);

        $this->servico()->transferir($this->stone, $this->banco, 200, today(), null, $this->admin, permitirRepetida: false);

        $this->assertSaldos(800, 400);
    }

    public function test_segunda_transferencia_da_mesma_conta_ve_o_saldo_ja_debitado_pela_primeira(): void
    {
        $caixinha = ContaFinanceira::factory()->cofre()->create(['data_saldo_inicial' => '2026-10-16']);
        $this->servico()->transferir($this->stone, $this->banco, 700, today(), null, $this->admin);

        $this->expectExceptionMessage('Saldo insuficiente em Stone');

        try {
            $this->servico()->transferir($this->stone, $caixinha, 400, today(), null, $this->admin);
        } finally {
            $this->assertSaldos(300, 900);
            $this->assertEquals(0, $caixinha->fresh()->saldo_atual);
        }
    }

    public function test_tela_pede_confirmacao_para_transferencia_repetida(): void
    {
        $this->servico()->transferir($this->stone, $this->banco, 200, today(), null, $this->admin);
        $dados = [
            'conta_financeira_id' => $this->stone->id,
            'destino_id' => $this->banco->id,
            'valor' => '200,00',
            'data' => today()->toDateString(),
        ];

        Livewire::test(ListContasFinanceiras::class)
            ->callAction('transferir', $dados)
            ->assertNotified('Transferência não feita');

        $this->assertSaldos(800, 400);

        Livewire::test(ListContasFinanceiras::class)
            ->callAction('transferir', [...$dados, 'permitir_repetida' => true])
            ->assertNotified('Transferência registrada');

        $this->assertSaldos(600, 600);
    }

    public function test_comando_redata_estorno_antigo_que_mexeu_no_saldo_e_recalcula(): void
    {
        $saida = $this->pernaAntiga($this->stone, TipoMovimentacao::Saida, 2237.50, '2026-10-01', $uuid = (string) Str::uuid());
        $entrada = $this->pernaAntiga($this->banco, TipoMovimentacao::Entrada, 2237.50, '2026-10-01', $uuid);
        // Estornos como o código antigo gravava: datados do dia do estorno.
        foreach ([[$saida, $this->stone, TipoMovimentacao::Entrada], [$entrada, $this->banco, TipoMovimentacao::Saida]] as [$original, $conta, $tipo]) {
            MovimentacaoFinanceira::factory()->for($conta, 'conta')->create([
                'tipo' => $tipo,
                'natureza' => NaturezaMovimentacao::Estorno,
                'valor' => 2237.50,
                'data_movimento' => today()->toDateString(),
                'transferencia_uuid' => $uuid,
                'estorno_de_id' => $original->id,
            ]);
        }

        foreach ([$this->stone, $this->banco] as $conta) {
            $this->servico()->recalcularSaldo($conta);
        }
        $this->assertSaldos(3237.50, -2037.50);

        $this->artisan('financeiro:corrigir-estornos-fora-do-periodo')->assertSuccessful();
        $this->assertSaldos(3237.50, -2037.50);

        $this->artisan('financeiro:corrigir-estornos-fora-do-periodo', ['--aplicar' => true])->assertSuccessful();
        $this->assertSaldos(1000, 200);
        $this->assertSame(4, MovimentacaoFinanceira::count());
        $this->assertSame(['2026-10-01'], MovimentacaoFinanceira::query()->whereNotNull('estorno_de_id')->get()->map(fn (MovimentacaoFinanceira $m): string => $m->data_movimento->toDateString())->unique()->values()->all());
    }

    /** Perna gravada direto, como as que já existem no banco com data anterior ao saldo inicial. */
    private function pernaAntiga(ContaFinanceira $conta, TipoMovimentacao $tipo, float $valor, string $data, string $uuid): MovimentacaoFinanceira
    {
        return MovimentacaoFinanceira::factory()->for($conta, 'conta')->create([
            'tipo' => $tipo,
            'natureza' => NaturezaMovimentacao::Transferencia,
            'valor' => $valor,
            'data_movimento' => $data,
            'transferencia_uuid' => $uuid,
        ]);
    }

    private function assertSaldos(float $stone, float $banco): void
    {
        $this->assertEquals($stone, $this->stone->fresh()->saldo_atual, 'Saldo da Stone');
        $this->assertEquals($banco, $this->banco->fresh()->saldo_atual, 'Saldo do Banco');
    }

    private function assertRecalculoNaoMuda(): void
    {
        foreach ([$this->stone, $this->banco] as $conta) {
            $saldo = $this->servico()->recalcularSaldo($conta);
            $this->assertSame($saldo['anterior'], $saldo['atual'], "Recálculo mudou o saldo de {$conta->nome}");
        }
    }

    private function servico(): MovimentacaoService
    {
        return app(MovimentacaoService::class);
    }
}
