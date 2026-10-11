<?php

namespace App\Services;

use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use Illuminate\Support\Facades\Auth;

/**
 * Grava as formas de pagamento combinadas de um pedido a partir das linhas do
 * EntregaPagamentoPicker (Atender Pedido e entrega do Painel do Garçom).
 * Atualiza as linhas existentes pela ordem em vez de apagar e recriar: o id
 * da linha é o vínculo com o pagamento que o Nova Venda criou a partir dela
 * (pg_venda_pagamento_pedido_id). "Pagamento já efetuado" só vale em forma
 * com opcaopag_confirmavel_no_pedido e guarda quem confirmou e quando;
 * reconfirmar a mesma forma mantém o registro original.
 */
class PagamentosCombinadosService
{
    /**
     * @param  array<int, array{opcaoPagamentoId?: ?int, valor?: ?string, trocoPara?: ?string, pago?: bool}>  $linhas  Valores mascarados ("1.234,50")
     * @return array<int, string> Nomes das formas, na ordem (texto legado pedido_descricao_pagamento)
     */
    public function sincronizar(Pedido $pedido, array $linhas): array
    {
        $existentes = $pedido->pagamentosCombinados()->orderBy('pg_pedido_ordem')->orderBy('id')->get()->values();
        $nomes = [];

        foreach (array_values($linhas) as $ordem => $linha) {
            $opcao = ($linha['opcaoPagamentoId'] ?? null) ? OpcoesPagamento::find($linha['opcaoPagamentoId']) : null;
            $nomes[] = $opcao?->opcaopag_nome;

            /** @var PagamentosPedido|null $anterior */
            $anterior = $existentes->get($ordem);
            $pago = (bool) ($linha['pago'] ?? false) && (bool) $opcao?->opcaopag_confirmavel_no_pedido;
            $jaConfirmada = $pago && $anterior?->pg_pedido_pago && $anterior->pg_pedido_opcaopagamento_id === $opcao?->id;

            $dados = [
                'pg_pedido_pedido_id' => $pedido->id,
                'pg_pedido_opcaopagamento_id' => $opcao?->id,
                'pg_pedido_opcaopagamento_nome' => $opcao?->opcaopag_nome,
                'pg_pedido_valor' => self::valorDigitado($linha['valor'] ?? '0'),
                'pg_pedido_valor_troco_para' => filled($linha['trocoPara'] ?? null) ? self::valorDigitado($linha['trocoPara']) : null,
                'pg_pedido_ordem' => $ordem,
                'pg_pedido_pago' => $pago,
                'pg_pedido_pago_em' => $pago ? ($jaConfirmada ? $anterior->pg_pedido_pago_em : now()) : null,
                'pg_pedido_pago_usuario_id' => $pago ? ($jaConfirmada ? $anterior->pg_pedido_pago_usuario_id : Auth::id()) : null,
            ];

            $anterior ? $anterior->update($dados) : PagamentosPedido::create($dados);
        }

        $existentes->slice(count($linhas))->each->delete();

        return array_values(array_filter($nomes));
    }

    /** Valor mascarado do EntregaPagamentoPicker ("1.234,50") em reais. */
    public static function valorDigitado(mixed $valor): float
    {
        return ((int) str_replace(['.', ','], '', (string) ($valor ?? '0'))) / 100;
    }
}
