<?php

namespace Tests\Feature;

use App\Enums\AcaoAutorizadaEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\AutorizacaoNegadaException;
use App\Exceptions\MesaIndisponivelException;
use App\Models\AutorizacaoGerente;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\SessaoMesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AtendimentoMesaServiceTest extends TestCase
{
    use RefreshDatabase;

    private AtendimentoMesaService $service;

    private User $garcom;

    private User $gerente;

    private Mesa $mesa;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        config(['pizzaria.salao.taxa_servico_percentual' => 10, 'pizzaria.salao.taxa_servico_padrao_ligada' => true]);

        $this->service = app(AtendimentoMesaService::class);
        $this->garcom = User::factory()->garcom()->create(['name_first' => 'Garçom']);
        $this->gerente = User::factory()->gerente()->comPin('4321')->create(['name_first' => 'Gerente']);
        $this->mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA']);

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $this->produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
        ]);
    }

    private function abrirSessao(): SessaoMesa
    {
        return app(SessaoMesaService::class)->abrir($this->mesa->id, $this->garcom->id, pessoas: 4);
    }

    private function item(Pedido $pedido, float $valor = 50.00): ItensPedido
    {
        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $valor,
            'item_pedido_valor' => $valor,
            'item_pedido_desconto' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    private function rodadaEnviada(SessaoMesa $sessao, int $itens = 1): Pedido
    {
        $rascunho = $this->service->rascunho($sessao, $this->garcom);

        for ($i = 0; $i < $itens; $i++) {
            $this->item($rascunho);
        }

        $this->service->enviarRodada($rascunho);

        return $rascunho->fresh();
    }

    public function test_abrir_grava_pessoas_e_snapshot_da_taxa_e_ocupa_a_mesa(): void
    {
        $sessao = $this->abrirSessao();

        $this->assertSame(4, $sessao->sessao_mesa_pessoas);
        $this->assertEquals(10, $sessao->sessao_mesa_taxa_servico_percentual);
        $this->assertDatabaseHas(Mesa::class, ['id' => $this->mesa->id, 'mesa_status' => 'OCUPADA', 'mesa_sessao_atual_id' => $sessao->id]);
    }

    public function test_abrir_mesa_ja_ocupada_nao_cria_segunda_sessao(): void
    {
        $this->abrirSessao();

        try {
            $this->abrirSessao();
            $this->fail('Deveria recusar mesa ocupada.');
        } catch (MesaIndisponivelException) {
        }

        $this->assertSame(1, SessaoMesa::where('sessao_mesa_mesa_id', $this->mesa->id)->count());
    }

    public function test_cada_garcom_tem_seu_proprio_rascunho_na_mesma_mesa(): void
    {
        $sessao = $this->abrirSessao();
        $colega = User::factory()->garcom()->create(['name_first' => 'Colega']);

        $meu = $this->service->rascunho($sessao, $this->garcom);
        $doColega = $this->service->rascunho($sessao, $colega);

        $this->assertNotSame($meu->id, $doColega->id);
        $this->assertSame($meu->id, $this->service->rascunho($sessao, $this->garcom)->id);
    }

    public function test_enviar_rodada_abre_o_pedido_com_totais_e_reenvio_e_inofensivo(): void
    {
        $sessao = $this->abrirSessao();
        $rascunho = $this->service->rascunho($sessao, $this->garcom);
        $this->item($rascunho, 50.00);
        $this->item($rascunho, 30.00);

        $this->assertTrue($this->service->enviarRodada($rascunho));
        $this->assertFalse($this->service->enviarRodada($rascunho));

        $pedido = $rascunho->fresh();
        $this->assertSame(StatusPedidoEnum::ABERTO->value, $pedido->pedido_status);
        $this->assertEquals(80.00, $pedido->pedido_valor_total);
        $this->assertNotNull($pedido->pedido_datahora_abertura);
        $this->assertSame($this->garcom->id, $pedido->pedido_usuario_garcom_id);
        // A próxima rodada nasce num rascunho novo.
        $this->assertNotSame($pedido->id, $this->service->rascunho($sessao, $this->garcom)->id);
    }

    public function test_enviar_rodada_vazia_e_recusado(): void
    {
        $rascunho = $this->service->rascunho($this->abrirSessao(), $this->garcom);

        $this->expectException(RuntimeException::class);

        $this->service->enviarRodada($rascunho);
    }

    public function test_cancelar_item_com_pin_de_gerente_recalcula_e_audita(): void
    {
        $pedido = $this->rodadaEnviada($this->abrirSessao(), itens: 2);
        $item = $pedido->item_pedido_pedido_id()->first();

        $this->service->cancelarItem($item, $this->garcom, $this->gerente->id, '4321', 'Cliente desistiu');

        $this->assertSame('REMOVIDO', $item->fresh()->item_pedido_status);
        $this->assertSame($this->garcom->id, $item->fresh()->item_pedido_usuario_removeu);
        $this->assertEquals(50.00, $pedido->fresh()->pedido_valor_total);
        $this->assertDatabaseHas(AutorizacaoGerente::class, [
            'autorizacao_acao' => AcaoAutorizadaEnum::CANCELAR_ITEM->value,
            'autorizacao_autorizador_id' => $this->gerente->id,
            'autorizacao_auditavel_id' => $item->id,
        ]);
    }

    public function test_cancelar_item_sem_autorizacao_nao_altera_nada(): void
    {
        $pedido = $this->rodadaEnviada($this->abrirSessao());
        $item = $pedido->item_pedido_pedido_id()->first();

        try {
            $this->service->cancelarItem($item, $this->garcom, $this->gerente->id, '0000', 'Erro');
            $this->fail('PIN errado deveria ser recusado.');
        } catch (AutorizacaoNegadaException) {
        }

        $this->assertSame('INSERIDO', $item->fresh()->item_pedido_status);
    }

    public function test_cancelar_o_ultimo_item_cancela_a_rodada(): void
    {
        $pedido = $this->rodadaEnviada($this->abrirSessao());

        $this->service->cancelarItem($pedido->item_pedido_pedido_id()->first(), $this->gerente, null, null, 'Erro');

        $this->assertSame(StatusPedidoEnum::CANCELADO->value, $pedido->fresh()->pedido_status);
    }

    public function test_remover_taxa_exige_pin_e_respeita_a_versao(): void
    {
        $sessao = $this->abrirSessao();

        try {
            $this->service->removerTaxaServico($sessao, 0, $this->garcom, null, null, null);
            $this->fail('Garçom sem PIN de gerente não remove a taxa.');
        } catch (AutorizacaoNegadaException) {
        }
        $this->assertEquals(10, $sessao->fresh()->sessao_mesa_taxa_servico_percentual);

        $this->service->removerTaxaServico($sessao, 0, $this->garcom, $this->gerente->id, '4321', 'Cortesia');
        $this->assertEquals(0, $sessao->fresh()->sessao_mesa_taxa_servico_percentual);

        // Versão antiga na tela de outro garçom: conflito.
        $this->expectExceptionMessage('Outro garçom alterou');
        $this->service->alterarPessoas($sessao, 6, 0);
    }

    public function test_transferir_conta_para_mesa_livre(): void
    {
        $sessao = $this->abrirSessao();
        $destino = Mesa::create(['mesa_nome' => 'Mesa 2', 'mesa_status' => 'LIBERADA']);

        $this->service->transferirMesa($sessao, $destino->id, $this->gerente, null, null, 'Cliente pediu');

        $this->assertSame($destino->id, $sessao->fresh()->sessao_mesa_mesa_id);
        $this->assertDatabaseHas(Mesa::class, ['id' => $this->mesa->id, 'mesa_status' => 'LIBERADA', 'mesa_sessao_atual_id' => null]);
        $this->assertDatabaseHas(Mesa::class, ['id' => $destino->id, 'mesa_status' => 'OCUPADA', 'mesa_sessao_atual_id' => $sessao->id]);
    }

    public function test_solicitar_conta_marca_a_sessao(): void
    {
        $sessao = $this->service->solicitarConta($this->abrirSessao());

        $this->assertTrue($sessao->fresh()->contaSolicitada());
    }
}
