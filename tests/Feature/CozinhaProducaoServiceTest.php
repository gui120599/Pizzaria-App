<?php

namespace Tests\Feature;

use App\Enums\Cozinha\AcaoProducao;
use App\Enums\Cozinha\MotivoCancelamentoProducao;
use App\Enums\Cozinha\MotivoRefacao;
use App\Enums\Cozinha\PrioridadeProducao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\Cozinha\StatusProducaoPedido;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\ProducaoException;
use App\Models\Pedido;
use App\Models\ProducaoEvento;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Models\User;
use App\Notifications\Cozinha\ProducaoProntaNotification;
use App\Services\Cozinha\ProducaoService;
use App\Services\PedidoStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CenarioCozinha;
use Tests\TestCase;

/** Fase 2: transições dos itens e sincronização Produção → Pedido. */
class CozinhaProducaoServiceTest extends TestCase
{
    use CenarioCozinha, RefreshDatabase;

    private SetorProducao $pizzaria;

    private ProducaoService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $this->pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria']);
        $this->servico = app(ProducaoService::class);
    }

    /** Pedido aberto com N pizzas roteadas para a Pizzaria. */
    private function pedidoComPizzas(int $quantidade = 1, ?Pedido $pedido = null): Pedido
    {
        $pizzas = $this->categoria('Pizzas '.uniqid());
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido ??= $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas), $quantidade);

        return $this->abrir($pedido);
    }

    public function test_versao_desatualizada_recusa_o_segundo_toque(): void
    {
        $this->pedidoComPizzas();
        $item = ProducaoItem::sole();

        $this->servico->iniciar($item, $this->operador(), versao: 0);

        $this->expectException(ProducaoException::class);
        $this->expectExceptionMessage('atualizado em outro aparelho');
        $this->servico->iniciar($item, $this->operador(), versao: 0);
    }

    public function test_transicoes_invalidas_lancam_excecao(): void
    {
        $this->pedidoComPizzas();
        $item = ProducaoItem::sole();

        try {
            $this->servico->desfazer($item, $this->operador());
            $this->fail('Desfazer da fila deveria falhar.');
        } catch (ProducaoException) {
        }

        // Pizzaria não permite pular o início.
        $this->expectException(ProducaoException::class);
        $this->servico->concluir($item, $this->operador());
    }

    public function test_setor_que_exige_operador_recusa_sem_operador(): void
    {
        $this->pedidoComPizzas();

        $this->expectExceptionMessage('Escolha o operador');
        $this->servico->iniciar(ProducaoItem::sole());
    }

    public function test_bar_pode_marcar_pronto_direto_da_fila(): void
    {
        $bar = SetorProducao::factory()->bar()->semOperador()->create();
        $sucos = $this->categoria('Sucos');
        $this->rotaCategoria($sucos, $bar);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Suco', $sucos));
        $this->abrir($pedido);

        $item = $this->servico->concluir(ProducaoItem::sole());

        $this->assertSame(StatusItemProducao::Pronto, $item->status);
        $this->assertNotNull($item->iniciado_em);
    }

    public function test_concluir_etapa_um_libera_a_etapa_dois_da_mesma_unidade(): void
    {
        $forno = SetorProducao::factory()->create(['nome' => 'Forno']);
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria, 1);
        $this->rotaCategoria($pizzas, $forno, 2);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas), 2);
        $this->abrir($pedido);

        $montagem = ProducaoItem::where('etapa', 1)->where('unidade_seq', 1)->sole();
        $this->servico->iniciar($montagem, $this->operador());
        $this->servico->concluir($montagem->fresh(), $this->operador());

        $this->assertSame(StatusItemProducao::NaFila, ProducaoItem::where('etapa', 2)->where('unidade_seq', 1)->sole()->status);
        $this->assertSame(StatusItemProducao::Aguardando, ProducaoItem::where('etapa', 2)->where('unidade_seq', 2)->sole()->status);

        // Desfazer a montagem devolve o forno a aguardar.
        $this->servico->desfazer($montagem->fresh(), $this->operador());
        $this->assertSame(StatusItemProducao::Aguardando, ProducaoItem::where('etapa', 2)->where('unidade_seq', 1)->sole()->status);
    }

    public function test_primeiro_iniciado_leva_o_pedido_a_preparando_e_nao_regera_nada(): void
    {
        $pedido = $this->pedidoComPizzas(2);

        $this->servico->iniciar(ProducaoItem::first(), $this->operador());

        $pedido->refresh();
        $this->assertSame(StatusPedidoEnum::PREPARANDO->value, $pedido->pedido_status);
        $this->assertNotNull($pedido->pedido_datahora_preparo);
        $this->assertSame(StatusProducaoPedido::EmProducao, $pedido->pedido_producao_status);
        // Sem laço: a mudança feita pela cozinha não regera nem cancela itens.
        $this->assertSame(2, ProducaoItem::count());
        $this->assertSame(0, ProducaoItem::where('status', StatusItemProducao::Cancelado)->count());
    }

    public function test_sem_expedicao_o_ultimo_pronto_deixa_o_pedido_pronto_e_avisa_o_garcom(): void
    {
        config(['cozinha.usa_expedicao' => false]);
        Notification::fake();
        $garcom = User::factory()->garcom()->create(['name_first' => 'Garçom']);
        $pedido = $this->pedidoComPizzas(1, $this->pedidoMesa($garcom));

        $item = $this->servico->iniciar(ProducaoItem::sole(), $this->operador());
        $this->servico->concluir($item, $this->operador());

        $this->assertSame(StatusPedidoEnum::PRONTO->value, $pedido->fresh()->pedido_status);
        Notification::assertSentTo($garcom, ProducaoProntaNotification::class);
    }

    public function test_com_expedicao_o_despacho_aplica_o_destino_do_tipo_de_atendimento(): void
    {
        $mesa = $this->pedidoComPizzas();
        $item = $this->servico->iniciar(ProducaoItem::sole(), $this->operador());
        $this->servico->concluir($item, $this->operador());

        $this->assertSame(StatusPedidoEnum::PREPARANDO->value, $mesa->fresh()->pedido_status, 'Com Expedição, só o despacho leva a PRONTO.');
        $this->assertSame(StatusProducaoPedido::Pronto, $mesa->fresh()->pedido_producao_status);

        $this->servico->expedirPedido($mesa);

        $this->assertSame(StatusPedidoEnum::ENTREGUE->value, $mesa->fresh()->pedido_status);
        $this->assertSame(StatusItemProducao::Expedido, $item->fresh()->status);
        $this->assertSame(StatusProducaoPedido::Expedido, $mesa->fresh()->pedido_producao_status);

        $delivery = $this->pedidoComPizzas(1, $this->pedidoDelivery());
        $linha = ProducaoItem::where('pedido_id', $delivery->id)->sole();
        $this->servico->concluir($this->servico->iniciar($linha, $this->operador()), $this->operador());
        $this->servico->expedirPedido($delivery);

        $this->assertSame(StatusPedidoEnum::PRONTO->value, $delivery->fresh()->pedido_status, 'Delivery espera o entregador em PRONTO.');
    }

    public function test_despacho_com_item_em_producao_e_recusado(): void
    {
        $pedido = $this->pedidoComPizzas(2);
        $this->servico->iniciar(ProducaoItem::first(), $this->operador());

        $this->expectExceptionMessage('Ainda há 2 item(ns) em produção');
        $this->servico->expedirPedido($pedido);
    }

    public function test_pedido_que_ja_saiu_nunca_retrocede(): void
    {
        $pedido = $this->pedidoComPizzas(2);
        $primeira = ProducaoItem::first();
        $this->servico->concluir($this->servico->iniciar($primeira, $this->operador()), $this->operador());

        // Saiu por fora da cozinha (painel): os pendentes acompanham.
        app(PedidoStatusService::class)->avancarPara($pedido->fresh(), StatusPedidoEnum::PRONTO);
        app(PedidoStatusService::class)->avancar($pedido->fresh());
        $this->assertSame(StatusPedidoEnum::ENTREGUE->value, $pedido->fresh()->pedido_status);

        $refeita = $this->servico->refazer(ProducaoItem::where('unidade_seq', 1)->where('status', StatusItemProducao::Expedido)->sole(), MotivoRefacao::Queimou, auth()->user());
        $this->servico->iniciar($refeita, $this->operador());

        $this->assertSame(StatusPedidoEnum::ENTREGUE->value, $pedido->fresh()->pedido_status);
        $this->assertSame(StatusProducaoPedido::EmProducao, $pedido->fresh()->pedido_producao_status);
    }

    public function test_forcar_pronto_pelo_painel_conclui_os_pendentes_com_evento(): void
    {
        $pedido = $this->pedidoComPizzas(2);
        $this->servico->iniciar(ProducaoItem::first(), $this->operador());

        app(PedidoStatusService::class)->avancar($pedido->fresh());

        $this->assertSame(2, ProducaoItem::where('status', StatusItemProducao::Pronto)->count());
        $this->assertSame(2, ProducaoEvento::where('acao', AcaoProducao::ForcadoPeloPainel)->count());
        $this->assertSame(StatusProducaoPedido::Pronto, $pedido->fresh()->pedido_producao_status);
    }

    public function test_refazer_cria_geracao_dois_urgente_e_cancela_a_anterior(): void
    {
        $pedido = $this->pedidoComPizzas();
        $item = $this->servico->concluir($this->servico->iniciar(ProducaoItem::sole(), $this->operador()), $this->operador());

        $nova = $this->servico->refazer($item, MotivoRefacao::Caiu, auth()->user());

        $this->assertSame(2, $nova->geracao);
        $this->assertSame(PrioridadeProducao::Urgente, $nova->prioridade);
        $this->assertSame(StatusItemProducao::NaFila, $nova->status);
        $this->assertSame($item->id, $nova->refeito_de_id);
        $item->refresh();
        $this->assertSame(StatusItemProducao::Cancelado, $item->status);
        $this->assertSame(MotivoCancelamentoProducao::Refeito, $item->motivo_cancelamento);
        $this->assertSame(MotivoRefacao::Caiu, $item->motivo_refacao);
        $this->assertSame(StatusProducaoPedido::EmProducao, $pedido->fresh()->pedido_producao_status);
        $this->assertSame(0, ProducaoItem::aguardandoCiencia()->count());
    }

    public function test_priorizar_sobe_todos_os_itens_do_pedido_na_fila(): void
    {
        $outro = $this->pedidoComPizzas();
        $pedido = $this->pedidoComPizzas(2);

        $this->servico->priorizar($pedido, PrioridadeProducao::Urgente);

        $fila = ProducaoItem::doSetor($this->pizzaria->id)->naFila()->pluck('pedido_id')->all();
        $this->assertSame([$pedido->id, $pedido->id, $outro->id], $fila);
        $this->assertSame(PrioridadeProducao::Urgente, $pedido->fresh()->pedido_prioridade_producao);
    }
}
