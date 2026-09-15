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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
