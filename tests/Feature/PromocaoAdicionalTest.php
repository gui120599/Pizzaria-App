<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Exceptions\PromocaoIndisponivelException;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoAdicionalConsumo;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoAdicionalRegra;
use App\Models\PromocaoRelampago;
use App\Services\PrecificadorService;
use App\Services\PromocaoAdicionalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromocaoAdicionalTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private Produto $pizza;

    private Produto $brotinho;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $this->pizza = $this->produto('Calabresa', 59.90);
        $this->brotinho = $this->produto('Pizza Brotinho', 20.00);
    }

    private function produto(string $nome, float $preco, array $attrs = []): Produto
    {
        return Produto::create(array_merge([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ], $attrs));
    }

    private function promocao(array $attrs = []): PromocaoAdicional
    {
        return PromocaoAdicional::create(array_merge([
            'promoad_nome' => 'Dia do Cliente',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
        ], $attrs));
    }

    private function regra(PromocaoAdicional $promocao, array $attrs = []): PromocaoAdicionalRegra
    {
        return $promocao->regras()->create(array_merge([
            'par_produto_gatilho_id' => $this->pizza->id,
            'par_preco_gatilho_override' => 39.90,
            'par_qtd_maxima_por_pedido' => 1,
        ], $attrs));
    }

    private function oferta(PromocaoAdicionalRegra $regra, array $attrs = []): PromocaoAdicionalOferta
    {
        return $regra->ofertas()->create(array_merge([
            'pao_produto_oferta_id' => $this->brotinho->id,
            'pao_valor_adicional' => 5.00,
        ], $attrs));
    }

    /** Regra + 1 oferta padrão — atalho para os testes que não se importam com N ofertas. */
    private function regraComOferta(PromocaoAdicional $promocao, array $attrsRegra = [], array $attrsOferta = []): array
    {
        $regra = $this->regra($promocao, $attrsRegra);
        $oferta = $this->oferta($regra, $attrsOferta);

        return [$regra, $oferta];
    }

    private function criarGatilho(PromocaoAdicionalRegra $regra): array
    {
        $pedido = Pedido::create(['pedido_status' => 'INICIADO']);

        $gatilho = ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $this->pizza->id,
            'item_pedido_promocao_adicional_regra_id' => $regra->par_preco_gatilho_override !== null ? $regra->id : null,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 39.90,
            'item_pedido_valor' => 39.90,
            'item_pedido_desconto' => 20.00,
            'item_pedido_desconto_unitario' => 20.00,
            'item_pedido_status' => 'INSERIDO',
        ]);

        return [$pedido, $gatilho];
    }

    private function criarItemOferta(Pedido $pedido, ItensPedido $gatilho, PromocaoAdicionalOferta $oferta): ItensPedido
    {
        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $oferta->pao_produto_oferta_id,
            'item_pedido_promocao_adicional_regra_id' => $oferta->pao_regra_id,
            'item_pedido_promocao_adicional_oferta_id' => $oferta->id,
            'item_pedido_origem_id' => $gatilho->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $oferta->pao_valor_adicional,
            'item_pedido_valor' => $oferta->pao_valor_adicional,
            'item_pedido_desconto' => 0,
            'item_pedido_desconto_unitario' => 0,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    /** Cria o item-gatilho e o item-oferta vinculado (atalho para o caso comum). */
    private function criarItens(PromocaoAdicionalRegra $regra, PromocaoAdicionalOferta $oferta): array
    {
        [$pedido, $gatilho] = $this->criarGatilho($regra);
        $itemOferta = $this->criarItemOferta($pedido, $gatilho, $oferta);

        return [$pedido, $gatilho, $itemOferta];
    }

    // ── Vigência / elegibilidade ─────────────────────────────────────────────

    public function test_regra_vigente_com_saldo_aparece_para_o_produto_gatilho(): void
    {
        $promocao = $this->promocao();
        [$regra] = $this->regraComOferta($promocao);

        $encontrada = app(PrecificadorService::class)->regraAdicionalDoProduto($this->pizza->id);

        $this->assertNotNull($encontrada);
        $this->assertSame($regra->id, $encontrada->id);
    }

    public function test_regra_sem_nenhuma_oferta_nao_aparece(): void
    {
        $promocao = $this->promocao();
        $this->regra($promocao);

        $this->assertNull(app(PrecificadorService::class)->regraAdicionalDoProduto($this->pizza->id));
    }

    public function test_promocao_inativa_nao_aparece(): void
    {
        $promocao = $this->promocao(['promoad_ativa' => false]);
        $this->regraComOferta($promocao);

        $this->assertNull(app(PrecificadorService::class)->regraAdicionalDoProduto($this->pizza->id));
    }

    public function test_promocao_fora_do_periodo_nao_aparece(): void
    {
        $promocao = $this->promocao([
            'promoad_inicio' => now()->subDays(10),
            'promoad_fim' => now()->subDay(),
        ]);
        $this->regraComOferta($promocao);

        $this->assertNull(app(PrecificadorService::class)->regraAdicionalDoProduto($this->pizza->id));
    }

    public function test_produto_nao_participante_nao_recebe_oferta(): void
    {
        $promocao = $this->promocao();
        $this->regraComOferta($promocao);

        $outroProduto = $this->produto('Refrigerante', 8.00);

        $this->assertNull(app(PrecificadorService::class)->regraAdicionalDoProduto($outroProduto->id));
    }

    // ── N ofertas por gatilho ─────────────────────────────────────────────────

    public function test_n_ofertas_no_mesmo_gatilho_cliente_escolhe_uma_e_so_ela_debita_saldo(): void
    {
        $promocao = $this->promocao();
        $regra = $this->regra($promocao);
        $ofertaBrotinho = $this->oferta($regra, ['pao_valor_adicional' => 5.00]);
        $refrigerante = $this->produto('Refrigerante 2L', 12.00);
        $ofertaRefri = $this->oferta($regra, ['pao_produto_oferta_id' => $refrigerante->id, 'pao_valor_adicional' => 8.00]);

        $ofertasDoProduto = app(PrecificadorService::class)->ofertasDoProduto($this->pizza->id);
        $this->assertCount(2, $ofertasDoProduto);

        [$pedido, $gatilho] = $this->criarGatilho($regra);
        $itemOferta = $this->criarItemOferta($pedido, $gatilho, $ofertaRefri);

        app(PromocaoAdicionalService::class)->consumir($itemOferta);

        $this->assertSame(1.0, (float) $ofertaRefri->fresh()->pao_qtd_vendida);
        $this->assertSame(0.0, (float) $ofertaBrotinho->fresh()->pao_qtd_vendida);
    }

    // ── Preço do gatilho (override) ──────────────────────────────────────────

    public function test_preco_override_do_gatilho_e_aplicado_pelo_precificador(): void
    {
        $promocao = $this->promocao();
        $this->regraComOferta($promocao);

        $preco = app(PrecificadorService::class)->resolver($this->pizza);

        $this->assertSame(59.90, $preco->valorUnitario);
        $this->assertSame(20.00, $preco->descontoUnitario);
        $this->assertSame(39.90, $preco->precoFinal());
        $this->assertTrue($preco->temPromocaoAdicional());
    }

    public function test_sem_override_o_preco_do_gatilho_segue_a_cadeia_normal(): void
    {
        $promocao = $this->promocao();
        $this->regraComOferta($promocao, ['par_preco_gatilho_override' => null]);

        $preco = app(PrecificadorService::class)->resolver($this->pizza);

        $this->assertSame(59.90, $preco->precoFinal());
        $this->assertFalse($preco->temPromocaoAdicional());
    }

    public function test_override_da_promocao_adicional_vence_relampago_ativo_no_mesmo_produto(): void
    {
        $promocao = $this->promocao();
        $this->regraComOferta($promocao);

        $relampago = PromocaoRelampago::create([
            'promocao_nome' => 'Relâmpago concorrente',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
        ]);
        $relampago->promocaoProdutos()->create([
            'prp_produto_id' => $this->pizza->id,
            'prp_preco_promocional' => 44.90,
        ]);

        $preco = app(PrecificadorService::class)->resolver($this->pizza);

        $this->assertSame(39.90, $preco->precoFinal());
        $this->assertTrue($preco->temPromocaoAdicional());
        $this->assertFalse($preco->temPromocaoRelampago());
    }

    // ── Restrição de forma de pagamento (nível campanha) ─────────────────────

    public function test_sem_restricao_de_pagamento_qualquer_forma_passa(): void
    {
        $promocao = $this->promocao();
        [$regra] = $this->regraComOferta($promocao);

        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'PIX']);

        $this->assertNotNull(app(PrecificadorService::class)->regraAdicionalDoProduto($this->pizza->id, $pix->id));
    }

    public function test_restricao_de_pagamento_bloqueia_forma_nao_permitida(): void
    {
        $promocao = $this->promocao();
        [$regra] = $this->regraComOferta($promocao);

        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'PIX']);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro']);
        $promocao->opcoesPagamento()->attach($pix->id);

        $this->assertNotNull(app(PrecificadorService::class)->regraAdicionalDoProduto($this->pizza->id, $pix->id));
        $this->assertNull(app(PrecificadorService::class)->regraAdicionalDoProduto($this->pizza->id, $dinheiro->id));
    }

    public function test_forma_de_pagamento_null_nao_filtra_mesmo_com_restricao_cadastrada(): void
    {
        // Atendente/garçom nunca informam forma de pagamento ao adicionar item
        // — a restrição só é aplicada quando o chamador sabe a forma (checkout).
        $promocao = $this->promocao();
        [$regra] = $this->regraComOferta($promocao);

        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'PIX']);
        $promocao->opcoesPagamento()->attach($pix->id);

        $this->assertNotNull(app(PrecificadorService::class)->regraAdicionalDoProduto($this->pizza->id));
    }

    // ── Fracionado (meia a meia / três sabores) ──────────────────────────────

    public function test_fracionado_desligado_combo_nao_recebe_override_mesmo_com_todos_sabores_cobertos(): void
    {
        $promocao = $this->promocao(['promoad_aplica_fracionado' => false]);
        $marguerita = $this->produto('Marguerita', 55.00);
        $this->regraComOferta($promocao, ['par_preco_gatilho_override' => 39.90]);
        $regraMarguerita = $this->regra($promocao, ['par_produto_gatilho_id' => $marguerita->id, 'par_preco_gatilho_override' => 45.00]);
        $this->oferta($regraMarguerita);

        $rateio = app(PrecificadorService::class)->ratearCombo([$this->pizza, $marguerita], qtd: 1);

        $this->assertNull(collect($rateio)->pluck('promocao_adicional_regra_id')->filter()->first());
        // Sem promoção de combo, a pizza fracionada vale a MÉDIA do preço
        // cheio de cada sabor (convenção já existente pro meia a meia).
        $this->assertSame(57.45, round(array_sum(array_column($rateio, 'valor')), 2));
    }

    public function test_fracionado_ligado_combo_aplica_maior_override_entre_os_sabores(): void
    {
        $promocao = $this->promocao(['promoad_aplica_fracionado' => true]);
        $marguerita = $this->produto('Marguerita', 55.00);
        $this->regraComOferta($promocao, ['par_preco_gatilho_override' => 39.90]);
        $regraMarguerita = $this->regra($promocao, ['par_produto_gatilho_id' => $marguerita->id, 'par_preco_gatilho_override' => 45.00]);
        $this->oferta($regraMarguerita);

        $rateio = app(PrecificadorService::class)->ratearCombo([$this->pizza, $marguerita], qtd: 1);

        // Maior override entre os dois sabores (45.00) é o preço anunciado da pizza.
        $this->assertSame(45.00, round(array_sum(array_column($rateio, 'valor')), 2));
        $this->assertNotNull(collect($rateio)->pluck('promocao_adicional_regra_id')->filter()->first());
    }

    public function test_fracionado_ligado_mas_so_um_sabor_com_override_nao_aplica(): void
    {
        $promocao = $this->promocao(['promoad_aplica_fracionado' => true]);
        $marguerita = $this->produto('Marguerita', 55.00);
        $this->regraComOferta($promocao, ['par_preco_gatilho_override' => 39.90]);
        // Marguerita não participa da campanha — sem override.

        $rateio = app(PrecificadorService::class)->ratearCombo([$this->pizza, $marguerita], qtd: 1);

        $this->assertSame(57.45, round(array_sum(array_column($rateio, 'valor')), 2));
    }

    // ── Consumo / estorno ─────────────────────────────────────────────────────

    public function test_aceite_grava_as_duas_linhas_com_vinculo_correto_e_consome_saldo(): void
    {
        $promocao = $this->promocao();
        [$regra, $oferta] = $this->regraComOferta($promocao, [], ['pao_qtd_total' => 10]);

        [$pedido, $gatilho, $itemOferta] = $this->criarItens($regra, $oferta);

        app(PromocaoAdicionalService::class)->consumir($itemOferta);

        $this->assertSame($gatilho->id, $itemOferta->item_pedido_origem_id);
        $this->assertSame($regra->id, $itemOferta->item_pedido_promocao_adicional_regra_id);
        $this->assertSame($oferta->id, $itemOferta->item_pedido_promocao_adicional_oferta_id);
        $this->assertSame(1.0, (float) $oferta->fresh()->pao_qtd_vendida);

        $this->assertDatabaseHas('promocao_adicional_consumos', [
            'pac_regra_id' => $regra->id,
            'pac_oferta_id' => $oferta->id,
            'pac_item_pedido_gatilho_id' => $gatilho->id,
            'pac_item_pedido_oferta_id' => $itemOferta->id,
            'pac_pedido_id' => $pedido->id,
            'pac_valor_adicional_cobrado' => 5.00,
        ]);
    }

    public function test_recusa_no_grava_linha_de_oferta(): void
    {
        $promocao = $this->promocao();
        [$regra, $oferta] = $this->regraComOferta($promocao);

        [, $gatilho] = $this->criarGatilho($regra);

        $this->assertSame(0, ItensPedido::where('item_pedido_origem_id', $gatilho->id)->count());
        $this->assertSame(0.0, (float) $oferta->fresh()->pao_qtd_vendida);
    }

    public function test_consumir_e_idempotente_por_item(): void
    {
        $promocao = $this->promocao();
        [$regra, $oferta] = $this->regraComOferta($promocao);

        [, , $itemOferta] = $this->criarItens($regra, $oferta);

        $service = app(PromocaoAdicionalService::class);
        $service->consumir($itemOferta);
        $service->consumir($itemOferta);

        $this->assertSame(1.0, (float) $oferta->fresh()->pao_qtd_vendida);
    }

    public function test_teto_agregado_da_oferta_nao_e_ultrapassado(): void
    {
        $promocao = $this->promocao();
        [$regra, $oferta] = $this->regraComOferta($promocao, [], ['pao_qtd_total' => 1]);

        [, , $itemOferta1] = $this->criarItens($regra, $oferta);
        app(PromocaoAdicionalService::class)->consumir($itemOferta1);

        [$pedido2, $gatilho2] = $this->criarGatilho($regra);
        $itemOferta2 = $this->criarItemOferta($pedido2, $gatilho2, $oferta);

        $this->expectException(PromocaoIndisponivelException::class);
        app(PromocaoAdicionalService::class)->consumir($itemOferta2);
    }

    public function test_estorno_do_gatilho_devolve_saldo_e_retorna_ids_da_oferta_para_cascata(): void
    {
        $promocao = $this->promocao();
        [$regra, $oferta] = $this->regraComOferta($promocao, [], ['pao_qtd_total' => 10]);

        [, $gatilho, $itemOferta] = $this->criarItens($regra, $oferta);
        app(PromocaoAdicionalService::class)->consumir($itemOferta);

        $idsOferta = app(PromocaoAdicionalService::class)->estornarItensDoGatilho($gatilho);

        $this->assertSame([$itemOferta->id], $idsOferta);
        $this->assertSame(0.0, (float) $oferta->fresh()->pao_qtd_vendida);
        $this->assertNotNull(
            PromocaoAdicionalConsumo::where('pac_item_pedido_oferta_id', $itemOferta->id)->first()->pac_revertido_em
        );
    }

    public function test_estorno_nao_devolve_saldo_duas_vezes(): void
    {
        $promocao = $this->promocao();
        [$regra, $oferta] = $this->regraComOferta($promocao, [], ['pao_qtd_total' => 10]);

        [, $gatilho, $itemOferta] = $this->criarItens($regra, $oferta);
        $service = app(PromocaoAdicionalService::class);
        $service->consumir($itemOferta);

        $service->estornarItensDoGatilho($gatilho);
        $service->estornarItensDoGatilho($gatilho);

        $this->assertSame(0.0, (float) $oferta->fresh()->pao_qtd_vendida);
    }

    // ── Limite por pedido ─────────────────────────────────────────────────────

    public function test_limite_por_pedido_bloqueia_segundo_aceite(): void
    {
        $promocao = $this->promocao();
        [$regra, $oferta] = $this->regraComOferta($promocao, ['par_qtd_maxima_por_pedido' => 1]);

        [$pedido, , $itemOferta] = $this->criarItens($regra, $oferta);
        $service = app(PromocaoAdicionalService::class);
        $service->consumir($itemOferta);

        $aceitesAtuais = $service->aceitesNoPedido($regra, $pedido->id);

        $this->expectException(PromocaoIndisponivelException::class);
        $service->validarLimitePorPedido($regra, $aceitesAtuais + 1);
    }

    public function test_limite_por_pedido_em_branco_nao_bloqueia(): void
    {
        $promocao = $this->promocao();
        [$regra] = $this->regraComOferta($promocao, ['par_qtd_maxima_por_pedido' => null]);

        $service = app(PromocaoAdicionalService::class);
        $service->validarLimitePorPedido($regra, 50);

        $this->assertTrue(true);
    }

    // ── Congelamento de preço ────────────────────────────────────────────────

    public function test_item_com_promocao_adicional_nao_e_reprecificado_ao_recalcular(): void
    {
        $promocao = $this->promocao();
        [$regra, $oferta] = $this->regraComOferta($promocao);

        [, $gatilho, $itemOferta] = $this->criarItens($regra, $oferta);

        // Campanha encerra — se o item fosse reprecificado, o gatilho voltaria
        // ao preço normal e a oferta perderia o valor congelado.
        $promocao->update(['promoad_ativa' => false]);

        $gatilho->recalcularValores();
        $itemOferta->recalcularValores();

        $this->assertSame(39.90, $gatilho->item_pedido_valor_unitario);
        $this->assertSame(5.00, $itemOferta->item_pedido_valor_unitario);
    }
}
