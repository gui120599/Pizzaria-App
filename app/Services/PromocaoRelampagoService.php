<?php

namespace App\Services;

use App\Exceptions\PromocaoIndisponivelException;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\PromocaoConsumo;
use App\Models\PromocaoRelampago;
use App\Models\PromocaoRelampagoProduto;
use Illuminate\Support\Facades\DB;

/**
 * Controla o saldo das promoções relâmpago.
 *
 * O contador é materializado (promocao_qtd_vendida) porque derivá-lo de um
 * SUM em itens_pedidos a cada checkout abriria uma corrida: dois clientes
 * passariam na verificação da última pizza. O débito é um UPDATE condicional
 * — o próprio banco recusa a operação que estouraria o teto —, e o ledger
 * promocao_consumos mantém a rastreabilidade e torna o estorno idempotente.
 */
class PromocaoRelampagoService
{
    /**
     * Debita o saldo (pool e sublimite do produto) e registra o consumo.
     * Idempotente: chamar duas vezes para o mesmo item não debita duas vezes.
     *
     * @throws PromocaoIndisponivelException quando o saldo acabou.
     */
    public function consumir(ItensPedido $item): void
    {
        $promocaoId = $item->item_pedido_promocao_id;

        if (! $promocaoId) {
            return;
        }

        $quantidade = round((float) $item->item_pedido_quantidade, 2);

        if ($quantidade <= 0) {
            return;
        }

        if (PromocaoConsumo::where('consumo_item_pedido_id', $item->id)->exists()) {
            return;
        }

        // A janela de vigência (inclusive recorrente: dia da semana + hora do
        // dia) não dá pra expressar num WHERE portável dentro do UPDATE atômico
        // de debitarPool() — então é checada aqui, em PHP, logo antes da
        // transação. debitarPool() continua garantindo o teto (pizza 41) de
        // forma atômica; só a checagem de janela deixa de ser atômica, o que é
        // aceitável (a janela não fecha no meio de uma transação de alguns ms).
        $promocao = PromocaoRelampago::find($promocaoId);

        if (! $promocao || ! $promocao->vigente()) {
            throw new PromocaoIndisponivelException(
                'A promoção acabou ou foi encerrada enquanto você finalizava o pedido.'
            );
        }

        $prp = PromocaoRelampagoProduto::where('prp_promocao_id', $promocaoId)
            ->where('prp_produto_id', $item->item_pedido_produto_id)
            ->first();

        if (! $prp) {
            throw new PromocaoIndisponivelException('Este produto não faz parte da promoção informada.');
        }

        DB::transaction(function () use ($item, $prp, $promocaoId, $quantidade) {
            $this->debitarPool($promocaoId, $quantidade);
            $this->debitarProduto($prp, $quantidade);

            PromocaoConsumo::create([
                'consumo_promocao_id' => $promocaoId,
                'consumo_promocao_produto_id' => $prp->id,
                'consumo_item_pedido_id' => $item->id,
                'consumo_pedido_id' => $item->item_pedido_pedido_id,
                'consumo_quantidade' => $quantidade,
            ]);
        });
    }

    /** Devolve ao saldo o consumo de um item. Não faz nada se já foi revertido. */
    public function estornarItem(ItensPedido $item): void
    {
        $consumo = PromocaoConsumo::where('consumo_item_pedido_id', $item->id)
            ->whereNull('consumo_revertido_em')
            ->first();

        if (! $consumo) {
            return;
        }

        $this->estornarConsumo($consumo);
    }

    /** Devolve ao saldo todos os consumos ainda ativos de um pedido. */
    public function estornarPedido(Pedido $pedido): void
    {
        PromocaoConsumo::where('consumo_pedido_id', $pedido->id)
            ->whereNull('consumo_revertido_em')
            ->get()
            ->each(fn (PromocaoConsumo $consumo) => $this->estornarConsumo($consumo));
    }

    /**
     * Quantidade da promoção já reservada por um pedido em montagem. Usado para
     * aplicar promocao_limite_por_pedido antes de gravar os itens.
     */
    public function quantidadeNoPedido(PromocaoRelampago $promocao, int $pedidoId): float
    {
        return (float) PromocaoConsumo::where('consumo_promocao_id', $promocao->id)
            ->where('consumo_pedido_id', $pedidoId)
            ->whereNull('consumo_revertido_em')
            ->sum('consumo_quantidade');
    }

    /**
     * @throws PromocaoIndisponivelException
     */
    public function validarLimitePorPedido(PromocaoRelampago $promocao, float $quantidade): void
    {
        $limite = $promocao->promocao_limite_por_pedido;

        if ($limite !== null && $quantidade > $limite) {
            throw new PromocaoIndisponivelException(
                "A promoção \"{$promocao->promocao_nome}\" permite no máximo {$limite} unidade(s) por pedido."
            );
        }
    }

    private function estornarConsumo(PromocaoConsumo $consumo): void
    {
        DB::transaction(function () use ($consumo) {
            // Marca primeiro, com guarda: se outra transação já reverteu este
            // consumo, nenhuma linha é afetada e o saldo não sobe duas vezes.
            $marcadas = DB::table('promocao_consumos')
                ->where('id', $consumo->id)
                ->whereNull('consumo_revertido_em')
                ->update([
                    'consumo_revertido_em' => now(),
                    'updated_at' => now(),
                ]);

            if ($marcadas === 0) {
                return;
            }

            $quantidade = (float) $consumo->consumo_quantidade;

            DB::update(
                'UPDATE promocoes_relampago
                    SET promocao_qtd_vendida = GREATEST(promocao_qtd_vendida - ?, 0), updated_at = ?
                  WHERE id = ?',
                [$quantidade, now(), $consumo->consumo_promocao_id]
            );

            DB::update(
                'UPDATE promocao_relampago_produtos
                    SET prp_qtd_vendida = GREATEST(prp_qtd_vendida - ?, 0), updated_at = ?
                  WHERE id = ?',
                [$quantidade, now(), $consumo->consumo_promocao_produto_id]
            );
        });
    }

    /**
     * Débito atômico do pool. O WHERE carrega a regra: o banco recusa o UPDATE
     * que estouraria promocao_qtd_total, e a linha fica travada durante a
     * operação — é isso que impede vender a pizza 41. A janela de vigência já
     * foi checada em consumir() (ver comentário lá); aqui só sobra ativa+saldo.
     *
     * @throws PromocaoIndisponivelException
     */
    private function debitarPool(int $promocaoId, float $quantidade): void
    {
        $afetadas = DB::update(
            'UPDATE promocoes_relampago
                SET promocao_qtd_vendida = promocao_qtd_vendida + ?, updated_at = ?
              WHERE id = ?
                AND promocao_ativa = 1
                AND (promocao_qtd_total IS NULL OR promocao_qtd_vendida + ? <= promocao_qtd_total)',
            [$quantidade, now(), $promocaoId, $quantidade]
        );

        if ($afetadas === 0) {
            throw new PromocaoIndisponivelException(
                'A promoção acabou ou foi encerrada enquanto você finalizava o pedido.'
            );
        }
    }

    /**
     * @throws PromocaoIndisponivelException
     */
    private function debitarProduto(PromocaoRelampagoProduto $prp, float $quantidade): void
    {
        $afetadas = DB::update(
            'UPDATE promocao_relampago_produtos
                SET prp_qtd_vendida = prp_qtd_vendida + ?, updated_at = ?
              WHERE id = ?
                AND (prp_qtd_total IS NULL OR prp_qtd_vendida + ? <= prp_qtd_total)',
            [$quantidade, now(), $prp->id, $quantidade]
        );

        if ($afetadas === 0) {
            throw new PromocaoIndisponivelException(
                'Este sabor esgotou na promoção enquanto você finalizava o pedido.'
            );
        }
    }
}
