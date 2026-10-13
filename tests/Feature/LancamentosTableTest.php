<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\Periodicidade;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Resources\Lancamentos\Pages\ListLancamentos;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\Lancamento;
use App\Models\LancamentoDespesa;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LancamentosTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function fornecedor(string $nome): Prestador
    {
        return Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => $nome]);
    }

    /** @param  array<string, mixed>  $atributos */
    private function lancamento(array $atributos = []): Lancamento
    {
        return Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'descricao' => 'Título',
            'valor' => 100,
            'vencimento' => today()->addDays(30)->toDateString(),
            'status' => StatusLancamento::Pendente,
            ...$atributos,
        ]);
    }

    public function test_sem_filtro_aplicado_lista_todos_os_lancamentos(): void
    {
        $lancamentos = collect([
            $this->lancamento(),
            $this->lancamento(['tipo' => TipoLancamento::Receber]),
            $this->lancamento(['status' => StatusLancamento::Pago, 'data_pagamento' => today()]),
        ]);

        Livewire::test(ListLancamentos::class)
            ->assertCanSeeTableRecords($lancamentos);
    }

    public function test_agrupa_por_favorecido_sem_misturar_fornecedor_e_cliente_de_mesmo_id(): void
    {
        $fornecedor = $this->fornecedor('Atacadão');
        $cliente = Cliente::create(['cliente_nome' => 'Bruno', 'cliente_tipo' => 'PF']);

        $pagar = $this->lancamento(['favorecido_id' => $fornecedor->id]);
        $receber = $this->lancamento(['tipo' => TipoLancamento::Receber, 'cliente_id' => $cliente->id]);
        $semFavorecido = $this->lancamento();

        $componente = Livewire::test(ListLancamentos::class)
            ->set('tableGrouping', 'favorecido')
            ->assertCanSeeTableRecords([$pagar, $receber, $semFavorecido])
            ->assertSee('Atacadão')
            ->assertSee('Bruno')
            ->assertSee('Sem favorecido');

        $grupo = $componente->instance()->getTable()->getGrouping();
        $this->assertSame("f{$fornecedor->id}", $grupo->getStringKey($pagar));
        $this->assertSame("c{$cliente->id}", $grupo->getStringKey($receber));
        $this->assertNull($grupo->getStringKey($semFavorecido));
    }

    public function test_filtro_de_plano_de_despesa_encontra_titulo_rateado(): void
    {
        $insumos = PlanoDespesa::create(['nome' => 'Insumos', 'comportamento' => Comportamento::Variavel, 'periodicidade' => Periodicidade::Mensal]);
        $aluguel = PlanoDespesa::create(['nome' => 'Aluguel', 'comportamento' => Comportamento::Fixo, 'periodicidade' => Periodicidade::Mensal]);

        $direto = $this->lancamento(['plano_despesa_id' => $insumos->id]);
        $rateado = $this->lancamento();
        LancamentoDespesa::create(['lancamento_id' => $rateado->id, 'plano_despesa_id' => $insumos->id, 'valor' => 60]);
        LancamentoDespesa::create(['lancamento_id' => $rateado->id, 'plano_despesa_id' => $aluguel->id, 'valor' => 40]);
        $outro = $this->lancamento(['plano_despesa_id' => $aluguel->id]);

        Livewire::test(ListLancamentos::class)
            ->filterTable('plano_despesa', [$insumos->id])
            ->assertCanSeeTableRecords([$direto, $rateado])
            ->assertCanNotSeeTableRecords([$outro]);
    }

    public function test_filtros_de_fornecedor_origem_vencidos_e_valor(): void
    {
        $fornecedor = $this->fornecedor('Distribuidora Sul');
        $doFornecedor = $this->lancamento(['favorecido_id' => $fornecedor->id, 'valor' => 500]);
        $vencido = $this->lancamento(['vencimento' => today()->subDays(3)->toDateString(), 'valor' => 50]);
        $compra = Compra::create(['compra_data_entrada' => today()->toDateString(), 'compra_user_id' => auth()->id()]);
        $deCompra = $this->lancamento(['compra_id' => $compra->id, 'valor' => 80]);

        Livewire::test(ListLancamentos::class)
            ->filterTable('favorecido_id', [$fornecedor->id])
            ->assertCanSeeTableRecords([$doFornecedor])
            ->assertCanNotSeeTableRecords([$vencido, $deCompra])
            ->resetTableFilters()
            ->filterTable('origem', 'compra')
            ->assertCanSeeTableRecords([$deCompra])
            ->assertCanNotSeeTableRecords([$doFornecedor, $vencido])
            ->resetTableFilters()
            ->filterTable('vencidos')
            ->assertCanSeeTableRecords([$vencido])
            ->assertCanNotSeeTableRecords([$doFornecedor, $deCompra])
            ->resetTableFilters()
            ->filterTable('valor', ['valor_min' => '70,00', 'valor_max' => '100,00'])
            ->assertCanSeeTableRecords([$deCompra])
            ->assertCanNotSeeTableRecords([$doFornecedor, $vencido]);
    }

    public function test_filtros_aba_e_agrupamento_ficam_salvos_na_sessao(): void
    {
        $pago = $this->lancamento(['status' => StatusLancamento::Pago, 'data_pagamento' => today()]);
        $pendente = $this->lancamento();

        Livewire::test(ListLancamentos::class)
            ->filterTable('status', [StatusLancamento::Pendente->value])
            ->set('activeTab', 'pagar')
            ->set('tableGrouping', 'favorecido');

        Livewire::test(ListLancamentos::class)
            ->assertSet('activeTab', 'pagar')
            ->assertSet('tableGrouping', 'favorecido')
            ->assertCanSeeTableRecords([$pendente])
            ->assertCanNotSeeTableRecords([$pago]);
    }
}
