<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\AtenderPedido;
use App\Models\Categoria;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Mensagens do Atender Pedido para o WhatsApp: conferência antes de confirmar e aviso de pedido confirmado. */
class AtenderPedidoMensagemWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private OpcoesEntregas $retirada;

    private OpcoesPagamento $pix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->retirada = OpcoesEntregas::create(['opcaoentrega_nome' => 'Retirada na loja', 'opcaoentrega_valor_frete' => 0, 'opcaoentrega_min_valor_frete' => 0, 'opcaoentrega_requer_endereco' => false]);
        $this->pix = OpcoesPagamento::create(['opcaopag_nome' => 'Pix', 'opcaopag_desc_nfe' => 'InstantPayment']);
    }

    private function telaComPedido(): Testable
    {
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizza Grande'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 59.90,
        ]);

        return Livewire::test(AtenderPedido::class)
            ->set('itensCarrinho', [[
                'id' => 'tmp_1', 'produto_id' => $produto->id, 'produto_nome' => 'Calabresa', 'categoria_nome' => 'Pizza Grande',
                'quantidade' => 1, 'valor_unitario' => 59.90, 'desconto' => 0, 'valor' => 59.90, 'adicionais_valor' => 0,
                'observacao' => '', 'adicionais' => [],
            ]])
            ->set('clienteData', ['semCliente' => true])
            ->set('entregaPagamentoData', [
                'opcaoEntregaId' => $this->retirada->id,
                'pagamentos' => [['opcaoPagamentoId' => $this->pix->id, 'valor' => '59,90', 'trocoPara' => null]],
            ]);
    }

    public function test_mensagem_de_confirmacao_acompanha_itens_entrega_e_pagamento(): void
    {
        $mensagem = $this->telaComPedido()->instance()->mensagemConfirmacao;

        $this->assertStringContainsString('1x Pizza Grande — Calabresa — R$ 59,90', $mensagem);
        $this->assertStringContainsString('*Total: R$ 59,90*', $mensagem);
        $this->assertStringContainsString('*Entrega:* Retirada na loja', $mensagem);
        $this->assertStringContainsString('*Pagamento:* Pix', $mensagem);
        $this->assertStringNotContainsString('Acompanhe', $mensagem);
    }

    public function test_mensagem_de_confirmacao_comeca_pelo_primeiro_nome_do_cliente(): void
    {
        $tela = $this->telaComPedido()->set('clienteData', ['semCliente' => false, 'nome' => 'JOÃO PEREIRA', 'celular' => '']);

        $this->assertStringStartsWith('*João, confira o seu pedido, por favor:*', $tela->instance()->mensagemConfirmacao);
    }

    public function test_depois_de_salvar_a_tela_em_branco_abre_a_mensagem_final_com_o_link(): void
    {
        $this->telaComPedido()->call('save')->assertHasNoErrors()->assertRedirect(AtenderPedido::getUrl());
        $pedido = Pedido::sole();

        $tela = Livewire::test(AtenderPedido::class)->assertSet('mensagemFinal.pedido_id', $pedido->id);

        $mensagem = $tela->get('mensagemFinal.mensagem');
        $this->assertStringStartsWith("✅ *Pedido nº {$pedido->id} confirmado!*", $mensagem);
        $this->assertStringContainsString($pedido->linkAcompanhamento(), $mensagem);

        $tela->call('fecharMensagemFinal')->assertSet('mensagemFinal', null);
    }
}
