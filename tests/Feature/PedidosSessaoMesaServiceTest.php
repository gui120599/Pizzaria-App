<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Garcom\Pages\AtenderMesa;
use App\Filament\Pages\OperarVenda;
use App\Filament\Resources\SessoesMesa\Pages\EditSessaoMesa;
use App\Filament\Resources\SessoesMesa\RelationManagers\PedidosRelationManager;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\SessaoMesaCliente;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\PedidosSessaoMesaService;
use App\Services\SessaoMesaService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PedidosSessaoMesaServiceTest extends TestCase
{
    use RefreshDatabase;

    private PedidosSessaoMesaService $service;

    private User $garcom;

    private Produto $produto;

    private int $mesas = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PedidosSessaoMesaService::class);
        $this->garcom = User::factory()->garcom()->create(['name_first' => 'Ana']);
        // PedidoObserver grava a movimentação entre mesas com o usuário logado.
        $this->actingAs($this->garcom);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
            'produto_valor_percentual_icms' => 10,
            'produto_valor_percentual_pis' => 1,
            'produto_valor_percentual_cofins' => 2,
        ]);
    }

    private function sessao(?User $garcom = null): SessaoMesa
    {
        $mesa = Mesa::create(['mesa_nome' => 'Mesa '.++$this->mesas, 'mesa_status' => 'LIBERADA']);

        return app(SessaoMesaService::class)->abrir($mesa->id, ($garcom ?? $this->garcom)->id, taxaServicoPercentual: 0.0);
    }

    private function pedido(string $status = 'PRONTO', ?SessaoMesa $sessao = null, ?int $clienteDoItem = null): Pedido
    {
        $pedido = Pedido::create([
            'pedido_status' => $status,
            'pedido_sessao_mesa_id' => $sessao?->id,
            'pedido_valor_total' => 50,
            'pedido_datahora_abertura' => now(),
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_cliente_id' => $clienteDoItem,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 50,
            'item_pedido_valor' => 50,
            'item_pedido_desconto' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_adiciona_pedido_sem_mesa_e_de_outra_mesa_trazendo_as_pessoas_dos_itens(): void
    {
        $sessao = $this->sessao();
        $outraMesa = $this->sessao();
        $joao = Cliente::create(['cliente_nome' => 'João', 'cliente_tipo' => 'Física']);
        $avulso = $this->pedido();
        $daOutraMesa = $this->pedido('ENTREGUE', $outraMesa, $joao->id);

        $this->assertEqualsCanonicalizing(
            [$avulso->id, $daOutraMesa->id],
            $this->service->elegiveisParaAdicionar($sessao)->pluck('id')->all(),
        );

        $this->assertSame(2, $this->service->adicionar($sessao, [$avulso->id, $daOutraMesa->id], $this->garcom));

        $this->assertSame($sessao->id, $avulso->fresh()->pedido_sessao_mesa_id);
        $this->assertSame($sessao->id, $daOutraMesa->fresh()->pedido_sessao_mesa_id);
        $this->assertDatabaseHas(SessaoMesaCliente::class, ['smc_sessao_mesa_id' => $sessao->id, 'smc_cliente_id' => $joao->id]);
    }

    public function test_pedido_rascunho_cancelado_pago_ou_lancado_no_caixa_nao_muda_de_conta(): void
    {
        $sessao = $this->sessao();
        $fixos = collect(['INICIADO', 'CANCELADO', 'FINALIZADO'])->map(fn ($status) => $this->pedido($status));

        $noCaixa = $this->pedido();
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_valor_total' => 50, 'venda_valor_pago' => 0, 'venda_valor_troco' => 0]);
        $noCaixa->item_pedido_pedido_id()->update(['item_pedido_venda_id' => $venda->id]);

        $naMaquininha = $this->pedido();
        StonePedido::create([
            'stp_pedido_id' => $naMaquininha->id,
            'stp_order_id' => 'or_teste',
            'stp_valor_solicitado' => 50,
            'stp_status' => 'aguardando',
        ]);

        $ids = [...$fixos->pluck('id'), $noCaixa->id, $naMaquininha->id];

        $this->assertSame([], array_intersect($ids, $this->service->elegiveisParaAdicionar($sessao)->pluck('id')->all()));

        $this->expectException(RuntimeException::class);

        try {
            $this->service->adicionar($sessao, $ids, $this->garcom);
        } finally {
            $this->assertSame(0, Pedido::where('pedido_sessao_mesa_id', $sessao->id)->whereIn('id', $ids)->count());
        }
    }

    public function test_garcom_nao_puxa_pedido_da_mesa_de_outro_garcom_mas_gerente_puxa(): void
    {
        $sessao = $this->sessao();
        $mesaDoBeto = $this->sessao(User::factory()->garcom()->create(['name_first' => 'Beto']));
        $pedido = $this->pedido('PRONTO', $mesaDoBeto);
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gil']);

        $this->assertArrayNotHasKey($pedido->id, $this->service->opcoesParaAdicionar($sessao, $this->garcom)['opcoes']);
        $this->assertArrayHasKey($pedido->id, $this->service->opcoesParaAdicionar($sessao, $gerente)['opcoes']);

        try {
            $this->service->adicionar($sessao, [$pedido->id], $this->garcom);
            $this->fail('Garçom moveu pedido da mesa de outro garçom.');
        } catch (RuntimeException) {
            $this->assertSame($mesaDoBeto->id, $pedido->fresh()->pedido_sessao_mesa_id);
        }

        $this->service->adicionar($sessao, [$pedido->id], $gerente);

        $this->assertSame($sessao->id, $pedido->fresh()->pedido_sessao_mesa_id);
    }

    public function test_so_o_garcom_da_mesa_ou_gerente_altera_a_conta(): void
    {
        $sessao = $this->sessao();

        $this->expectException(AuthorizationException::class);

        $this->service->adicionar($sessao, [$this->pedido()->id], User::factory()->garcom()->create(['name_first' => 'Beto']));
    }

    public function test_conta_fechada_ou_com_cobranca_stone_aguardando_recusa(): void
    {
        $sessao = $this->sessao();
        $rodada = $this->pedido('PRONTO', $sessao);
        StonePedido::create([
            'stp_sessao_mesa_id' => $sessao->id,
            'stp_order_id' => 'or_mesa',
            'stp_valor_solicitado' => 50,
            'stp_status' => 'aguardando',
        ]);

        try {
            $this->service->remover($sessao, [$rodada->id], $this->garcom);
            $this->fail('Removeu rodada com cobrança Stone aguardando.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('maquininha', $e->getMessage());
        }

        StonePedido::query()->update(['stp_status' => 'cancelado']);
        app(SessaoMesaService::class)->fechar($sessao);

        $this->expectExceptionMessage('não está mais aberta');

        $this->service->remover($sessao, [$rodada->id], $this->garcom);
    }

    public function test_pedido_removido_fica_sem_mesa_e_aparece_nos_avulsos_do_caixa(): void
    {
        $sessao = $this->sessao();
        $rodada = $this->pedido('PREPARANDO', $sessao);

        $this->assertSame(1, $this->service->remover($sessao, [$rodada->id], $this->garcom));
        $this->assertNull($rodada->fresh()->pedido_sessao_mesa_id);

        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($admin);
        SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $admin->id,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $avulsos = Livewire::test(OperarVenda::class)->instance()->pedidosAvulsos();

        $this->assertContains($rodada->id, $avulsos->pluck('id')->all());
    }

    public function test_relation_manager_do_admin_adiciona_e_remove(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $sessao = $this->sessao();
        $rodada = $this->pedido('PRONTO', $sessao);
        $avulso = $this->pedido();
        // A aba de pedidos exige a policy viewAny de Pedido (permissão do Shield).
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gil']);
        $gerente->givePermissionTo(Permission::findOrCreate('view_any:pedido'));
        $this->actingAs($gerente);

        $relationManager = fn () => Livewire::test(PedidosRelationManager::class, [
            'ownerRecord' => $sessao,
            'pageClass' => EditSessaoMesa::class,
        ]);

        $relationManager()
            ->callAction(TestAction::make('adicionarExistentes')->table(), ['pedidos' => [$avulso->id]])
            ->assertHasNoActionErrors()
            ->assertNotified('1 pedido(s) adicionado(s) à mesa.');

        $relationManager()
            ->callAction(TestAction::make('removerDaMesa')->table($rodada))
            ->assertNotified('1 pedido(s) removido(s) da mesa.');

        $this->assertSame($sessao->id, $avulso->fresh()->pedido_sessao_mesa_id);
        $this->assertNull($rodada->fresh()->pedido_sessao_mesa_id);
    }

    public function test_relation_manager_esconde_as_acoes_de_quem_nao_e_dono_da_mesa(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $sessao = $this->sessao();
        $rodada = $this->pedido('PRONTO', $sessao);
        $outroGarcom = User::factory()->garcom()->create(['name_first' => 'Beto']);
        $outroGarcom->givePermissionTo(Permission::findOrCreate('view_any:pedido'));
        $this->actingAs($outroGarcom);

        Livewire::test(PedidosRelationManager::class, [
            'ownerRecord' => $sessao,
            'pageClass' => EditSessaoMesa::class,
        ])
            ->assertActionHidden(TestAction::make('adicionarExistentes')->table())
            ->assertActionHidden(TestAction::make('removerDaMesa')->table($rodada));
    }

    public function test_tela_legada_usa_as_mesmas_regras(): void
    {
        $sessao = $this->sessao();
        $rodada = $this->pedido('PRONTO', $sessao);
        $finalizado = $this->pedido('FINALIZADO');

        $this->patch(route('sessaoMesa.updateRemoverPedidosSessaoMesa', $sessao), ['pedidoExistente' => [$rodada->id]])
            ->assertSessionHas('success', 'Pedidos removidos!');

        $this->patch(route('sessaoMesa.updateAdicionarExistentes', $sessao), ['pedidoExistente' => [$finalizado->id]])
            ->assertSessionHas('error');

        $this->assertNull($rodada->fresh()->pedido_sessao_mesa_id);
        $this->assertNull($finalizado->fresh()->pedido_sessao_mesa_id);
    }

    public function test_garcom_traz_e_tira_pedido_pela_tela_da_mesa(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('garcom'));
        $sessao = $this->sessao();
        $rodada = $this->pedido('PRONTO', $sessao);
        $avulso = $this->pedido();
        $this->actingAs($this->garcom);

        $pagina = fn () => Livewire::test(AtenderMesa::class, ['sessao' => $sessao->id]);

        $pagina()
            ->set('aba', 'rodadas')
            ->assertSee('Trazer pedido existente')
            ->assertSee('Tirar da mesa')
            ->callAction('adicionarPedido', ['pedidos' => [$avulso->id]])
            ->assertHasNoActionErrors()
            ->assertNotified('1 pedido(s) trazido(s) para a mesa.');

        $pagina()
            ->callAction('removerPedido', arguments: ['pedido' => $rodada->id])
            ->assertNotified('1 pedido(s) tirado(s) da mesa.');

        $this->assertSame($sessao->id, $avulso->fresh()->pedido_sessao_mesa_id);
        $this->assertNull($rodada->fresh()->pedido_sessao_mesa_id);
        $this->assertSame(StatusPedidoEnum::PRONTO->value, $rodada->fresh()->pedido_status);
        // O rascunho da rodada do garçom continua na mesa.
        $this->assertSame($sessao->id, app(AtendimentoMesaService::class)->rascunho($sessao, $this->garcom)->pedido_sessao_mesa_id);
    }
}
