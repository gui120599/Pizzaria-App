<?php

namespace Tests\Feature;

use App\Enums\MotivoAprovacaoMesaEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Exceptions\ItemIndisponivelException;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\MesaParticipante;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\ItemSolicitado;
use App\Services\MesaCliente\AprovacaoPedidoMesaService;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\MesaCliente\PedidoMesaClienteService;
use App\Services\SessaoMesaService;
use App\Support\ContaMesa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Rodada enviada pelo cliente no QR: direto para a cozinha ou esperando o
 * garçom, conforme as regras de aprovação.
 */
class PedidoMesaClienteServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $garcom;

    public function test_sem_motivo_vai_direto_para_a_cozinha_e_entra_na_conta_do_cliente(): void
    {
        config(['pizzaria.mesa_cliente.aprovacao_primeiro_pedido' => 'off']);
        $participante = $this->participante();
        $refrigerante = $this->produto('Refrigerante', 8.00);

        $pedido = $this->enviar($participante, [new ItemSolicitado($refrigerante->id, 2)]);

        $this->assertSame('ABERTO', $pedido->pedido_status);
        $this->assertSame(PedidoOrigemEnum::MESA_QR, $pedido->pedido_origem);
        $this->assertNull($pedido->pedido_aprovacao_status);
        $this->assertSame($this->garcom->id, $pedido->pedido_usuario_garcom_id);
        $this->assertSame('16.00', $pedido->pedido_valor_total);
        $this->assertSame($participante->mp_cliente_id, ItensPedido::sole()->item_pedido_cliente_id);
        $this->assertSame(16.0, ContaMesa::subtotal($participante->mp_sessao_mesa_id));
    }

    public function test_primeiro_pedido_da_mesa_espera_o_garcom_fora_da_conta(): void
    {
        $participante = $this->participante();

        $pedido = $this->enviar($participante, [new ItemSolicitado($this->produto('Refrigerante', 8.00)->id)]);

        $this->assertSame('INICIADO', $pedido->pedido_status);
        $this->assertTrue($pedido->aguardandoAprovacao());
        $this->assertSame([MotivoAprovacaoMesaEnum::PRIMEIRO_PEDIDO], $pedido->pedido_aprovacao_motivos->all());
        $this->assertSame('8.00', $pedido->pedido_valor_total);
        $this->assertSame(0.0, ContaMesa::subtotal($participante->mp_sessao_mesa_id));
    }

    public function test_enquanto_o_primeiro_espera_os_seguintes_tambem_esperam(): void
    {
        $participante = $this->participante();
        $refrigerante = $this->produto('Refrigerante', 8.00);
        $this->enviar($participante, [new ItemSolicitado($refrigerante->id)]);

        $segundo = $this->enviar($participante, [new ItemSolicitado($refrigerante->id)]);

        $this->assertTrue($segundo->aguardandoAprovacao());
    }

    public function test_depois_do_primeiro_aprovado_os_seguintes_vao_direto(): void
    {
        $participante = $this->participante();
        $refrigerante = $this->produto('Refrigerante', 8.00);
        $primeiro = $this->enviar($participante, [new ItemSolicitado($refrigerante->id)]);
        app(AprovacaoPedidoMesaService::class)->aprovar($primeiro, $this->garcom);

        $segundo = $this->enviar($participante, [new ItemSolicitado($refrigerante->id)]);

        $this->assertSame('ABERTO', $segundo->pedido_status);
        $this->assertNull($segundo->pedido_aprovacao_status);
    }

    public function test_regra_por_celular_faz_cada_celular_novo_esperar_no_primeiro_pedido(): void
    {
        config(['pizzaria.mesa_cliente.aprovacao_primeiro_pedido' => 'participante']);
        $ana = $this->participante();
        $refrigerante = $this->produto('Refrigerante', 8.00);
        app(AprovacaoPedidoMesaService::class)->aprovar($this->enviar($ana, [new ItemSolicitado($refrigerante->id)]), $this->garcom);
        ['participante' => $joao] = app(ParticipanteMesaService::class)->entrar($ana->sessaoMesa->mesa, 'João', '64988887777');

        $doJoao = $this->enviar($joao, [new ItemSolicitado($refrigerante->id)]);

        $this->assertTrue($doJoao->aguardandoAprovacao());
    }

    public function test_produto_de_categoria_marcada_passa_pelo_garcom(): void
    {
        config(['pizzaria.mesa_cliente.aprovacao_primeiro_pedido' => 'off']);
        $participante = $this->participante();
        $alcoolicas = Categoria::create(['categoria_nome' => 'Cervejas', 'categoria_cardapio' => true, 'categoria_requer_aprovacao_mesa' => true]);
        $cerveja = $this->produto('Cerveja', 12.00, $alcoolicas);

        $pedido = $this->enviar($participante, [new ItemSolicitado($cerveja->id)]);

        $this->assertSame([MotivoAprovacaoMesaEnum::PRODUTO_MARCADO], $pedido->pedido_aprovacao_motivos->all());
    }

    public function test_quantidade_alta_de_um_item_passa_pelo_garcom(): void
    {
        config(['pizzaria.mesa_cliente.aprovacao_primeiro_pedido' => 'off', 'pizzaria.mesa_cliente.quantidade_aprovacao' => 5]);
        $participante = $this->participante();

        $pedido = $this->enviar($participante, [new ItemSolicitado($this->produto('Refrigerante', 8.00)->id, 5)]);

        $this->assertSame([MotivoAprovacaoMesaEnum::QUANTIDADE_ALTA], $pedido->pedido_aprovacao_motivos->all());
    }

    public function test_acima_da_quantidade_maxima_o_pedido_e_recusado(): void
    {
        config(['pizzaria.mesa_cliente.quantidade_maxima' => 20]);
        $participante = $this->participante();
        $refrigerante = $this->produto('Refrigerante', 8.00);

        $this->expectExceptionObject(new RuntimeException('No máximo 20 unidades de cada item por pedido. Para mais, chame o garçom.'));

        try {
            $this->enviar($participante, [new ItemSolicitado($refrigerante->id, 21)]);
        } finally {
            $this->assertSame(0, Pedido::count());
        }
    }

    public function test_reenviar_com_a_mesma_chave_devolve_o_mesmo_pedido_sem_duplicar(): void
    {
        $participante = $this->participante();
        $itens = [new ItemSolicitado($this->produto('Refrigerante', 8.00)->id)];
        $chave = (string) Str::uuid();

        $primeiro = app(PedidoMesaClienteService::class)->enviar($participante, $itens, $chave);
        $reenvio = app(PedidoMesaClienteService::class)->enviar($participante, $itens, $chave);

        $this->assertSame($primeiro->id, $reenvio->id);
        $this->assertSame(1, ItensPedido::count());
    }

    public function test_muitas_rodadas_em_pouco_tempo_sao_recusadas(): void
    {
        config(['pizzaria.mesa_cliente.rodadas_por_10min' => 2]);
        $participante = $this->participante();
        $refrigerante = $this->produto('Refrigerante', 8.00);
        $this->enviar($participante, [new ItemSolicitado($refrigerante->id)]);
        $this->enviar($participante, [new ItemSolicitado($refrigerante->id)]);

        $this->expectExceptionObject(new RuntimeException('Muitos pedidos em pouco tempo. Aguarde alguns minutos ou chame o garçom.'));

        $this->enviar($participante, [new ItemSolicitado($refrigerante->id)]);
    }

    public function test_conta_fechada_recusa_o_pedido(): void
    {
        $participante = $this->participante();
        app(SessaoMesaService::class)->fechar($participante->sessaoMesa);

        $this->expectExceptionObject(new RuntimeException('A conta desta mesa foi encerrada.'));

        $this->enviar($participante->fresh(), [new ItemSolicitado($this->produto('Refrigerante', 8.00)->id)]);
    }

    public function test_produto_que_o_cardapio_nao_mostra_e_recusado(): void
    {
        $participante = $this->participante();
        $oculto = Produto::create([
            'produto_descricao' => 'Prato do dia',
            'produto_categoria_id' => $this->categoria()->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 30.00,
            'produto_cardapio' => false,
        ]);

        $this->expectException(ItemIndisponivelException::class);

        $this->enviar($participante, [new ItemSolicitado($oculto->id)]);
    }

    public function test_pedido_do_cliente_esperando_nao_vira_o_rascunho_do_garcom(): void
    {
        $participante = $this->participante();
        $pendente = $this->enviar($participante, [new ItemSolicitado($this->produto('Refrigerante', 8.00)->id)]);

        $rascunho = app(AtendimentoMesaService::class)->rascunho($participante->sessaoMesa, $this->garcom);

        $this->assertNotSame($pendente->id, $rascunho->id);
        $this->assertNull($rascunho->pedido_mesa_participante_id);
    }

    /** @param  list<ItemSolicitado>  $itens */
    private function enviar(MesaParticipante $participante, array $itens): Pedido
    {
        return app(PedidoMesaClienteService::class)->enviar($participante, $itens, (string) Str::uuid());
    }

    private function participante(): MesaParticipante
    {
        $this->garcom = User::factory()->create(['name_first' => 'Garçom']);
        $mesa = Mesa::factory()->create();
        app(SessaoMesaService::class)->abrir($mesa->id, $this->garcom->id, pessoas: 2);

        return app(ParticipanteMesaService::class)->entrar($mesa->fresh(), 'Ana', '64999991234')['participante'];
    }

    private function categoria(): Categoria
    {
        return Categoria::firstOrCreate(['categoria_nome' => 'Bebidas'], ['categoria_cardapio' => true]);
    }

    private function produto(string $nome, float $preco, ?Categoria $categoria = null): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => ($categoria ?? $this->categoria())->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }
}
