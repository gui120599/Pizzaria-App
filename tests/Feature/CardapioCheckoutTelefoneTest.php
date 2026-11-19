<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\HorarioFuncionamento;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Ponto 1 — identificação do cliente pelo WhatsApp no checkout do cardápio. */
class CardapioCheckoutTelefoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_busca_pelo_whatsapp_reconhece_cadastro_antigo_sem_nono_digito(): void
    {
        $id = $this->clienteAntigo('(62) 8123-4567');

        $this->getJson(route('cardapio.lookup_cliente', ['telefone' => '(62) 98123-4567']))
            ->assertOk()
            ->assertJson(['encontrado' => true, 'cliente_id' => $id, 'nome' => 'Maria Antiga']);
    }

    public function test_busca_por_trecho_do_numero_nao_revela_cadastro(): void
    {
        $this->clienteAntigo('62981234567');

        $this->getJson(route('cardapio.lookup_cliente', ['telefone' => '81234567']))
            ->assertOk()
            ->assertExactJson(['encontrado' => false]);
    }

    public function test_pedido_com_whatsapp_em_outro_formato_usa_o_cadastro_existente(): void
    {
        $id = $this->clienteAntigo('6281234567');

        $this->checkout('+55 (62) 98123-4567')->assertOk();

        $this->assertSame($id, Pedido::sole()->pedido_cliente_id);
        $this->assertSame(1, Cliente::count());
    }

    public function test_pedido_com_whatsapp_sem_ddd_e_recusado_com_mensagem_no_campo(): void
    {
        $this->checkout('98123-4567')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telefone' => 'Informe um telefone válido com DDD, ex.: (62) 99999-9999.']);

        $this->assertSame(0, Pedido::count());
        $this->assertSame(0, Cliente::count());
    }

    private function clienteAntigo(string $celularGravado): int
    {
        $id = DB::table('clientes')->insertGetId([
            'cliente_nome' => 'Maria Antiga',
            'cliente_tipo' => 'Física',
            'cliente_celular' => $celularGravado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->artisan('clientes:normalizar-telefones')->assertSuccessful();

        return $id;
    }

    private function checkout(string $telefone): TestResponse
    {
        HorarioFuncionamento::create([
            'horario_dia_semana' => now()->dayOfWeek,
            'horario_ativo' => true,
            'horario_abertura' => '00:00:00',
            'horario_fechamento' => '23:59:59',
        ]);
        $retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0]);
        $pizza = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Cardápio', 'categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.00,
            'produto_cardapio' => true,
        ]);

        return $this->postJson(route('cardapio.checkout'), [
            'nome' => 'Maria',
            'telefone' => $telefone,
            'opcao_entrega_id' => $retirada->id,
            'pagamento_nome' => 'Dinheiro',
            'itens' => [['id' => $pizza->id, 'qty' => 1]],
        ]);
    }
}
