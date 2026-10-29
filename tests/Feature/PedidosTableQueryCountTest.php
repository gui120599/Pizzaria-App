<?php

namespace Tests\Feature;

use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Cliente;
use App\Models\Maquininha;
use App\Models\Mesa;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use App\Models\StonePedido;
use App\Models\User;
use App\Services\SessaoMesaService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A lista de pedidos cresce com o histórico. Se o número de consultas subir
 * com a quantidade de linhas (ações da Stone, pagamento, SLA), o banco sente.
 */
class PedidosTableQueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected OpcoesEntregas $delivery;

    protected OpcoesPagamento $cartao;

    protected Maquininha $maquininha;

    protected User $garcom;

    protected int $criados = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Carbon::setTestNow('2026-10-24 12:00:00');

        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $gerente->givePermissionTo([Permission::findOrCreate('view_any:pedido'), Permission::findOrCreate('view:pedido')]);
        $this->actingAs($gerente);

        $this->garcom = User::factory()->create(['name_first' => 'Ana']);
        $this->delivery = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_requer_endereco' => true]);
        $this->maquininha = Maquininha::create(['nome' => 'Balcão', 'operadora' => 'stone', 'maquininha_connect_ativo' => true, 'numero_serie' => '111']);
        $this->cartao = OpcoesPagamento::create([
            'opcaopag_nome' => 'Cartão Stone',
            'opcaopag_desc_nfe' => 'creditCard',
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_stone_integrada' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Pedido completo: cliente, atendente, entrega ou mesa, pagamento combinado na Stone e, em metade deles, cobrança pendente. */
    protected function criarPedidos(int $quantidade): void
    {
        foreach (range(1, $quantidade) as $i) {
            $this->criados++;
            $porMesa = $this->criados % 3 === 0;

            $pedido = Pedido::create([
                'pedido_status' => $this->criados % 2 === 0 ? 'EM TRANSPORTE' : 'PREPARANDO',
                'pedido_valor_total' => 40.0,
                'pedido_cliente_id' => Cliente::create(['cliente_nome' => "Cliente {$this->criados}", 'cliente_tipo' => 'PF', 'cliente_celular' => '6299999'.str_pad((string) $this->criados, 4, '0', STR_PAD_LEFT)])->id,
                'pedido_usuario_garcom_id' => $this->garcom->id,
                'pedido_usuario_entrega_id' => $this->garcom->id,
                'pedido_opcaoentrega_id' => $this->delivery->id,
                'pedido_sessao_mesa_id' => $porMesa
                    ? app(SessaoMesaService::class)->abrir(Mesa::create(['mesa_nome' => "Mesa {$this->criados}", 'mesa_status' => 'LIBERADA'])->id, $this->garcom->id, taxaServicoPercentual: 0.0)->id
                    : null,
                'pedido_datahora_abertura' => now()->subMinutes(60),
                'pedido_datahora_preparo' => now()->subMinutes(40),
                'pedido_datahora_transporte' => now()->subMinutes(50),
            ]);

            PagamentosPedido::create([
                'pg_pedido_pedido_id' => $pedido->id,
                'pg_pedido_opcaopagamento_id' => $this->cartao->id,
                'pg_pedido_opcaopagamento_nome' => $this->cartao->opcaopag_nome,
                'pg_pedido_valor' => 40.0,
                'pg_pedido_ordem' => 0,
            ]);

            if ($this->criados % 2 === 0) {
                StonePedido::create([
                    'stp_pedido_id' => $pedido->id,
                    'stp_maquininha_id' => $this->maquininha->id,
                    'stp_origem' => 'pedido',
                    'stp_order_id' => "or_{$this->criados}",
                    'stp_valor_solicitado' => 40.0,
                    'stp_status' => 'aguardando',
                    'stp_modo' => 'listado',
                ]);
            }
        }
    }

    /**
     * Consultas de um render completo da lista na aba indicada, mostrando
     * `$porPagina` linhas (depois de um render de aquecimento).
     */
    private function consultasDoRender(string $aba, int $porPagina): int
    {
        Livewire::test(ListPedidos::class)->set('activeTab', $aba)->set('tableRecordsPerPage', $porPagina)->assertOk();

        $total = 0;
        DB::listen(function () use (&$total): void {
            $total++;
        });

        Livewire::test(ListPedidos::class)->set('activeTab', $aba)->set('tableRecordsPerPage', $porPagina)->assertOk();

        return $total;
    }

    /**
     * Mesmos 40 pedidos, 5 contra 25 linhas na página: as duas visões têm mais
     * de uma página (o rodapé calcula o mesmo número de totais), então só uma
     * consulta por linha faria a conta mudar.
     */
    public function test_a_quantidade_de_consultas_nao_cresce_com_o_numero_de_linhas(): void
    {
        $this->criarPedidos(40);

        foreach (['todos', 'andamento', 'pendencias'] as $aba) {
            $poucas = $this->consultasDoRender($aba, 5);
            $muitas = $this->consultasDoRender($aba, 25);

            $this->assertSame($poucas, $muitas, "Aba {$aba}: {$poucas} consultas com 5 linhas e {$muitas} com 25 — a lista está consultando por linha.");
        }
    }
}
