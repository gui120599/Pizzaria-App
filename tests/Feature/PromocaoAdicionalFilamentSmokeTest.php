<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\PromocoesAdicionais\Pages\CreatePromocaoAdicional;
use App\Filament\Resources\PromocoesAdicionais\Pages\EditPromocaoAdicional;
use App\Filament\Resources\PromocoesAdicionais\Pages\ListPromocoesAdicionais;
use App\Models\Categoria;
use App\Models\OpcoesPagamento;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\User;
use App\Services\PrecificadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PromocaoAdicionalFilamentSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function produto(string $nome, float $preco): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
        ]);
    }

    private function promocao(): PromocaoAdicional
    {
        $gatilho = $this->produto('Calabresa', 59.90);
        $oferta = $this->produto('Pizza Brotinho', 20.00);

        $promocao = PromocaoAdicional::create([
            'promoad_nome' => 'Dia do Cliente',
            'promoad_ativa' => true,
            'promoad_inicio' => now()->subHour(),
            'promoad_fim' => now()->addHour(),
        ]);

        $regra = $promocao->regras()->create([
            'par_produto_gatilho_id' => $gatilho->id,
            'par_preco_gatilho_override' => 39.90,
        ]);

        $regra->ofertas()->create([
            'pao_produto_oferta_id' => $oferta->id,
            'pao_valor_adicional' => 5.00,
        ]);

        return $promocao;
    }

    public function test_listagem_monta_com_status(): void
    {
        $this->promocao();

        Livewire::test(ListPromocoesAdicionais::class)->assertOk();
    }

    public function test_formulario_de_criacao_monta_sem_erro(): void
    {
        Livewire::test(CreatePromocaoAdicional::class)->assertOk();
    }

    public function test_edicao_carrega_o_repeater_de_regras(): void
    {
        $promocao = $this->promocao();

        Livewire::test(EditPromocaoAdicional::class, ['record' => $promocao->id])
            ->assertOk()
            ->assertFormSet(['promoad_nome' => 'Dia do Cliente']);
    }

    public function test_edicao_mostra_cada_regra_com_o_gatilho_e_a_quantidade_de_ofertas_no_titulo(): void
    {
        $promocao = $this->promocao();

        Livewire::test(EditPromocaoAdicional::class, ['record' => $promocao->id])
            ->assertOk()
            ->assertSee('Calabresa — 1 oferta');
    }

    public function test_acao_encerrar_desativa_a_promocao(): void
    {
        $promocao = $this->promocao();

        Livewire::test(ListPromocoesAdicionais::class)
            ->callTableAction('encerrar', $promocao)
            ->assertHasNoTableActionErrors();

        $this->assertFalse((bool) $promocao->fresh()->promoad_ativa);
    }

    public function test_criacao_com_regra_e_ofertas_salva_corretamente(): void
    {
        $gatilho = $this->produto('Calabresa', 59.90);
        $brotinho = $this->produto('Pizza Brotinho', 20.00);
        $refrigerante = $this->produto('Refrigerante 2L', 12.00);

        Livewire::test(CreatePromocaoAdicional::class)
            ->fillForm([
                'promoad_nome' => 'Dia do Cliente',
                'promoad_inicio' => now()->format('Y-m-d H:i:s'),
                'promoad_fim' => now()->addDays(30)->format('Y-m-d H:i:s'),
                'regras' => [[
                    'par_produto_gatilho_id' => $gatilho->id,
                    // Money::make() espera o valor no formato BR (vírgula
                    // decimal) — mesmo gotcha documentado na Promoção Relâmpago.
                    'par_preco_gatilho_override' => '39,90',
                    'par_qtd_maxima_por_pedido' => 1,
                    'ofertas' => [
                        ['pao_produto_oferta_id' => $brotinho->id, 'pao_valor_adicional' => '5.00'],
                        ['pao_produto_oferta_id' => $refrigerante->id, 'pao_valor_adicional' => '8.00'],
                    ],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promocao = PromocaoAdicional::where('promoad_nome', 'Dia do Cliente')->firstOrFail();
        $regra = $promocao->regras()->with('ofertas')->firstOrFail();

        $this->assertSame(39.90, (float) $regra->par_preco_gatilho_override);
        $this->assertSame($gatilho->id, $regra->par_produto_gatilho_id);
        $this->assertCount(2, $regra->ofertas);
        $this->assertSame(5.00, (float) $regra->ofertas->firstWhere('pao_produto_oferta_id', $brotinho->id)->pao_valor_adicional);
        $this->assertSame(8.00, (float) $regra->ofertas->firstWhere('pao_produto_oferta_id', $refrigerante->id)->pao_valor_adicional);
    }

    public function test_preco_do_gatilho_em_branco_salva_nulo_e_nao_zera_o_produto(): void
    {
        // O Money mostrava vazio como 0,00 e salvava 0: o refri da campanha saía de graça.
        $refri = $this->produto('Coca-Cola 2L', 16.00);
        $brotinho = $this->produto('Pizza Brotinho', 30.00);

        Livewire::test(CreatePromocaoAdicional::class)
            ->fillForm([
                'promoad_nome' => 'Grande + Refri',
                'promoad_inicio' => now()->subHour()->format('Y-m-d H:i:s'),
                'promoad_fim' => now()->addHour()->format('Y-m-d H:i:s'),
                'regras' => [[
                    'par_produto_gatilho_id' => $refri->id,
                    'par_preco_gatilho_override' => '0,00',
                    'par_qtd_maxima_por_pedido' => 1,
                    'ofertas' => [['pao_produto_oferta_id' => $brotinho->id, 'pao_valor_adicional' => '0']],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promocao = PromocaoAdicional::where('promoad_nome', 'Grande + Refri')->firstOrFail();
        $this->assertNull($promocao->regras()->firstOrFail()->par_preco_gatilho_override);

        Livewire::test(EditPromocaoAdicional::class, ['record' => $promocao->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($promocao->regras()->firstOrFail()->par_preco_gatilho_override);
        $this->assertSame(16.0, app(PrecificadorService::class)->resolver($refri->fresh())->valorUnitario);
    }

    public function test_override_zero_ja_gravado_e_ignorado_no_preco(): void
    {
        $refri = $this->produto('Coca-Cola 2L', 16.00);
        $promocao = $this->promocao();
        $regra = $promocao->regras()->create(['par_produto_gatilho_id' => $refri->id, 'par_qtd_maxima_por_pedido' => 1]);
        $regra->ofertas()->create(['pao_produto_oferta_id' => $this->produto('Brotinho', 30.00)->id, 'pao_valor_adicional' => 0]);

        // Linhas salvas antes da correção ficaram com 0.00 no banco.
        DB::table('promocao_adicional_regras')->where('id', $regra->id)->update(['par_preco_gatilho_override' => 0]);
        app(PrecificadorService::class)->esquecerPromocoes();

        $this->assertNull($regra->fresh()->par_preco_gatilho_override);
        $this->assertSame(16.0, app(PrecificadorService::class)->resolver($refri->fresh())->valorUnitario);
    }

    public function test_criacao_de_promocao_recorrente_nao_exige_data_de_fim(): void
    {
        $gatilho = $this->produto('Calabresa', 59.90);
        $oferta = $this->produto('Pizza Brotinho', 20.00);

        Livewire::test(CreatePromocaoAdicional::class)
            ->fillForm([
                'promoad_nome' => 'Terça do Cliente',
                'promoad_recorrente' => true,
                'promoad_inicio' => now()->subDay()->format('Y-m-d H:i:s'),
                'promoad_dias_semana' => [2],
                'promoad_hora_inicio' => '18:00',
                'promoad_hora_fim' => '22:00',
                'regras' => [[
                    'par_produto_gatilho_id' => $gatilho->id,
                    'ofertas' => [
                        ['pao_produto_oferta_id' => $oferta->id, 'pao_valor_adicional' => '5.00'],
                    ],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promocao = PromocaoAdicional::where('promoad_nome', 'Terça do Cliente')->firstOrFail();

        $this->assertTrue($promocao->promoad_recorrente);
        $this->assertNull($promocao->promoad_fim);
        $this->assertSame([2], $promocao->promoad_dias_semana);
    }

    public function test_criacao_com_restricao_de_pagamento_e_fracionado(): void
    {
        $gatilho = $this->produto('Calabresa', 59.90);
        $oferta = $this->produto('Pizza Brotinho', 20.00);
        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'PIX']);

        Livewire::test(CreatePromocaoAdicional::class)
            ->fillForm([
                'promoad_nome' => 'Dia do Cliente',
                'promoad_inicio' => now()->format('Y-m-d H:i:s'),
                'promoad_fim' => now()->addDays(30)->format('Y-m-d H:i:s'),
                'promoad_aplica_fracionado' => true,
                'opcoesPagamento' => [$pix->id],
                'regras' => [[
                    'par_produto_gatilho_id' => $gatilho->id,
                    'ofertas' => [
                        ['pao_produto_oferta_id' => $oferta->id, 'pao_valor_adicional' => '5.00'],
                    ],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promocao = PromocaoAdicional::where('promoad_nome', 'Dia do Cliente')->firstOrFail();

        $this->assertTrue($promocao->promoad_aplica_fracionado);
        $this->assertSame([$pix->id], $promocao->opcoesPagamento->pluck('id')->all());
    }

    public function test_quantidade_limitada_ligada_salva_o_teto_e_desligada_volta_a_ilimitado(): void
    {
        $promocao = $this->promocao();

        Livewire::test(EditPromocaoAdicional::class, ['record' => $promocao->id])
            ->assertFormSet(['promoad_quantidade_limitada' => false])
            ->fillForm(['promoad_quantidade_limitada' => true, 'promoad_qtd_total' => 20])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(20, $promocao->fresh()->promoad_qtd_total);

        Livewire::test(EditPromocaoAdicional::class, ['record' => $promocao->id])
            ->assertFormSet(['promoad_quantidade_limitada' => true])
            ->fillForm(['promoad_quantidade_limitada' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($promocao->fresh()->promoad_qtd_total);
    }

    public function test_quantidade_limitada_exige_quantidade_de_pelo_menos_1(): void
    {
        $promocao = $this->promocao();

        Livewire::test(EditPromocaoAdicional::class, ['record' => $promocao->id])
            ->fillForm(['promoad_quantidade_limitada' => true, 'promoad_qtd_total' => 0])
            ->call('save')
            ->assertHasFormErrors(['promoad_qtd_total' => 'min']);

        Livewire::test(EditPromocaoAdicional::class, ['record' => $promocao->id])
            ->fillForm(['promoad_quantidade_limitada' => true, 'promoad_qtd_total' => null])
            ->call('save')
            ->assertHasFormErrors(['promoad_qtd_total' => 'required']);
    }

    public function test_listagem_mostra_o_saldo_restante_da_campanha(): void
    {
        $promocao = $this->promocao();
        $promocao->update(['promoad_qtd_total' => 20, 'promoad_qtd_vendida' => 15]);

        Livewire::test(ListPromocoesAdicionais::class)
            ->assertTableColumnStateSet('saldo', '5 de 20', $promocao);
    }

    public function test_adicionar_gatilhos_por_categoria_cria_regras_com_varias_ofertas_sem_duplicar(): void
    {
        $promocao = $this->promocao();
        $grande = Categoria::create(['categoria_nome' => 'Pizza Grande']);
        $refri = $this->produto('Guaraná Kuat 2L', 12.00);
        $brotinho = $this->produto('Brotinho Chocolate', 25.00);
        [$calabresa, $frango, $portuguesa] = collect(['Calabresa Grande', 'Frango Grande', 'Portuguesa Grande'])
            ->map(fn (string $nome) => Produto::create([
                'produto_descricao' => $nome,
                'produto_categoria_id' => $grande->id,
                'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
                'produto_preco_venda' => 60.00,
            ]))
            ->all();
        $promocao->regras()->create(['par_produto_gatilho_id' => $calabresa->id, 'par_qtd_maxima_por_pedido' => 1])
            ->ofertas()->create(['pao_produto_oferta_id' => $refri->id, 'pao_valor_adicional' => 3.00]);

        Livewire::test(EditPromocaoAdicional::class, ['record' => $promocao->id])
            ->callAction('adicionarGatilhosPorCategoria', [
                'categoria_id' => $grande->id,
                'ofertas' => [
                    ['pao_produto_oferta_id' => $refri->id, 'pao_valor_adicional' => 0, 'pao_qtd_total' => null],
                    ['pao_produto_oferta_id' => $brotinho->id, 'pao_valor_adicional' => 5, 'pao_qtd_total' => 10],
                ],
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('2 regra(s) criada(s), 5 oferta(s) adicionada(s).');

        $regras = $promocao->regras()->with('ofertas')->get()->keyBy('par_produto_gatilho_id');

        // Regra nova: as duas ofertas, com os valores informados.
        $ofertasFrango = $regras[$frango->id]->ofertas->keyBy('pao_produto_oferta_id');
        $this->assertSame(['0.00', '5.00'], [$ofertasFrango[$refri->id]->pao_valor_adicional, $ofertasFrango[$brotinho->id]->pao_valor_adicional]);
        $this->assertSame(10, $ofertasFrango[$brotinho->id]->pao_qtd_total);
        $this->assertNull($regras[$frango->id]->par_qtd_maxima_por_pedido);
        $this->assertCount(2, $regras[$portuguesa->id]->ofertas);

        // Gatilho existente: ganha só o brotinho; o refri e o limite não mudam.
        $ofertasCalabresa = $regras[$calabresa->id]->ofertas->keyBy('pao_produto_oferta_id');
        $this->assertCount(2, $ofertasCalabresa);
        $this->assertSame('3.00', $ofertasCalabresa[$refri->id]->pao_valor_adicional);
        $this->assertSame(1, $regras[$calabresa->id]->par_qtd_maxima_por_pedido);
    }

    public function test_adicionar_gatilhos_por_categoria_recusa_produto_oferecido_repetido(): void
    {
        $promocao = $this->promocao();
        $refri = $this->produto('Guaraná Kuat 2L', 12.00);

        Livewire::test(EditPromocaoAdicional::class, ['record' => $promocao->id])
            ->callAction('adicionarGatilhosPorCategoria', [
                'categoria_id' => Categoria::firstOrFail()->id,
                'ofertas' => [
                    ['pao_produto_oferta_id' => $refri->id, 'pao_valor_adicional' => 0],
                    ['pao_produto_oferta_id' => $refri->id, 'pao_valor_adicional' => 2],
                ],
            ])
            ->assertHasActionErrors(['ofertas.1.pao_produto_oferta_id']);

        $this->assertSame(0, $promocao->regras()->whereHas('ofertas', fn ($q) => $q->where('pao_produto_oferta_id', $refri->id))->count());
    }
}
