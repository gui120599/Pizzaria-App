<?php

namespace Tests\Unit\Support\Pedido;

use App\Support\Pedido\MensagemPedidoWhatsapp;
use PHPUnit\Framework\TestCase;

class MensagemPedidoWhatsappTest extends TestCase
{
    /** @return array<string, mixed> */
    private function dados(array $extra = []): array
    {
        return $extra + [
            'itens' => [
                [
                    'categoria_nome' => 'Pizza Grande', 'produto_nome' => 'Calabresa', 'quantidade' => 2, 'valor' => 101.80,
                    'respostas_linhas' => ['Borda: Catupiry'], 'adicionais' => [['id' => 1, 'nome' => 'Bacon', 'valor' => 6.0]], 'observacao' => 'sem cebola',
                ],
                ['categoria_nome' => 'Refrigerante', 'produto_nome' => 'Kuat 2L', 'quantidade' => 1, 'valor' => 0, 'item_origem_id' => 10],
            ],
            'totais' => ['itens' => 125.80, 'desconto' => 24.00, 'frete' => 8.00, 'total' => 109.80],
            'entrega' => 'Delivery',
            'endereco' => 'Rua A, 10, Centro',
            'pagamento' => 'Dinheiro (troco para R$ 150,00)',
            'observacao' => 'Interfone 12',
        ];
    }

    public function test_confirmacao_lista_itens_totais_entrega_e_pede_confirmacao(): void
    {
        $this->assertSame(implode("\n", [
            '*Confira o seu pedido, por favor:*',
            '',
            '2x Pizza Grande — Calabresa — R$ 101,80',
            '   • Borda: Catupiry',
            '   + Bacon',
            '   Obs.: sem cebola',
            '🎁 1x Refrigerante — Kuat 2L — Grátis',
            '',
            'Itens: R$ 125,80',
            'Descontos: − R$ 24,00',
            'Taxa de entrega: R$ 8,00',
            '*Total: R$ 109,80*',
            '',
            '*Entrega:* Delivery',
            '*Endereço:* Rua A, 10, Centro',
            '*Pagamento:* Dinheiro (troco para R$ 150,00)',
            '*Obs.:* Interfone 12',
            '',
            'Está tudo certo? Posso confirmar? 😊',
        ]), MensagemPedidoWhatsapp::confirmacao($this->dados()));
    }

    public function test_confirmacao_de_pedido_gravado_leva_numero_e_link(): void
    {
        $mensagem = MensagemPedidoWhatsapp::confirmacao($this->dados(['pedido_id' => 321, 'link' => 'https://loja/acompanhar/321']));

        $this->assertStringStartsWith('*Pedido nº 321* — confira, por favor:', $mensagem);
        $this->assertStringEndsWith("🔍 Acompanhe o seu pedido:\nhttps://loja/acompanhar/321", $mensagem);
    }

    public function test_primeiro_nome_do_cliente_abre_as_mensagens(): void
    {
        $dados = $this->dados(['cliente' => '  MARIA DA SILVA ', 'pedido_id' => 321, 'link' => 'https://loja/acompanhar/321']);

        $this->assertStringStartsWith("*Maria, confira o seu pedido nº 321, por favor:*\n", MensagemPedidoWhatsapp::confirmacao($dados));
        $this->assertStringStartsWith("*Maria, confira o seu pedido, por favor:*\n", MensagemPedidoWhatsapp::confirmacao($this->dados(['cliente' => 'maria'])));
        $this->assertStringStartsWith("✅ *Maria, seu pedido nº 321 está confirmado!*\n", MensagemPedidoWhatsapp::confirmado($dados));
    }

    public function test_pedido_confirmado_traz_numero_e_link_de_acompanhamento_sem_descontos_zerados(): void
    {
        $mensagem = MensagemPedidoWhatsapp::confirmado($this->dados([
            'totais' => ['itens' => 50.0, 'desconto' => 0.0, 'frete' => 0.0, 'total' => 50.0],
            'pedido_id' => 321,
            'link' => 'https://loja/acompanhar/321',
            'endereco' => null,
            'observacao' => null,
        ]));

        $this->assertStringStartsWith("✅ *Pedido nº 321 confirmado!*\n", $mensagem);
        $this->assertStringContainsString("🔍 *Acompanhe o seu pedido em tempo real:*\nhttps://loja/acompanhar/321", $mensagem);
        $this->assertStringNotContainsString('Descontos', $mensagem);
        $this->assertStringNotContainsString('Taxa de entrega', $mensagem);
        $this->assertStringNotContainsString('Endereço', $mensagem);
    }
}
