<?php

namespace Tests\Feature;

use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusChamadoMesaEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Http\Controllers\MesaClienteController;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\MesaChamado;
use App\Models\MesaParticipante;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\SessaoMesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rotas públicas do QR da mesa: o celular só pede na conta aberta em que se
 * identificou, e só vê as próprias rodadas.
 */
class MesaClienteRotasTest extends TestCase
{
    use RefreshDatabase;

    public function test_codigo_desconhecido_e_404(): void
    {
        $this->get('/mesa/naoexiste')->assertNotFound();
    }

    public function test_mesa_fechada_mostra_o_cardapio_sem_pedir_e_aceita_o_pedido_de_abertura(): void
    {
        $mesa = Mesa::factory()->create(['mesa_nome' => 'Mesa 7']);

        $this->get(route('mesa-cliente.show', $mesa->mesa_codigo_qr))
            ->assertOk()
            ->assertViewHas('mesaCliente', fn (array $dados) => $dados['estado']['estado'] === 'fechada');

        $this->postJson(route('mesa-cliente.abertura', $mesa->mesa_codigo_qr))->assertOk();

        $this->assertTrue(MesaChamado::where('mc_mesa_id', $mesa->id)->where('mc_tipo', TipoChamadoMesaEnum::ABRIR_MESA->value)->exists());
        $this->getJson(route('mesa-cliente.estado', $mesa->mesa_codigo_qr))
            ->assertJsonPath('estado', 'fechada')
            ->assertJsonPath('abertura_solicitada', true);
    }

    public function test_entrar_grava_o_cookie_e_o_celular_passa_a_pedir(): void
    {
        $mesa = $this->mesaAberta();

        $resposta = $this->postJson(route('mesa-cliente.entrar', $mesa->mesa_codigo_qr), ['nome' => 'Ana', 'celular' => '(64) 99999-1234']);

        $resposta->assertOk()
            ->assertJsonPath('estado', 'pedindo')
            ->assertJsonPath('participante.nome', 'Ana')
            ->assertCookie(MesaClienteController::COOKIE);
        $token = $resposta->getCookie(MesaClienteController::COOKIE)->getValue();
        $this->assertSame(MesaParticipante::sole()->id, MesaParticipante::porToken($token)?->id);
    }

    public function test_entrar_sem_celular_com_ddd_e_recusado(): void
    {
        $mesa = $this->mesaAberta();

        $this->postJson(route('mesa-cliente.entrar', $mesa->mesa_codigo_qr), ['nome' => 'Ana', 'celular' => '99999-1234'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Informe o celular com DDD.');

        $this->assertSame(0, MesaParticipante::count());
    }

    public function test_pedido_sem_identificacao_e_recusado(): void
    {
        $mesa = $this->mesaAberta();

        $this->postJson(route('mesa-cliente.pedidos', $mesa->mesa_codigo_qr), $this->rodada($this->produto()))
            ->assertUnauthorized();

        $this->assertSame(0, Pedido::count());
    }

    public function test_pedido_entra_na_conta_pelo_preco_do_servidor(): void
    {
        config(['pizzaria.mesa_cliente.aprovacao_primeiro_pedido' => 'off']);
        $mesa = $this->mesaAberta();
        ['participante' => $participante, 'token' => $token] = $this->entrar($mesa);
        $produto = $this->produto(preco: 30.00);
        $rodada = $this->rodada($produto, quantidade: 2);
        $rodada['itens'][0]['preco'] = 0.01;

        $this->comToken($token)
            ->postJson(route('mesa-cliente.pedidos', $mesa->mesa_codigo_qr), $rodada)
            ->assertOk()
            ->assertJsonPath('estado.rodadas.0.situacao', 'Enviado para a cozinha')
            ->assertJsonPath('estado.conta.subtotal', 60);

        $pedido = Pedido::sole();
        $this->assertSame(PedidoOrigemEnum::MESA_QR, $pedido->pedido_origem);
        $this->assertSame($participante->id, $pedido->pedido_mesa_participante_id);
        $this->assertSame('60.00', $pedido->pedido_valor_total);
    }

    public function test_regra_do_servico_recusada_volta_422_com_a_mensagem(): void
    {
        config(['pizzaria.mesa_cliente.quantidade_maxima' => 3]);
        $mesa = $this->mesaAberta();
        ['token' => $token] = $this->entrar($mesa);

        $this->comToken($token)
            ->postJson(route('mesa-cliente.pedidos', $mesa->mesa_codigo_qr), $this->rodada($this->produto(), quantidade: 4))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No máximo 3 unidades de cada item por pedido. Para mais, chame o garçom.');
    }

    public function test_token_de_conta_fechada_nao_pede_e_o_estado_mostra_encerrada(): void
    {
        $mesa = $this->mesaAberta();
        ['token' => $token] = $this->entrar($mesa);
        app(SessaoMesaService::class)->fechar($mesa->sessaoAtual);

        $this->comToken($token)
            ->postJson(route('mesa-cliente.pedidos', $mesa->mesa_codigo_qr), $this->rodada($this->produto()))
            ->assertGone();
        $this->comToken($token)
            ->getJson(route('mesa-cliente.estado', $mesa->mesa_codigo_qr))
            ->assertJsonPath('estado', 'encerrada');
    }

    public function test_token_usado_no_qr_de_outra_mesa_nao_pede_nela(): void
    {
        $mesa = $this->mesaAberta();
        ['token' => $token] = $this->entrar($mesa);
        $outra = $this->mesaAberta();

        $this->comToken($token)
            ->postJson(route('mesa-cliente.pedidos', $outra->mesa_codigo_qr), $this->rodada($this->produto()))
            ->assertConflict();

        $this->assertSame(0, Pedido::count());
    }

    public function test_conta_transferida_redireciona_o_qr_antigo_para_a_mesa_nova(): void
    {
        $mesa = $this->mesaAberta();
        ['token' => $token] = $this->entrar($mesa);
        $nova = Mesa::factory()->create();
        app(SessaoMesaService::class)->trocarMesa($mesa->sessaoAtual, $nova->id);

        $this->comToken($token)
            ->get(route('mesa-cliente.show', $mesa->mesa_codigo_qr))
            ->assertRedirect(route('mesa-cliente.show', $nova->mesa_codigo_qr));
    }

    public function test_celular_bloqueado_nao_pede(): void
    {
        $mesa = $this->mesaAberta();
        ['participante' => $participante, 'token' => $token] = $this->entrar($mesa);
        app(ParticipanteMesaService::class)->bloquear($participante, User::factory()->create(['name_first' => 'Garçom']));

        $this->comToken($token)
            ->postJson(route('mesa-cliente.pedidos', $mesa->mesa_codigo_qr), $this->rodada($this->produto()))
            ->assertForbidden();
    }

    public function test_estado_mostra_so_as_rodadas_deste_celular(): void
    {
        config(['pizzaria.mesa_cliente.aprovacao_primeiro_pedido' => 'off']);
        $mesa = $this->mesaAberta();
        ['token' => $daAna] = $this->entrar($mesa, 'Ana', '64999991234');
        ['token' => $doJoao] = $this->entrar($mesa, 'João', '64988887777');
        $produto = $this->produto();
        $this->comToken($daAna)
            ->postJson(route('mesa-cliente.pedidos', $mesa->mesa_codigo_qr), $this->rodada($produto))
            ->assertOk();

        $this->comToken($doJoao)
            ->getJson(route('mesa-cliente.estado', $mesa->mesa_codigo_qr))
            ->assertJsonPath('estado', 'pedindo')
            ->assertJsonCount(0, 'rodadas')
            ->assertJsonPath('conta.subtotal', 30);
    }

    public function test_pedir_a_conta_abre_o_chamado_e_abrir_mesa_nao_e_tipo_aceito(): void
    {
        $mesa = $this->mesaAberta();
        ['token' => $token] = $this->entrar($mesa);

        $this->comToken($token)
            ->postJson(route('mesa-cliente.chamados', $mesa->mesa_codigo_qr), ['tipo' => TipoChamadoMesaEnum::PEDIR_CONTA->value])
            ->assertOk()
            ->assertJsonPath('estado.chamados', [TipoChamadoMesaEnum::PEDIR_CONTA->value]);
        $this->comToken($token)
            ->postJson(route('mesa-cliente.chamados', $mesa->mesa_codigo_qr), ['tipo' => TipoChamadoMesaEnum::ABRIR_MESA->value])
            ->assertJsonValidationErrors('tipo');

        $this->assertSame(StatusChamadoMesaEnum::PENDENTE, MesaChamado::sole()->mc_status);
    }

    public function test_muitas_tentativas_de_entrar_sao_barradas(): void
    {
        $mesa = $this->mesaAberta();

        foreach (range(1, 10) as $tentativa) {
            $this->postJson(route('mesa-cliente.entrar', $mesa->mesa_codigo_qr), ['nome' => 'Ana', 'celular' => '1'])->assertUnprocessable();
        }

        $this->postJson(route('mesa-cliente.entrar', $mesa->mesa_codigo_qr), ['nome' => 'Ana', 'celular' => '1'])->assertTooManyRequests();
    }

    /** Requisição JSON só leva cookies com withCredentials(). */
    private function comToken(string $token): self
    {
        return $this->withCredentials()->withCookie(MesaClienteController::COOKIE, $token);
    }

    private function mesaAberta(): Mesa
    {
        $mesa = Mesa::factory()->create();
        app(SessaoMesaService::class)->abrir($mesa->id, User::factory()->create(['name_first' => 'Garçom'])->id, pessoas: 2);

        return $mesa->fresh();
    }

    /** @return array{participante: MesaParticipante, token: string} */
    private function entrar(Mesa $mesa, string $nome = 'Ana', string $celular = '64999991234'): array
    {
        return app(ParticipanteMesaService::class)->entrar($mesa, $nome, $celular);
    }

    private function produto(float $preco = 30.00): Produto
    {
        return Produto::create([
            'produto_descricao' => 'Pizza Calabresa',
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Pizzas'], ['categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => $preco,
            'produto_cardapio' => true,
        ]);
    }

    /** @return array{chave: string, itens: list<array<string, mixed>>} */
    private function rodada(Produto $produto, int $quantidade = 1): array
    {
        return ['chave' => (string) Str::uuid(), 'itens' => [['id' => $produto->id, 'qty' => $quantidade]]];
    }
}
