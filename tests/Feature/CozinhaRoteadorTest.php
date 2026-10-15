<?php

namespace Tests\Feature;

use App\Enums\Cozinha\MotivoCancelamentoProducao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\Cozinha\StatusProducaoPedido;
use App\Enums\StatusPedidoEnum;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Models\User;
use App\Services\Cozinha\ProducaoService;
use App\Services\Cozinha\RoteadorProducao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\CenarioCozinha;
use Tests\TestCase;

/** Fase 2: geração e sincronização Pedido → Produção (RoteadorProducao + observers). */
class CozinhaRoteadorTest extends TestCase
{
    use CenarioCozinha, RefreshDatabase;

    private SetorProducao $pizzaria;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 20:00:00');
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $this->pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rota_do_produto_sobrepoe_a_da_categoria_que_vale_para_descendentes(): void
    {
        $bar = SetorProducao::factory()->bar()->create();
        $pizzas = $this->categoria('Pizzas');
        $grande = $this->categoria('Pizza Grande', $pizzas);
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $calabresa = $this->produto('Calabresa', $grande);
        $especial = $this->produto('Especial', $grande);
        $this->rotaProduto($especial, $bar);

        $pedido = $this->pedidoMesa();
        $this->item($pedido, $calabresa);
        $this->item($pedido, $especial);
        $this->abrir($pedido);

        $this->assertSame([$this->pizzaria->id, $bar->id], ProducaoItem::orderBy('item_pedido_id')->pluck('setor_producao_id')->all());
    }

    public function test_dispensa_producao_e_item_sem_rota_nao_vao_para_a_cozinha_mas_setor_padrao_recebe(): void
    {
        $bebidas = $this->categoria('Refrigerante');
        $lata = $this->produto('Coca lata', $bebidas, ['produto_dispensa_producao' => true]);
        $this->rotaCategoria($bebidas, $this->pizzaria);
        $semRota = $this->produto('Pastel', $this->categoria('Pastel'));

        $pedido = $this->pedidoMesa();
        $this->item($pedido, $lata, 2);
        $this->item($pedido, $semRota);
        $this->abrir($pedido);

        $this->assertSame(0, ProducaoItem::count());
        $this->assertSame(StatusProducaoPedido::SemProducao, $pedido->fresh()->pedido_producao_status);

        config(['cozinha.setor_padrao' => $this->pizzaria->slug]);
        app(RoteadorProducao::class)->gerarParaPedido($pedido);

        $this->assertSame(1, ProducaoItem::count());
    }

    public function test_explode_unidades_por_setor_e_rota_de_duas_etapas(): void
    {
        $forno = SetorProducao::factory()->create(['nome' => 'Forno']);
        $bar = SetorProducao::factory()->bar()->create();
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria, 1);
        $this->rotaCategoria($pizzas, $forno, 2);
        $sucos = $this->categoria('Sucos');
        $this->rotaCategoria($sucos, $bar);

        $pedido = $this->pedidoMesa();
        $pizza = $this->item($pedido, $this->produto('Calabresa', $pizzas), 3);
        $suco = $this->item($pedido, $this->produto('Suco', $sucos), 6);
        $this->abrir($pedido);

        $linhasPizza = ProducaoItem::where('item_pedido_id', $pizza->id)->get();
        $this->assertCount(3, $linhasPizza->where('etapa', 1)->where('status', StatusItemProducao::NaFila));
        $this->assertCount(3, $linhasPizza->where('etapa', 2)->where('status', StatusItemProducao::Aguardando));
        $this->assertSame([2], $linhasPizza->pluck('etapa_total')->unique()->values()->all());

        $linhaSuco = ProducaoItem::where('item_pedido_id', $suco->id)->sole();
        $this->assertSame('6.000', $linhaSuco->quantidade);
    }

    public function test_gerar_duas_vezes_nao_duplica(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas), 2);
        $this->abrir($pedido);

        app(RoteadorProducao::class)->gerarParaPedido($pedido);
        app(RoteadorProducao::class)->gerarParaPedido($pedido);

        $this->assertSame(2, ProducaoItem::count());
    }

    public function test_rascunho_iniciado_nao_vai_para_a_cozinha(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas));

        $this->assertSame(0, ProducaoItem::count());
    }

    public function test_meio_a_meio_vira_um_card_com_os_dois_sabores_e_remocoes_da_observacao(): void
    {
        $grande = $this->categoria('Pizza Grande');
        $this->rotaCategoria($grande, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $this->itemMeioAMeio($pedido, $this->produto('Calabresa', $grande), $this->produto('Portuguesa', $grande), [
            'item_pedido_observacao' => 'Sem cebola e sem azeitona, bem passada',
        ]);
        $this->abrir($pedido);

        $snapshot = ProducaoItem::sole()->snapshot;
        $this->assertSame('Pizza Grande', $snapshot['produto']);
        $this->assertSame(['Calabresa', 'Portuguesa'], array_column($snapshot['sabores'], 'nome'));
        $this->assertSame(['1/2', '1/2'], array_column($snapshot['sabores'], 'fracao'));
        $this->assertSame(['cebola', 'azeitona'], array_column($snapshot['remocoes'], 'opcional_ref'));
        $this->assertSame('Sem cebola e sem azeitona, bem passada', $snapshot['observacao']);
        $this->assertSame('Mesa 7', ProducaoItem::sole()->referencia);
    }

    public function test_referencia_nao_repete_mesa_quando_o_nome_ja_tem_o_prefixo(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $pedido->sessaoMesa->mesa->update(['mesa_nome' => 'Mesa 01']);
        $this->item($pedido, $this->produto('Calabresa', $pizzas));
        $this->abrir($pedido);

        $this->assertSame('Mesa 01', ProducaoItem::sole()->referencia);
    }

    public function test_item_adicionado_depois_ganha_selo_e_reabre_a_producao_do_pedido(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $calabresa = $this->produto('Calabresa', $pizzas);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $calabresa);
        $this->abrir($pedido);

        $primeira = ProducaoItem::sole();
        $servico = app(ProducaoService::class);
        $servico->iniciar($primeira, $this->operador());
        $servico->concluir($primeira->fresh(), $this->operador());
        $this->assertSame(StatusProducaoPedido::Pronto, $pedido->fresh()->pedido_producao_status);

        Carbon::setTestNow(now()->addMinutes(5));
        $novo = $this->item($pedido, $calabresa);

        $linha = ProducaoItem::where('item_pedido_id', $novo->id)->sole();
        $this->assertTrue($linha->snapshot['adicional']);
        $this->assertSame(StatusProducaoPedido::EmProducao, $pedido->fresh()->pedido_producao_status);
    }

    public function test_quantidade_reduzida_cancela_primeiro_os_da_fila(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $item = $this->item($pedido, $this->produto('Calabresa', $pizzas), 3);
        $this->abrir($pedido);

        $emPreparo = ProducaoItem::where('unidade_seq', 3)->sole();
        app(ProducaoService::class)->iniciar($emPreparo, $this->operador());

        $item->update(['item_pedido_quantidade' => 2]);

        $this->assertSame(StatusItemProducao::EmPreparo, $emPreparo->fresh()->status);
        $cancelada = ProducaoItem::where('status', StatusItemProducao::Cancelado)->sole();
        $this->assertSame(2, $cancelada->unidade_seq);
        $this->assertSame(MotivoCancelamentoProducao::QuantidadeReduzida, $cancelada->motivo_cancelamento);
        $this->assertNotNull($cancelada->cancelamento_ciente_em, 'Não tinha começado: não exige ciência.');
    }

    public function test_pedido_cancelado_cancela_tudo_e_os_iniciados_exigem_ciencia(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas), 2);
        $this->abrir($pedido);
        app(ProducaoService::class)->iniciar(ProducaoItem::first(), $this->operador());

        $pedido->update(['pedido_status' => StatusPedidoEnum::CANCELADO->value]);

        $this->assertSame(2, ProducaoItem::where('status', StatusItemProducao::Cancelado)->count());
        $this->assertSame(1, ProducaoItem::aguardandoCiencia()->count());
        $this->assertSame(StatusProducaoPedido::Cancelado, $pedido->fresh()->pedido_producao_status);
    }

    public function test_item_removido_cancela_a_producao_dele(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $item = $this->item($pedido, $this->produto('Calabresa', $pizzas));
        $this->abrir($pedido);

        $item->update(['item_pedido_status' => 'REMOVIDO']);

        $this->assertSame(MotivoCancelamentoProducao::ItemRemovido, ProducaoItem::sole()->motivo_cancelamento);
    }

    public function test_edicao_antes_de_iniciar_e_silenciosa_e_depois_marca_alterado(): void
    {
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $item = $this->item($pedido, $this->produto('Calabresa', $pizzas));
        $this->abrir($pedido);

        $item->update(['item_pedido_observacao' => 'Sem cebola']);
        $linha = ProducaoItem::sole();
        $this->assertSame('Sem cebola', $linha->snapshot['observacao']);
        $this->assertNull($linha->alterado_apos_inicio_em);

        app(ProducaoService::class)->iniciar($linha, $this->operador());
        $item->update(['item_pedido_observacao' => 'Sem cebola, bem passada']);

        $linha->refresh();
        $this->assertNotNull($linha->alterado_apos_inicio_em);
        $this->assertSame('Sem cebola', $linha->snapshot['antes']['observacao']);
        $this->assertSame(1, ProducaoItem::aguardandoCiencia()->count());

        app(ProducaoService::class)->confirmarCiencia($linha, $this->operador());
        $this->assertSame(0, ProducaoItem::aguardandoCiencia()->count());
    }

    public function test_disparo_por_tempo_segura_o_item_rapido_e_o_comando_libera(): void
    {
        config(['cozinha.disparo_por_tempo_preparo' => true]);
        $pizzas = $this->categoria('Pizzas');
        $porcoes = $this->categoria('Porções');
        $this->rotaCategoria($pizzas, $this->pizzaria, tempo: 15);
        $this->rotaCategoria($porcoes, $this->pizzaria, tempo: 5);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas));
        $batata = $this->item($pedido, $this->produto('Batata', $porcoes));
        $this->abrir($pedido);

        $segura = ProducaoItem::where('item_pedido_id', $batata->id)->sole();
        $this->assertSame(StatusItemProducao::Aguardando, $segura->status);
        $this->assertTrue($segura->liberar_em->equalTo(now()->addMinutes(10)));

        Carbon::setTestNow(now()->addMinutes(10));
        $this->artisan('cozinha:liberar-itens')->assertSuccessful();

        $this->assertSame(StatusItemProducao::NaFila, $segura->fresh()->status);
    }

    public function test_remocoes_da_observacao(): void
    {
        $this->assertSame(['cebola'], RoteadorProducao::remocoesDaObservacao('SEM CEBOLA'));
        $this->assertSame(['oregano', 'pimentao'], RoteadorProducao::remocoesDaObservacao('sem orégano; sem pimentão'));
        $this->assertSame([], RoteadorProducao::remocoesDaObservacao('Borda catupiry'));
        $this->assertSame([1.0, 1.0, 0.5], RoteadorProducao::unidades(2.5, true));
        $this->assertSame([0.5], RoteadorProducao::unidades(0.5, true));
        $this->assertSame([6.0], RoteadorProducao::unidades(6, false));
    }
}
