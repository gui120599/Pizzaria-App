<?php

namespace App\Support\Pedido;

use App\Support\FormatoQuantidade;
use Illuminate\Support\Str;

/**
 * Textos que o atendente copia e cola na conversa do WhatsApp do cliente:
 * a conferência antes de confirmar e o aviso de pedido confirmado, com o
 * link de acompanhamento. Asterisco = negrito no WhatsApp.
 *
 * Os itens vêm no formato da lista do PedidoProdutoSelector (o mesmo de
 * AtenderPedido::$itensCarrinho), com 'valor' já líquido de promoções.
 *
 * @phpstan-type DadosPedido array{
 *     itens: list<array<string, mixed>>,
 *     cliente?: ?string,
 *     totais: array{itens: float, desconto: float, frete: float, total: float},
 *     entrega?: ?string,
 *     endereco?: ?string,
 *     pagamento?: ?string,
 *     observacao?: ?string,
 *     pedido_id?: ?int,
 *     link?: ?string,
 * }
 */
final class MensagemPedidoWhatsapp
{
    /**
     * Para o cliente conferir antes de o pedido ser confirmado. Pedido já
     * gravado leva o número e o link de acompanhamento.
     *
     * @param  DadosPedido  $dados
     */
    public static function confirmacao(array $dados): string
    {
        $nome = self::primeiroNome($dados['cliente'] ?? null);
        $numero = filled($dados['pedido_id'] ?? null) ? " nº {$dados['pedido_id']}" : '';
        $titulo = $nome !== null
            ? "*{$nome}, confira o seu pedido{$numero}, por favor:*"
            : ($numero !== '' ? "*Pedido{$numero}* — confira, por favor:" : '*Confira o seu pedido, por favor:*');

        $final = ['', 'Está tudo certo? Posso confirmar? 😊'];

        if (filled($dados['link'] ?? null)) {
            $final = [...$final, '', '🔍 Acompanhe o seu pedido:', $dados['link']];
        }

        return self::montar($titulo, $dados, $final);
    }

    /**
     * Pedido salvo/confirmado: número, resumo e link de acompanhamento.
     *
     * @param  DadosPedido  $dados
     */
    public static function confirmado(array $dados): string
    {
        $nome = self::primeiroNome($dados['cliente'] ?? null);
        $titulo = $nome !== null
            ? "✅ *{$nome}, seu pedido nº {$dados['pedido_id']} está confirmado!*"
            : "✅ *Pedido nº {$dados['pedido_id']} confirmado!*";

        return self::montar($titulo, $dados, [
            '',
            '🔍 *Acompanhe o seu pedido em tempo real:*',
            (string) ($dados['link'] ?? ''),
            '',
            'Obrigado pela preferência! 🍕',
        ]);
    }

    /**
     * @param  DadosPedido  $dados
     * @param  list<string>  $final
     */
    private static function montar(string $titulo, array $dados, array $final): string
    {
        $linhas = [$titulo, ''];

        foreach ($dados['itens'] as $item) {
            array_push($linhas, ...self::linhasDoItem($item));
        }

        $totais = $dados['totais'];
        $linhas[] = '';
        $linhas[] = 'Itens: '.self::reais($totais['itens']);

        if ($totais['desconto'] > 0.004) {
            $linhas[] = 'Descontos: − '.self::reais($totais['desconto']);
        }

        if ($totais['frete'] > 0.004) {
            $linhas[] = 'Taxa de entrega: '.self::reais($totais['frete']);
        }

        $linhas[] = '*Total: '.self::reais($totais['total']).'*';

        $detalhes = array_filter([
            filled($dados['entrega'] ?? null) ? '*Entrega:* '.$dados['entrega'] : null,
            filled($dados['endereco'] ?? null) ? '*Endereço:* '.$dados['endereco'] : null,
            filled($dados['pagamento'] ?? null) ? '*Pagamento:* '.$dados['pagamento'] : null,
            filled($dados['observacao'] ?? null) ? '*Obs.:* '.trim((string) $dados['observacao']) : null,
        ]);

        if ($detalhes !== []) {
            $linhas = [...$linhas, '', ...$detalhes];
        }

        return implode("\n", [...$linhas, ...$final]);
    }

    /**
     * "2x Pizza Grande — Calabresa — R$ 119,80" e, abaixo, perguntas,
     * adicionais e observação do item.
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private static function linhasDoItem(array $item): array
    {
        $nome = (string) ($item['produto_nome'] ?? 'Item');
        $nome = filled($item['categoria_nome'] ?? null) ? $item['categoria_nome'].' — '.$nome : $nome;
        $valor = (float) ($item['valor'] ?? 0);
        $preco = $valor <= 0.004 ? 'Grátis' : self::reais($valor);
        $prefixo = ! empty($item['item_origem_id']) || ! empty($item['oferta_id']) ? '🎁 ' : '';

        $linhas = [$prefixo.FormatoQuantidade::item($item['quantidade'] ?? 1).'x '.$nome.' — '.$preco];

        foreach ((array) ($item['respostas_linhas'] ?? []) as $resposta) {
            $linhas[] = '   • '.$resposta;
        }

        $adicionais = collect((array) ($item['adicionais'] ?? []))->pluck('nome')->filter()->all();

        if ($adicionais !== []) {
            $linhas[] = '   + '.implode(', ', $adicionais);
        }

        if (filled($item['observacao'] ?? null)) {
            $linhas[] = '   Obs.: '.trim((string) $item['observacao']);
        }

        return $linhas;
    }

    /** "MARIA DA SILVA" → "Maria": o título fica pessoal e confirma que é a conversa certa. */
    public static function primeiroNome(?string $nome): ?string
    {
        $primeiro = Str::before(trim((string) $nome).' ', ' ');

        return $primeiro !== '' ? Str::title(Str::lower($primeiro)) : null;
    }

    private static function reais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }
}
