<?php

namespace Tests\Feature;

use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Pages\PainelPedidos;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PainelPedidosTest extends TestCase
{
    use RefreshDatabase;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        // Meio do turno: 20:00 cai na janela 07:00 → 03:00 do dia seguinte.
        Carbon::setTestNow('2026-09-30 20:00:00');

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * `view_any:pedido` é uma permission de CRUD gerada pelo Filament Shield
     * (ShieldSeeder), não pelo PermissionSeeder — que só cobre as permissions de
     * negócio. Em produção as roles já a têm; aqui basta criá-la e atribuir.
     */
    private function gerente(): User
    {
        $user = User::factory()->create(['name_first' => 'Gerente']);
        $user->assignRole('Gerente');
        $user->givePermissionTo(Permission::firstOrCreate([
            'name' => 'view_any:pedido',
            'guard_name' => 'web',
        ]));

        return $user;
    }

    /** Pedido com um item INSERIDO — sem item, o board descarta o card. */
    private function pedido(StatusPedidoEnum $status, array $extras = []): Pedido
    {
        $pedido = Pedido::create($extras + [
            'pedido_status' => $status->value,
            'pedido_datahora_abertura' => Carbon::now(),
        ]);

        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 40,
            'item_pedido_valor' => 40,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return $pedido;
    }

    public function test_usuario_sem_permissao_nao_acessa_a_pagina(): void
    {
        $this->actingAs(User::factory()->create(['name_first' => 'Zé']));

        $this->assertFalse(PainelPedidos::canAccess());
    }

    public function test_gerente_acessa_e_a_pagina_renderiza(): void
    {
        $this->actingAs($this->gerente());

        Livewire::test(PainelPedidos::class)->assertOk();
    }

    public function test_cada_pedido_aparece_na_coluna_do_seu_status(): void
    {
        $this->actingAs($this->gerente());

        $aberto = $this->pedido(StatusPedidoEnum::ABERTO);
        $preparando = $this->pedido(StatusPedidoEnum::PREPARANDO);

        $colunas = Livewire::test(PainelPedidos::class)->get('colunas');

        $this->assertSame([$aberto->id], $colunas['ABERTO']->pluck('id')->all());
        $this->assertSame([$preparando->id], $colunas['PREPARANDO']->pluck('id')->all());
    }

    public function test_pedido_cancelado_nao_aparece_no_board(): void
    {
        $this->actingAs($this->gerente());

        $this->pedido(StatusPedidoEnum::CANCELADO);

        $colunas = Livewire::test(PainelPedidos::class)->get('colunas');

        $this->assertSame(0, collect($colunas)->sum(fn ($c) => $c->count()));
    }

    /**
     * INICIADO de outras origens é rascunho em construção no AtenderPedido —
     * mostrá-lo na fila da cozinha confundiria a operação.
     */
    public function test_coluna_iniciados_so_mostra_pedido_do_cardapio(): void
    {
        $this->actingAs($this->gerente());

        $doCardapio = $this->pedido(StatusPedidoEnum::INICIADO, [
            'pedido_origem' => PedidoOrigemEnum::CARDAPIO->value,
        ]);

        $this->pedido(StatusPedidoEnum::INICIADO, [
            'pedido_origem' => PedidoOrigemEnum::ATENDENTE->value,
        ]);

        $colunas = Livewire::test(PainelPedidos::class)->get('colunas');

        $this->assertSame([$doCardapio->id], $colunas['INICIADO']->pluck('id')->all());
    }

    public function test_pedido_sem_item_inserido_nao_gera_card(): void
    {
        $this->actingAs($this->gerente());

        // Sem ItensPedido nenhum.
        Pedido::create([
            'pedido_status' => StatusPedidoEnum::ABERTO->value,
            'pedido_datahora_abertura' => Carbon::now(),
        ]);

        $colunas = Livewire::test(PainelPedidos::class)->get('colunas');

        $this->assertCount(0, $colunas['ABERTO']);
    }

    public function test_pedido_de_turno_anterior_sai_do_board_e_entra_na_contagem_de_atrasados(): void
    {
        $this->actingAs($this->gerente());

        $antigo = $this->pedido(StatusPedidoEnum::PREPARANDO, [
            'pedido_datahora_abertura' => Carbon::now()->copy()->subDays(2),
        ]);

        $componente = Livewire::test(PainelPedidos::class);

        $this->assertCount(0, $componente->get('colunas')['PREPARANDO']);
        $this->assertSame(1, $componente->get('totalForaDoTurno'));

        // Ao ligar o filtro, o pedido preso aparece.
        $componente->call('alternarForaDoTurno');

        $this->assertSame([$antigo->id], $componente->get('colunas')['PREPARANDO']->pluck('id')->all());
    }

    public function test_coluna_entregue_respeita_a_janela_do_turno_escolhido(): void
    {
        $this->actingAs($this->gerente());

        // 01:00 de 01/10 ainda pertence ao turno aberto em 30/09.
        $desteTurno = $this->pedido(StatusPedidoEnum::ENTREGUE, [
            'pedido_datahora_entrega' => Carbon::parse('2026-10-01 01:00:00'),
        ]);

        // 05:00 já é o turno seguinte (fechamento às 03:00).
        $this->pedido(StatusPedidoEnum::ENTREGUE, [
            'pedido_datahora_entrega' => Carbon::parse('2026-10-01 05:00:00'),
        ]);

        $entregues = Livewire::test(PainelPedidos::class)
            ->set('dataEntregue', '2026-09-30')
            ->get('entregues');

        $this->assertSame([$desteTurno->id], $entregues->pluck('id')->all());
    }

    public function test_avancar_um_pedido_move_e_notifica(): void
    {
        $this->actingAs($this->gerente());

        $pedido = $this->pedido(StatusPedidoEnum::PREPARANDO);

        Livewire::test(PainelPedidos::class)
            ->callAction('avancar', arguments: ['pedido' => $pedido->id])
            ->assertNotified();

        $this->assertSame(StatusPedidoEnum::PRONTO->value, $pedido->refresh()->pedido_status);
    }

    public function test_cancelar_exige_motivo_e_grava_o_escolhido(): void
    {
        $this->actingAs($this->gerente());

        $pedido = $this->pedido(StatusPedidoEnum::PREPARANDO);

        Livewire::test(PainelPedidos::class)
            ->callAction('cancelar', data: ['motivo' => 'produto_indisponivel'], arguments: ['pedido' => $pedido->id])
            ->assertHasNoActionErrors();

        $pedido->refresh();

        $this->assertSame(StatusPedidoEnum::CANCELADO->value, $pedido->pedido_status);
        // A tela legada gravava sempre OUTRO, sem perguntar.
        $this->assertSame('produto_indisponivel', $pedido->pedido_motivo_cancelamento->value);
    }

    public function test_cancelar_sem_motivo_e_recusado(): void
    {
        $this->actingAs($this->gerente());

        $pedido = $this->pedido(StatusPedidoEnum::PREPARANDO);

        Livewire::test(PainelPedidos::class)
            ->callAction('cancelar', data: ['motivo' => null], arguments: ['pedido' => $pedido->id])
            ->assertHasActionErrors(['motivo' => 'required']);

        $this->assertSame(StatusPedidoEnum::PREPARANDO->value, $pedido->refresh()->pedido_status);
    }

    public function test_pedido_de_balcao_avanca_de_pronto_direto_para_entregue(): void
    {
        $this->actingAs($this->gerente());

        $balcao = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Balcão',
            'opcaoentrega_requer_endereco' => false,
        ]);

        $pedido = $this->pedido(StatusPedidoEnum::PRONTO, ['pedido_opcaoentrega_id' => $balcao->id]);

        Livewire::test(PainelPedidos::class)
            ->callAction('avancar', arguments: ['pedido' => $pedido->id]);

        $this->assertSame(StatusPedidoEnum::ENTREGUE->value, $pedido->refresh()->pedido_status);
    }

    /**
     * O poll só deve invalidar as listas quando algo mudou de fato — é o que
     * mantém a tela barata rodando o dia inteiro.
     */
    public function test_poll_sem_mudanca_mantem_a_assinatura(): void
    {
        $this->actingAs($this->gerente());

        $this->pedido(StatusPedidoEnum::ABERTO);

        $componente = Livewire::test(PainelPedidos::class);
        $assinatura = $componente->get('assinatura');

        $this->assertNotSame('', $assinatura);

        $componente->call('atualizar');

        $this->assertSame($assinatura, $componente->get('assinatura'));
    }

    public function test_poll_detecta_transicao_feita_por_query_builder(): void
    {
        $this->actingAs($this->gerente());

        $pedido = $this->pedido(StatusPedidoEnum::PRONTO);

        $componente = Livewire::test(PainelPedidos::class);
        $assinatura = $componente->get('assinatura');

        // EntregaService::aceitar() e ConfirmacoesPedidos::confirmar() gravam
        // assim, sem tocar updated_at. Só MAX(updated_at) não veria isto.
        Pedido::where('id', $pedido->id)->update(['pedido_status' => StatusPedidoEnum::EM_TRANSPORTE->value]);

        $componente->call('atualizar');

        $this->assertNotSame($assinatura, $componente->get('assinatura'));
    }
}
