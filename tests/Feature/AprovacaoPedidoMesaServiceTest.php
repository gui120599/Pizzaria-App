<?php

namespace Tests\Feature;

use App\Enums\EstoqueModoControleEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusChamadoMesaEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Exceptions\EstoqueInsuficienteException;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\MesaParticipante;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\ItemSolicitado;
use App\Services\MesaCliente\AprovacaoPedidoMesaService;
use App\Services\MesaCliente\MesaChamadoService;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\MesaCliente\PedidoMesaClienteService;
use App\Services\SessaoMesaService;
use App\Support\ContaMesa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Decisão do garçom sobre o pedido do QR que esperava aprovação, e o que
 * acontece com ele quando a mesa fecha.
 */
class AprovacaoPedidoMesaServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $garcom;

    public function test_aprovar_manda_para_a_cozinha_e_registra_quem_aprovou(): void
    {
        $pendente = $this->pedidoPendente();

        $aprovou = $this->servico()->aprovar($pendente, $this->garcom);

        $pedido = $pendente->fresh();
        $this->assertTrue($aprovou);
        $this->assertSame('ABERTO', $pedido->pedido_status);
        $this->assertSame(StatusAprovacaoPedidoEnum::APROVADO, $pedido->pedido_aprovacao_status);
        $this->assertSame($this->garcom->id, $pedido->pedido_aprovado_por_id);
        $this->assertNotNull($pedido->pedido_aprovado_em);
        $this->assertSame(8.0, ContaMesa::subtotal($pedido->pedido_sessao_mesa_id));
    }

    public function test_aprovar_de_novo_nao_faz_nada(): void
    {
        $pendente = $this->pedidoPendente();
        $this->servico()->aprovar($pendente, $this->garcom);

        $this->assertFalse($this->servico()->aprovar($pendente, $this->garcom));
        $this->assertSame('ABERTO', $pendente->fresh()->pedido_status);
    }

    public function test_aprovar_sem_estoque_em_modo_bloquear_mantem_o_pedido_esperando(): void
    {
        $pendente = $this->pedidoPendente();
        $pendente->item_pedido_pedido_id()->first()->produto->update([
            'produto_controla_estoque' => true,
            'produto_modo_controle_estoque' => EstoqueModoControleEnum::BLOQUEAR,
            'produto_saldo_estoque' => 0,
        ]);

        try {
            $this->servico()->aprovar($pendente, $this->garcom);
            $this->fail('Era esperado EstoqueInsuficienteException.');
        } catch (EstoqueInsuficienteException) {
        }

        $this->assertTrue($pendente->fresh()->aguardandoAprovacao());
    }

    public function test_recusar_cancela_com_o_motivo_e_quem_recusou(): void
    {
        $pendente = $this->pedidoPendente();

        $this->servico()->recusar($pendente, $this->garcom, 'Item acabou');

        $pedido = $pendente->fresh();
        $this->assertSame('CANCELADO', $pedido->pedido_status);
        $this->assertSame(StatusAprovacaoPedidoEnum::RECUSADO, $pedido->pedido_aprovacao_status);
        $this->assertSame('Item acabou', $pedido->pedido_recusa_motivo);
        $this->assertSame($this->garcom->id, $pedido->pedido_aprovado_por_id);
    }

    public function test_recusar_sem_motivo_e_recusado(): void
    {
        $pendente = $this->pedidoPendente();

        $this->expectExceptionObject(new RuntimeException('Informe o motivo da recusa.'));

        $this->servico()->recusar($pendente, $this->garcom, '  ');
    }

    public function test_fechar_a_mesa_recusa_os_pendentes_e_cancela_os_chamados(): void
    {
        $pendente = $this->pedidoPendente();
        $participante = $pendente->participante;
        $chamado = app(MesaChamadoService::class)->abrir($participante->sessaoMesa->mesa, TipoChamadoMesaEnum::CHAMAR_GARCOM, $participante);
        $this->actingAs($this->garcom);

        app(AtendimentoMesaService::class)->fecharSessao($participante->sessaoMesa, $this->garcom);

        $pedido = $pendente->fresh();
        $this->assertSame(StatusAprovacaoPedidoEnum::RECUSADO, $pedido->pedido_aprovacao_status);
        $this->assertSame('Mesa fechada.', $pedido->pedido_recusa_motivo);
        $this->assertSame(StatusChamadoMesaEnum::CANCELADO, $chamado->fresh()->mc_status);
        $this->assertSame('CANCELADA', $participante->sessaoMesa->fresh()->sessao_mesa_status);
    }

    private function servico(): AprovacaoPedidoMesaService
    {
        return app(AprovacaoPedidoMesaService::class);
    }

    /** Primeiro pedido do QR na mesa — espera o garçom pela regra padrão. */
    private function pedidoPendente(): Pedido
    {
        $this->garcom = User::factory()->create(['name_first' => 'Garçom']);
        $mesa = Mesa::factory()->create();
        app(SessaoMesaService::class)->abrir($mesa->id, $this->garcom->id, pessoas: 2);
        /** @var MesaParticipante $participante */
        $participante = app(ParticipanteMesaService::class)->entrar($mesa->fresh(), 'Ana', '64999991234')['participante'];
        $refrigerante = Produto::create([
            'produto_descricao' => 'Refrigerante',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas', 'categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 8.00,
            'produto_cardapio' => true,
        ]);

        return app(PedidoMesaClienteService::class)->enviar($participante, [new ItemSolicitado($refrigerante->id)], (string) Str::uuid());
    }
}
