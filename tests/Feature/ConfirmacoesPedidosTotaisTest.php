<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Livewire\ConfirmacoesPedidos;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Editar + Salvar um pedido do cardápio na tela de Confirmações não pode
 * recontar o desconto nem cobrar frete quando o pedido já passou do mínimo.
 * Regressão do pedido 52911 (meia a meia, ½ com promoção de produto).
 */
class ConfirmacoesPedidosTotaisTest extends TestCase
{
    use RefreshDatabase;

    private Categoria $categoria;

    private OpcoesEntregas $entrega;

    private User $atendente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);

        $this->categoria = Categoria::create([
            'categoria_nome' => 'Pizzas',
            'categoria_permite_sabores' => true,
            'categoria_max_sabores' => 2,
        ]);

        $this->entrega = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Entregar',
            'opcaoentrega_valor_frete' => 4.00,
            'opcaoentrega_min_valor_frete' => 50.00,
        ]);

        $this->atendente = User::factory()->create(['name_first' => 'Atendente']);
        $this->atendente->assignRole('Atendente');
    }

    private function pizza(string $nome, float $preco, ?float $promocional = null): Produto
    {
        return Produto::create([
            'produto_descricao' => $nome,
            'produto_categoria_id' => $this->categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_preco_promocional' => $promocional,
            'produto_cardapio' => true,
        ]);
    }

    private function checkoutMeiaAMeia(Produto $a, Produto $b): Pedido
    {
        $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Guilherme',
            'telefone' => '64993926738',
            'endereco' => 'Rua Teste, 100',
            'opcao_entrega_id' => $this->entrega->id,
            'pagamento_nome' => 'Pix',
            'itens' => [[
                'id' => $a->id,
                'qty' => 1,
                'sabores' => [['id' => $a->id], ['id' => $b->id]],
            ]],
        ])->assertOk();

        return Pedido::latest('id')->firstOrFail();
    }

    private function salvar(Pedido $pedido): void
    {
        Livewire::actingAs($this->atendente)
            ->test(ConfirmacoesPedidos::class)
            ->call('abrirEdicao', $pedido->id)
            ->set('editOpcaoEntregaId', (string) $this->entrega->id)
            ->set('editEndereco', 'Rua Teste, 100')
            ->set('editPagamentoNome', 'Pix')
            ->call('salvarAlteracoes')
            ->assertHasNoErrors();
    }

    public function test_salvar_nao_reconta_desconto_nem_cobra_frete_acima_do_minimo(): void
    {
        $comDesconto = $this->pizza('Frango Especial', 75.90, 49.90);
        $comum = $this->pizza('A Moda', 75.90);

        $pedido = $this->checkoutMeiaAMeia($comDesconto, $comum);

        // Estado gravado pelo checkout (referência correta).
        $this->assertSame('75.90', $pedido->pedido_valor_itens);
        $this->assertSame('13.00', $pedido->pedido_valor_desconto);
        $this->assertSame('0.00', $pedido->pedido_valor_frete);
        $this->assertSame('62.90', $pedido->pedido_valor_total);

        $this->salvar($pedido);

        $pedido->refresh();
        $this->assertSame('75.90', $pedido->pedido_valor_itens);
        $this->assertSame('13.00', $pedido->pedido_valor_desconto);
        $this->assertSame('0.00', $pedido->pedido_valor_frete);
        $this->assertSame('62.90', $pedido->pedido_valor_total);
    }

    public function test_salvar_mantem_frete_uma_vez_quando_liquido_fica_abaixo_do_minimo(): void
    {
        $comDesconto = $this->pizza('Frango Especial', 75.90, 49.90);
        $barata = $this->pizza('Muçarela', 20.00);

        $pedido = $this->checkoutMeiaAMeia($comDesconto, $barata);

        // (75,90 + 20,00) / 2 = 47,95 bruto; desconto 13,00; líquido 34,95 (< 50) → frete 4,00.
        $this->assertSame('4.00', $pedido->pedido_valor_frete);

        $this->salvar($pedido);
        $pedido->refresh();

        $liquido = round((float) $pedido->pedido_valor_itens - (float) $pedido->pedido_valor_desconto, 2);

        $this->assertSame('4.00', $pedido->pedido_valor_frete);
        $this->assertEqualsWithDelta($liquido + 4.00, (float) $pedido->pedido_valor_total, 0.001);
    }

    public function test_comanda_de_impressao_nao_mostra_taxa_quando_frete_e_zero(): void
    {
        $pedido = $this->checkoutMeiaAMeia(
            $this->pizza('Frango Especial', 75.90, 49.90),
            $this->pizza('A Moda', 75.90),
        );
        $this->salvar($pedido);

        $resposta = $this->get(route('pedido.imprimir', $pedido->id))->assertOk();

        $resposta->assertDontSee('Taxa de Entrega');
        $resposta->assertSee('75,90'); // (+) Valor Produtos = bruto
        $resposta->assertSee('62,90'); // (=) Valor Total
    }
}
