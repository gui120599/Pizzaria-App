<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Abas da lista de pedidos (Em andamento, Pendências, Concluídos, Cancelados,
 * Todos, Rascunhos, Lixeira), a janela padrão de 7 dias, as colunas de
 * pagamento e SLA e o rodapé.
 */
class PedidosTableAbasTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $agora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->agora = Carbon::parse('2026-10-24 12:00:00');
        Carbon::setTestNow($this->agora);

        $this->actingAs($this->usuario('gerente'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** view_any/view vêm do Shield em produção; o PermissionSeeder só cobre as de negócio. */
    private function usuario(string $role): User
    {
        $user = User::factory()->{$role}()->create(['name_first' => ucfirst($role)]);
        $user->givePermissionTo([Permission::findOrCreate('view_any:pedido'), Permission::findOrCreate('view:pedido')]);

        return $user;
    }

    private function pedido(string $status, array $extra = []): Pedido
    {
        return Pedido::create(array_merge(
            ['pedido_status' => $status, 'pedido_valor_total' => 25.0, 'pedido_datahora_abertura' => $this->agora->copy()->subHour()],
            $extra,
        ));
    }

    private function itemVendido(Pedido $pedido): void
    {
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 10.0]);
        $produto = Produto::create([
            'produto_descricao' => 'Refrigerante Lata',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas'])->id,
            'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 10.0,
            'item_pedido_valor' => 10.0,
            'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => $venda->id,
        ]);
    }

    public function test_cada_aba_lista_so_o_seu_conjunto(): void
    {
        $andamento = $this->pedido('PREPARANDO', ['pedido_datahora_preparo' => $this->agora->copy()->subMinutes(5)]);
        $pendurado = $this->pedido('ENTREGUE');
        $concluido = $this->pedido('FINALIZADO', ['pedido_venda_id' => Venda::create(['venda_status' => 'FINALIZADA'])->id]);
        $entreguePago = $this->pedido('ENTREGUE', ['pedido_venda_id' => Venda::create(['venda_status' => 'INICIADA'])->id]);
        $cancelado = $this->pedido('CANCELADO');
        $rascunho = $this->pedido('INICIADO');
        $excluido = $this->pedido('CANCELADO');
        $excluido->delete();

        $lista = Livewire::test(ListPedidos::class);

        $lista->set('activeTab', 'andamento')
            ->assertCanSeeTableRecords([$andamento])
            ->assertCanNotSeeTableRecords([$pendurado, $concluido, $cancelado, $rascunho]);

        $lista->set('activeTab', 'pendencias')
            ->assertCanSeeTableRecords([$pendurado])
            ->assertCanNotSeeTableRecords([$andamento, $concluido, $entreguePago]);

        $lista->set('activeTab', 'concluidos')
            ->assertCanSeeTableRecords([$concluido, $entreguePago])
            ->assertCanNotSeeTableRecords([$pendurado, $andamento, $cancelado]);

        $lista->set('activeTab', 'cancelados')
            ->assertCanSeeTableRecords([$cancelado])
            ->assertCanNotSeeTableRecords([$excluido, $concluido]);

        $lista->set('activeTab', 'todos')
            ->assertCanSeeTableRecords([$andamento, $pendurado, $concluido, $cancelado])
            ->assertCanNotSeeTableRecords([$rascunho, $excluido]);

        $lista->set('activeTab', 'rascunhos')
            ->assertCanSeeTableRecords([$rascunho])
            ->assertCanNotSeeTableRecords([$andamento, $cancelado]);

        $lista->set('activeTab', 'lixeira')
            ->assertCanSeeTableRecords([$excluido])
            ->assertCanNotSeeTableRecords([$cancelado, $rascunho]);
    }

    public function test_pedido_parado_alem_do_sla_entra_nas_pendencias(): void
    {
        $parado = $this->pedido('ABERTO', ['pedido_datahora_abertura' => $this->agora->copy()->subHours(5)]);
        $noPrazo = $this->pedido('ABERTO', ['pedido_datahora_abertura' => $this->agora->copy()->subMinutes(2)]);

        Livewire::test(ListPedidos::class)
            ->set('activeTab', 'pendencias')
            ->assertCanSeeTableRecords([$parado])
            ->assertCanNotSeeTableRecords([$noPrazo]);
    }

    public function test_abas_de_historico_abrem_nos_ultimos_7_dias_e_os_demais_estados_nao_tem_janela(): void
    {
        $recente = $this->pedido('FINALIZADO');
        $antigo = $this->pedido('FINALIZADO', ['pedido_datahora_abertura' => $this->agora->copy()->subDays(7)]);
        $naBorda = $this->pedido('FINALIZADO', ['pedido_datahora_abertura' => $this->agora->copy()->subDays(6)->startOfDay()]);
        $penduradoAntigo = $this->pedido('ENTREGUE', ['pedido_datahora_abertura' => $this->agora->copy()->subDays(40)]);

        Livewire::test(ListPedidos::class)
            ->set('activeTab', 'todos')
            ->assertCanSeeTableRecords([$recente, $naBorda])
            ->assertCanNotSeeTableRecords([$antigo, $penduradoAntigo])
            ->assertSee('Mostrando os últimos 7 dias')
            ->set('activeTab', 'pendencias')
            ->assertCanSeeTableRecords([$penduradoAntigo])
            ->assertDontSee('Mostrando os últimos 7 dias');
    }

    public function test_busca_e_periodo_escolhido_substituem_a_janela_padrao(): void
    {
        $antigo = $this->pedido('FINALIZADO', ['pedido_datahora_abertura' => $this->agora->copy()->subDays(30)]);
        $outroAntigo = $this->pedido('FINALIZADO', ['pedido_datahora_abertura' => $this->agora->copy()->subDays(31)]);

        Livewire::test(ListPedidos::class)
            ->assertCanNotSeeTableRecords([$antigo, $outroAntigo])
            ->searchTable((string) $antigo->id)
            ->assertCanSeeTableRecords([$antigo])
            ->assertCanNotSeeTableRecords([$outroAntigo])
            ->assertDontSee('Mostrando os últimos 7 dias')
            ->searchTable('')
            ->filterTable('periodo', ['de' => $this->agora->copy()->subDays(35)->toDateString(), 'ate' => $this->agora->copy()->subDays(20)->toDateString()])
            ->assertCanSeeTableRecords([$antigo, $outroAntigo])
            ->assertDontSee('Mostrando os últimos 7 dias');
    }

    public function test_contadores_das_abas(): void
    {
        $this->pedido('ENTREGUE');
        $this->pedido('ENTREGUE', ['pedido_venda_id' => Venda::create(['venda_status' => 'INICIADA'])->id]);
        $this->pedido('ABERTO', ['pedido_datahora_abertura' => $this->agora->copy()->subHours(3)]);
        $this->pedido('PREPARANDO', ['pedido_datahora_preparo' => $this->agora]);
        $this->pedido('INICIADO');
        $this->pedido('INICIADO');

        $abas = Livewire::test(ListPedidos::class)->instance()->getCachedTabs();

        // Em andamento: ABERTO + PREPARANDO. Pendências: o ENTREGUE sem pagamento e o ABERTO parado.
        $this->assertSame('2', (string) $abas['andamento']->getBadge());
        $this->assertSame('2', (string) $abas['pendencias']->getBadge());
        $this->assertSame('2', (string) $abas['rascunhos']->getBadge());
    }

    public function test_lixeira_so_aparece_para_quem_pode_restaurar(): void
    {
        $atendente = $this->usuario('atendente');
        $this->actingAs($atendente);

        $abas = Livewire::test(ListPedidos::class)->instance()->getCachedTabs();

        $this->assertFalse($abas['lixeira']->isVisible());
        $this->assertTrue($abas['pendencias']->isVisible());
    }

    public function test_coluna_pagamento_e_o_filtro_de_situacao_concordam(): void
    {
        $pago = $this->pedido('FINALIZADO', ['pedido_venda_id' => Venda::create(['venda_status' => 'FINALIZADA'])->id]);
        $finalizadoAntigo = $this->pedido('FINALIZADO');
        $parcial = $this->pedido('ENTREGUE');
        $this->itemVendido($parcial);
        $aReceber = $this->pedido('ENTREGUE');
        $preparando = $this->pedido('PREPARANDO');
        $cancelado = $this->pedido('CANCELADO');

        $lista = Livewire::test(ListPedidos::class);

        $lista->filterTable('situacao_pagamento', 'pago')
            ->assertCanSeeTableRecords([$pago, $finalizadoAntigo])
            ->assertCanNotSeeTableRecords([$parcial, $aReceber, $preparando, $cancelado])
            ->filterTable('situacao_pagamento', 'parcial')
            ->assertCanSeeTableRecords([$parcial])
            ->assertCanNotSeeTableRecords([$pago, $aReceber, $preparando, $cancelado])
            ->filterTable('situacao_pagamento', 'a_receber')
            ->assertCanSeeTableRecords([$aReceber, $preparando])
            ->assertCanNotSeeTableRecords([$pago, $finalizadoAntigo, $parcial, $cancelado])
            ->removeTableFilter('situacao_pagamento')
            ->assertTableColumnStateSet('pagamento', 'pago', $pago)
            ->assertTableColumnStateSet('pagamento', 'pago', $finalizadoAntigo)
            ->assertTableColumnStateSet('pagamento', 'parcial', $parcial)
            ->assertTableColumnStateSet('pagamento', 'a_receber', $aReceber)
            ->assertTableColumnStateSet('pagamento', 'a_receber', $preparando)
            ->assertTableColumnStateSet('pagamento', null, $cancelado);
    }

    public function test_coluna_sla_so_aparece_na_aba_em_andamento(): void
    {
        $atrasado = $this->pedido('ABERTO', ['pedido_datahora_abertura' => $this->agora->copy()->subMinutes(30)]);

        Livewire::test(ListPedidos::class)
            ->set('activeTab', 'andamento')
            ->assertTableColumnVisible('sla')
            ->assertTableColumnStateSet('sla', 'atrasado', $atrasado)
            ->set('activeTab', 'todos')
            ->assertTableColumnHidden('sla');
    }

    public function test_rodape_soma_o_valor_dos_pedidos_sem_os_cancelados(): void
    {
        $this->pedido('FINALIZADO', ['pedido_valor_total' => 100.0]);
        $this->pedido('ENTREGUE', ['pedido_valor_total' => 50.0]);
        $this->pedido('CANCELADO', ['pedido_valor_total' => 999.0]);

        Livewire::test(ListPedidos::class)
            ->set('activeTab', 'todos')
            ->assertTableColumnSummarySet('pedido_valor_total', 'sum', 150);
    }
}
