<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Filament\Garcom\Pages\AtenderMesa;
use App\Filament\Garcom\Pages\MapaMesas;
use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Models\Venda;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\SessaoMesaService;
use App\Services\VendaService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FecharMesaGarcomTest extends TestCase
{
    use RefreshDatabase;

    private AtendimentoMesaService $service;

    private User $garcom;

    private Mesa $mesa;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('garcom'));

        $this->service = app(AtendimentoMesaService::class);
        $this->garcom = User::factory()->garcom()->create(['name_first' => 'Ana']);
        $this->mesa = Mesa::create(['mesa_nome' => 'Mesa 3', 'mesa_status' => 'LIBERADA']);
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

    private function sessao(): SessaoMesa
    {
        return app(SessaoMesaService::class)->abrir($this->mesa->id, $this->garcom->id, taxaServicoPercentual: 0.0);
    }

    private function item(Pedido $pedido): void
    {
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 50,
            'item_pedido_valor' => 50,
            'item_pedido_desconto' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    private function rodadaEnviada(SessaoMesa $sessao, User $garcom): Pedido
    {
        $rascunho = $this->service->rascunho($sessao, $garcom);
        $this->item($rascunho);
        $this->service->enviarRodada($rascunho);

        return $rascunho->fresh();
    }

    public function test_garcom_da_mesa_fecha_libera_a_mesa_e_cancela_rascunho_vazio(): void
    {
        $sessao = $this->sessao();
        $this->rodadaEnviada($sessao, $this->garcom);
        $rascunhoVazio = $this->service->rascunho($sessao, $this->garcom);

        $this->service->fecharSessao($sessao, $this->garcom);

        $this->assertSame('FECHADA', $sessao->fresh()->sessao_mesa_status);
        $this->assertDatabaseHas(Mesa::class, ['id' => $this->mesa->id, 'mesa_status' => 'LIBERADA', 'mesa_sessao_atual_id' => null]);
        $this->assertSame(StatusPedidoEnum::CANCELADO->value, $rascunhoVazio->fresh()->pedido_status);
        // A mesa já pode receber outro cliente.
        $this->assertNotSame($sessao->id, app(SessaoMesaService::class)->abrir($this->mesa->id, $this->garcom->id)->id);
    }

    public function test_sessao_sem_rodada_fica_cancelada(): void
    {
        $sessao = $this->sessao();

        $this->service->fecharSessao($sessao, $this->garcom);

        $this->assertSame('CANCELADA', $sessao->fresh()->sessao_mesa_status);
    }

    public function test_rascunho_com_itens_bloqueia(): void
    {
        $sessao = $this->sessao();
        $this->item($this->service->rascunho($sessao, $this->garcom));

        $this->expectExceptionMessage('rodada montada e não enviada por Ana');

        $this->service->fecharSessao($sessao, $this->garcom);
    }

    public function test_outro_garcom_e_barrado_e_gerente_fecha(): void
    {
        $sessao = $this->sessao();
        $this->rodadaEnviada($sessao, $this->garcom);
        $colega = User::factory()->garcom()->create(['name_first' => 'Bia']);
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);

        try {
            $this->service->fecharSessao($sessao, $colega);
            $this->fail('Colega não deveria fechar a mesa de outro garçom.');
        } catch (AuthorizationException) {
        }

        $this->service->fecharSessao($sessao, $gerente);
        $this->assertSame('FECHADA', $sessao->fresh()->sessao_mesa_status);
    }

    public function test_conta_fechada_continua_no_caixa_e_finaliza_ao_pagar(): void
    {
        $sessao = $this->sessao();
        $this->rodadaEnviada($sessao, $this->garcom)->update(['pedido_status' => StatusPedidoEnum::ENTREGUE->value]);
        $this->service->fecharSessao($sessao, $this->garcom);

        $admin = User::factory()->admin()->create(['name_first' => 'Caixa']);
        $this->actingAs($admin);
        SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $admin->id,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $pdv = Livewire::test(OperarVenda::class);
        $this->assertTrue($pdv->instance()->mesas()->contains('id', $sessao->id));

        $pdv->call('lancarItensDaMesa', $sessao->id);

        $venda = Venda::sole();
        $this->assertEquals(50.00, $venda->venda_valor_total);
        // Lançar não finaliza: a mesa só sai do caixa quando a venda fecha.
        $this->assertSame('FECHADA', $sessao->fresh()->sessao_mesa_status);

        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_valor_pagamento' => 50.00,
            'pg_venda_valor_pago_pelo_cliente' => 50.00,
        ]);
        app(VendaService::class)->atualizarValoresdaVenda($venda->id);

        Livewire::test(OperarVenda::class, ['venda' => $venda])->call('finalizarVenda');

        $this->assertSame('FINALIZADA', $venda->fresh()->venda_status);
        $this->assertSame('FINALIZADA', $sessao->fresh()->sessao_mesa_status);
    }

    public function test_botao_fechar_mesa_na_tela(): void
    {
        $this->actingAs($this->garcom);
        $sessao = $this->sessao();
        $this->rodadaEnviada($sessao, $this->garcom);

        Livewire::test(AtenderMesa::class, ['sessao' => $sessao->id])
            ->set('aba', 'conta')
            ->assertSee('Fechar mesa')
            ->callAction('fecharMesa')
            ->assertRedirect(MapaMesas::getUrl());

        $this->assertSame('FECHADA', $sessao->fresh()->sessao_mesa_status);
    }
}
