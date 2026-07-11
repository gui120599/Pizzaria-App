<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\PromocoesRelampago\Pages\CreatePromocaoRelampago;
use App\Filament\Resources\PromocoesRelampago\Pages\EditPromocaoRelampago;
use App\Filament\Resources\PromocoesRelampago\Pages\ListPromocoesRelampago;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\PromocaoRelampago;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PromocaoRelampagoFilamentSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['name_first' => 'Admin']));
    }

    private function promocao(): PromocaoRelampago
    {
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
        ]);

        $promocao = PromocaoRelampago::create([
            'promocao_nome' => 'Dia da Pizza',
            'promocao_ativa' => true,
            'promocao_inicio' => now()->subHour(),
            'promocao_fim' => now()->addHour(),
            'promocao_qtd_total' => 40,
        ]);

        $promocao->promocaoProdutos()->create([
            'prp_produto_id' => $produto->id,
            'prp_preco_promocional' => 39.90,
        ]);

        return $promocao;
    }

    public function test_listagem_monta_com_status_e_contador(): void
    {
        $this->promocao();

        Livewire::test(ListPromocoesRelampago::class)->assertOk();
    }

    public function test_formulario_de_criacao_monta_sem_erro(): void
    {
        Livewire::test(CreatePromocaoRelampago::class)->assertOk();
    }

    public function test_opcao_do_select_de_produto_mostra_imagem_e_categoria_sem_saldo(): void
    {
        $reflexao = new \ReflectionMethod(
            \App\Filament\Resources\PromocoesRelampago\Schemas\PromocaoRelampagoForm::class,
            'renderOpcaoProduto',
        );
        $reflexao->setAccessible(true);

        $produto = Produto::create([
            'produto_descricao' => 'Marguerita',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas Especiais'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 60.00,
        ]);

        $html = $reflexao->invoke(null, $produto->fresh('categoria'));

        $this->assertStringContainsString('Pizzas Especiais', $html);
        $this->assertStringContainsString('Marguerita', $html);
        $this->assertStringNotContainsString('Saldo:', $html);
    }

    public function test_edicao_carrega_o_repeater_de_produtos(): void
    {
        $promocao = $this->promocao();

        Livewire::test(EditPromocaoRelampago::class, ['record' => $promocao->id])
            ->assertOk()
            ->assertFormSet(['promocao_nome' => 'Dia da Pizza']);
    }

    public function test_acao_encerrar_desativa_a_promocao(): void
    {
        $promocao = $this->promocao();

        Livewire::test(ListPromocoesRelampago::class)
            ->callTableAction('encerrar', $promocao)
            ->assertHasNoTableActionErrors();

        $this->assertFalse((bool) $promocao->fresh()->promocao_ativa);
    }

    public function test_criacao_de_promocao_recorrente_nao_exige_data_de_fim(): void
    {
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 55.00,
        ]);

        Livewire::test(CreatePromocaoRelampago::class)
            ->fillForm([
                'promocao_nome' => 'Terça da Pizza',
                'promocao_recorrente' => true,
                'promocao_inicio' => now()->subDay()->format('Y-m-d H:i:s'),
                'promocao_dias_semana' => [2],
                'promocao_hora_inicio' => '18:00',
                'promocao_hora_fim' => '20:00',
                'promocaoProdutos' => [[
                    'prp_produto_id' => $produto->id,
                    'prp_preco_promocional' => 39.90,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promocao = PromocaoRelampago::where('promocao_nome', 'Terça da Pizza')->firstOrFail();

        $this->assertTrue($promocao->promocao_recorrente);
        $this->assertNull($promocao->promocao_fim);
        $this->assertSame([2], $promocao->promocao_dias_semana);
    }
}
