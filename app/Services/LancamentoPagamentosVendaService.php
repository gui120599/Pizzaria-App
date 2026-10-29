<?php

namespace App\Services;

use App\Enums\FormaTrocoEnum;
use App\Models\Maquininha;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Venda;
use Illuminate\Support\Collection;

/**
 * Grava pagamentos numa Venda — extraído sem alteração de comportamento de
 * App\Filament\Pages\OperarVenda (gravarPagamento e lancarPagamentosDosPedidos)
 * para ser reaproveitado pelo webhook Stone (StoneVendaAutomaticaService), que
 * não pode instanciar uma Livewire Page: a venda criada pelo webhook precisa
 * receber os pagamentos já efetuados no pedido (pg_pedido_pago), senão ficaria
 * "devendo" o que o cliente já pagou e nunca finalizaria.
 */
class LancamentoPagamentosVendaService
{
    /**
     * Grava um pagamento na venda com a taxa da forma (ACRESCENTAR/DESCONTAR)
     * e o troco, mutando os totais da venda do mesmo jeito que o legado
     * (PagamentosVendaController::store).
     *
     * Com troco, grava de onde ele saiu: o informado ou, sem informar, o
     * padrão da forma (FormaTrocoEnum).
     *
     * @param  array<string, mixed>  $extras  Colunas adicionais do PagamentosVenda (cartão, maquininha, vínculo com o pedido)
     */
    public function gravar(Venda $venda, OpcoesPagamento $opcaoPagamento, float $valorRecebido, float $valorPagoPeloCliente, ?FormaTrocoEnum $formaTroco = null, array $extras = []): PagamentosVenda
    {
        $valorAcrescimo = 0.0;
        $valorDesconto = 0.0;
        $valorTroco = max(0, round($valorPagoPeloCliente - $valorRecebido, 2));

        $tipoTaxa = $opcaoPagamento->opcaopag_tipo_taxa;
        if ($tipoTaxa === 'ACRESCENTAR') {
            $valorAcrescimo = round($valorRecebido * (float) $opcaoPagamento->opcaopag_valor_percentual_taxa / 100, 2);
        } elseif ($tipoTaxa === 'DESCONTAR') {
            $valorDesconto = round($valorRecebido * (float) $opcaoPagamento->opcaopag_valor_percentual_taxa / 100, 2);
        }

        $pagamento = PagamentosVenda::create($extras + [
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcaoPagamento->id,
            'pg_venda_valor_pagamento' => $valorRecebido,
            'pg_venda_valor_recebido' => $valorRecebido,
            'pg_venda_valor_pago_pelo_cliente' => $valorPagoPeloCliente,
            'pg_venda_valor_troco' => $valorTroco,
            'pg_venda_forma_troco' => $valorTroco > 0 ? ($formaTroco ?? $opcaoPagamento->opcaopag_troco_padrao ?? FormaTrocoEnum::Dinheiro) : null,
            'pg_venda_valor_acrescimo' => $valorAcrescimo,
            'pg_venda_valor_desconto' => $valorDesconto,
        ]);

        $venda->venda_valor_acrescimo += $valorAcrescimo;
        $venda->venda_valor_desconto += $valorDesconto;
        $venda->venda_valor_pago += $valorRecebido + $valorAcrescimo - $valorDesconto;
        $venda->venda_valor_total += $valorAcrescimo - $valorDesconto;
        $venda->venda_valor_troco += $valorTroco;
        $venda->save();

        return $pagamento;
    }

    /**
     * Formas de pagamento combinadas no pedido (Atender Pedido) viram
     * pagamentos da venda assim que o pedido inteiro entra nela. Lançamento
     * parcial (item a item, "Dividir") não cria nada: os valores combinados
     * são do pedido todo. Forma que permite Stone fica de fora enquanto não
     * foi recebida de verdade — quem decide é o operador (manual ou cobrança
     * na maquininha). Cada pagamento guarda o vínculo
     * (pg_venda_pagamento_pedido_id), então lançar de novo não duplica.
     *
     * Com $somentePagos, só entra o que já foi recebido de verdade no pedido
     * (pg_pedido_pago): venda fiado e venda criada pelo webhook Stone.
     *
     * @param  Collection<int, int>  $pedidoIds
     * @return array{lancados: list<string>, naMaquininha: list<string>} Descrições dos lançamentos e nomes das formas deixadas para a maquininha
     */
    public function lancarDosPedidos(Venda $venda, Collection $pedidoIds, bool $somentePagos = false): array
    {
        $lancados = [];
        $naMaquininha = [];

        if ($pedidoIds->isEmpty()) {
            return ['lancados' => $lancados, 'naMaquininha' => $naMaquininha];
        }

        $pedidos = Pedido::whereIn('id', $pedidoIds)
            ->whereHas('pagamentosCombinados')
            ->whereDoesntHave('item_pedido_pedido_id', fn ($query) => $query
                ->where('item_pedido_status', 'INSERIDO')
                ->where(fn ($q) => $q->whereNull('item_pedido_venda_id')->orWhere('item_pedido_venda_id', '!=', $venda->id)))
            ->with('pagamentosCombinados.opcaoPagamento')
            ->orderBy('id')
            ->get();

        foreach ($pedidos as $pedido) {
            foreach ($pedido->pagamentosCombinados->sortBy('pg_pedido_ordem') as $combinado) {
                $opcao = $combinado->opcaoPagamento;
                $jaLancado = PagamentosVenda::where('pg_venda_venda_id', $venda->id)
                    ->where('pg_venda_pagamento_pedido_id', $combinado->id)
                    ->exists();

                if (! $opcao || (float) $combinado->pg_pedido_valor <= 0 || $jaLancado) {
                    continue;
                }

                if ($somentePagos && ! $combinado->pg_pedido_pago) {
                    continue;
                }

                // A carteira de influenciador nunca entra por pedido: exige o código no PDV.
                if ($opcao->ehCarteiraInfluenciador()) {
                    continue;
                }

                // Forma que permite Stone e ainda não recebida: quem decide é o
                // operador no modal (manual ou Stone), então nada é lançado sozinho.
                if ($opcao->permiteCobrarStone() && ! $combinado->pg_pedido_pago) {
                    $naMaquininha[] = $opcao->opcaopag_nome;

                    continue;
                }

                $valor = min((float) $combinado->pg_pedido_valor, round((float) $venda->venda_valor_total - (float) $venda->venda_valor_pago, 2));
                if ($valor <= 0) {
                    continue;
                }

                $this->gravar($venda, $opcao, $valor, max($valor, (float) $combinado->pg_pedido_valor_troco_para), extras: [
                    'pg_venda_pagamento_pedido_id' => $combinado->id,
                    'pg_venda_maquininha_id' => $opcao->tipoMaquininha() ? Maquininha::padrao()?->id : null,
                ]);

                $lancados[] = $opcao->opcaopag_nome.' R$ '.number_format($valor, 2, ',', '.').($combinado->pg_pedido_pago ? ' (pago no pedido)' : '');
            }
        }

        return ['lancados' => $lancados, 'naMaquininha' => $naMaquininha];
    }
}
