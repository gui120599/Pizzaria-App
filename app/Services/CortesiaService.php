<?php

namespace App\Services;

use App\Enums\AcaoAutorizadaEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\AutorizacaoNegadaException;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Garcom\AutorizacaoGerenteService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cortesia: o produto sai do estoque (baixa com origem CORTESIA, valorada pelo
 * custo médio), mas não é cobrado e não entra na NFC-e. Não é desconto
 * comercial: o preço de tabela fica no item (item_pedido_valor_unitario) e o
 * "desconto" da linha cobre o valor inteiro, então o valor cobrado é zero.
 *
 * Quem tem a permissão conceder_cortesia:pedido concede sozinho; os demais
 * (garçom, caixa) precisam do PIN de um gerente. A autorização é gravada em
 * autorizacoes_gerente com o motivo.
 *
 * Só item que ainda não foi lançado numa venda muda aqui: item já no carrinho
 * do caixa é retirado, marcado e lançado de novo pelo PDV (OperarVenda).
 */
class CortesiaService
{
    public function __construct(
        private readonly AutorizacaoGerenteService $autorizacoes,
        private readonly TotaisPedidoService $totais,
    ) {}

    /**
     * @throws RuntimeException|AutorizacaoNegadaException
     */
    public function concederItem(ItensPedido $item, User $solicitante, ?int $autorizadorId, ?string $pin, string $motivo): void
    {
        $this->aplicar([$item->getKey()], true, $solicitante, $autorizadorId, $pin, $motivo);
    }

    /**
     * Todos os itens ainda cobrados do pedido viram cortesia.
     *
     * @throws RuntimeException|AutorizacaoNegadaException
     */
    public function concederPedido(Pedido $pedido, User $solicitante, ?int $autorizadorId, ?string $pin, string $motivo): void
    {
        $ids = ItensPedido::where('item_pedido_pedido_id', $pedido->getKey())
            ->where('item_pedido_status', 'INSERIDO')
            ->where('item_pedido_cortesia', false)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            throw new RuntimeException('Este pedido não tem item cobrado para virar cortesia.');
        }

        $this->aplicar($ids, true, $solicitante, $autorizadorId, $pin, $motivo);
    }

    /**
     * @throws RuntimeException|AutorizacaoNegadaException
     */
    public function desfazerItem(ItensPedido $item, User $solicitante, ?int $autorizadorId, ?string $pin, string $motivo = 'Cortesia desfeita'): void
    {
        $this->aplicar([$item->getKey()], false, $solicitante, $autorizadorId, $pin, $motivo);
    }

    /**
     * Item de pedido: cortesia ligada ou desligada, com autorização e total do
     * pedido recalculado na mesma transação.
     *
     * @param  array<int, int>  $itemIds
     */
    private function aplicar(array $itemIds, bool $cortesia, User $solicitante, ?int $autorizadorId, ?string $pin, string $motivo): void
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw new RuntimeException('Informe o motivo da cortesia.');
        }

        DB::transaction(function () use ($itemIds, $cortesia, $solicitante, $autorizadorId, $pin, $motivo): void {
            /** @var Collection<int, ItensPedido> $itens */
            $itens = ItensPedido::whereIn('id', $itemIds)->with('produto')->lockForUpdate()->get();
            $pedidoIds = $itens->pluck('item_pedido_pedido_id')->unique();

            if ($itens->isEmpty() || $pedidoIds->count() !== 1) {
                throw new RuntimeException('Item não encontrado.');
            }

            $pedido = $this->totais->travar((int) $pedidoIds->first());

            if (in_array($pedido->pedido_status, [StatusPedidoEnum::INICIADO->value, StatusPedidoEnum::CANCELADO->value, StatusPedidoEnum::FINALIZADO->value], true)) {
                throw new RuntimeException('Este pedido não aceita mais cortesia (rascunho, cancelado ou finalizado).');
            }

            foreach ($itens as $item) {
                if ($item->item_pedido_status !== 'INSERIDO') {
                    throw new RuntimeException('Este item já foi cancelado.');
                }

                if ($item->item_pedido_venda_id !== null) {
                    throw new RuntimeException('Este item já está numa venda: retire-o do carrinho do caixa antes de mudar a cortesia.');
                }

                if ((bool) $item->item_pedido_cortesia === $cortesia) {
                    throw new RuntimeException($cortesia ? 'Este item já é cortesia.' : 'Este item não é cortesia.');
                }
            }

            $autorizador = $this->autorizacoes->autorizar(
                AcaoAutorizadaEnum::CONCEDER_CORTESIA,
                $solicitante,
                $pedido,
                $autorizadorId,
                $pin,
                $motivo,
                [
                    'acao' => $cortesia ? 'conceder' : 'desfazer',
                    'pedido_id' => $pedido->id,
                    'itens' => $itens->map(fn (ItensPedido $item): array => [
                        'id' => $item->id,
                        'produto' => $item->nomeProduto(),
                        'quantidade' => (float) $item->item_pedido_quantidade,
                        'valor' => round((float) $item->item_pedido_valor + $item->descontoTotal(), 2),
                    ])->all(),
                ],
            );

            foreach ($itens as $item) {
                $item->item_pedido_cortesia = $cortesia;
                $item->item_pedido_cortesia_motivo = $cortesia ? $motivo : null;
                $item->item_pedido_cortesia_user_id = $cortesia ? $autorizador->autorizacao_autorizador_id : null;
                $item->item_pedido_cortesia_em = $cortesia ? now() : null;
                $item->recalcularValores();
                $item->save();
            }

            $this->totais->recalcular($pedido);
        });
    }

    /**
     * Linha de venda do caixa que não veio de pedido (produto lançado à mão):
     * vira cortesia inteira, guardando o valor cobrado em item_venda_valor_tabela
     * para poder desfazer.
     *
     * @throws RuntimeException|AutorizacaoNegadaException
     */
    public function alternarLinhaDaVenda(ItensVenda $linha, User $solicitante, ?int $autorizadorId, ?string $pin, string $motivo): void
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw new RuntimeException('Informe o motivo da cortesia.');
        }

        DB::transaction(function () use ($linha, $solicitante, $autorizadorId, $pin, $motivo): void {
            $linha = ItensVenda::whereKey($linha->getKey())->with('produto')->lockForUpdate()->firstOrFail();
            $virar = ! $linha->item_venda_cortesia;

            $this->autorizacoes->autorizar(
                AcaoAutorizadaEnum::CONCEDER_CORTESIA,
                $solicitante,
                $linha,
                $autorizadorId,
                $pin,
                $motivo,
                [
                    'acao' => $virar ? 'conceder' : 'desfazer',
                    'venda_id' => $linha->item_venda_venda_id,
                    'produto' => $linha->produto?->produto_descricao,
                    'quantidade' => (float) $linha->item_venda_quantidade,
                ],
            );

            if ($virar) {
                $valor = round((float) $linha->item_venda_valor, 2);
                $linha->item_venda_cortesia = true;
                $linha->item_venda_valor_tabela = $valor;
                $linha->item_venda_desconto = round((float) $linha->item_venda_desconto + $valor, 2);
                $this->definirValorDaLinha($linha, 0.0);
            } else {
                $valor = round((float) $linha->item_venda_valor_tabela, 2);
                $linha->item_venda_cortesia = false;
                $linha->item_venda_desconto = max(0, round((float) $linha->item_venda_desconto - $valor, 2));
                $linha->item_venda_valor_tabela = null;
                $this->definirValorDaLinha($linha, $valor);
            }

            $linha->save();
            app(VendaService::class)->atualizarValoresdaVenda($linha->item_venda_venda_id);
        });
    }

    /** Valor cobrado da linha e os tributos que dependem dele (zero na cortesia). */
    private function definirValorDaLinha(ItensVenda $linha, float $valor): void
    {
        $produto = $linha->produto;

        $linha->item_venda_valor = $valor;
        $linha->item_venda_valor_base_calculo = $valor;
        $linha->item_venda_valor_icms = $valor * (float) $produto->produto_valor_percentual_icms / 100;
        $linha->item_venda_valor_pis = $valor * (float) $produto->produto_valor_percentual_pis / 100;
        $linha->item_venda_valor_cofins = $valor * (float) $produto->produto_valor_percentual_cofins / 100;
        $linha->item_venda_valor_total_tributos = $linha->item_venda_valor_icms + $linha->item_venda_valor_pis + $linha->item_venda_valor_cofins;
    }
}
