<?php

namespace App\Services;

use App\Exceptions\PromocaoIndisponivelException;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\PromocaoAdicionalConsumo;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoAdicionalRegra;
use Illuminate\Support\Facades\DB;

/**
 * Controla o saldo (teto agregado por opção de produto ofertado) da promoção
 * adicional e o limite de aceites por pedido (por regra/gatilho). Mesmo
 * padrão de PromocaoRelampagoService: contador materializado
 * (pao_qtd_vendida) + débito atômico via UPDATE condicional + ledger
 * idempotente (promocao_adicional_consumos).
 */
class PromocaoAdicionalService
{
    /**
     * Debita o saldo da opção de oferta escolhida e registra o consumo.
     * Idempotente: chamar duas vezes para o mesmo item de oferta não debita
     * duas vezes. Espera que o item já tenha
     * item_pedido_promocao_adicional_oferta_id e item_pedido_origem_id
     * preenchidos (ver PrecificadorService/quem cria o item).
     *
     * @throws PromocaoIndisponivelException quando a promoção não está mais
     *                                       vigente, o produto não bate com
     *                                       a oferta, ou o saldo acabou.
     */
    public function consumir(ItensPedido $itemOferta): void
    {
        $ofertaId = $itemOferta->item_pedido_promocao_adicional_oferta_id;

        if (! $ofertaId) {
            return;
        }

        $quantidade = round((float) $itemOferta->item_pedido_quantidade, 2);

        if ($quantidade <= 0) {
            return;
        }

        if (PromocaoAdicionalConsumo::where('pac_item_pedido_oferta_id', $itemOferta->id)->exists()) {
            return;
        }

        $oferta = PromocaoAdicionalOferta::with('regra.promocao')->find($ofertaId);

        if (! $oferta || ! $oferta->regra || ! $oferta->regra->promocao || ! $oferta->regra->promocao->vigente()) {
            throw new PromocaoIndisponivelException(
                'A promoção acabou ou foi encerrada enquanto você finalizava o pedido.'
            );
        }

        if ((int) $itemOferta->item_pedido_produto_id !== (int) $oferta->pao_produto_oferta_id) {
            throw new PromocaoIndisponivelException('Este produto não corresponde à oferta configurada na promoção.');
        }

        $itemGatilhoId = $itemOferta->item_pedido_origem_id;
        $regraId = $oferta->pao_regra_id;

        DB::transaction(function () use ($itemOferta, $oferta, $ofertaId, $regraId, $itemGatilhoId, $quantidade) {
            $this->debitarOferta($ofertaId, $quantidade);

            PromocaoAdicionalConsumo::create([
                'pac_regra_id' => $regraId,
                'pac_oferta_id' => $ofertaId,
                'pac_item_pedido_gatilho_id' => $itemGatilhoId,
                'pac_item_pedido_oferta_id' => $itemOferta->id,
                'pac_pedido_id' => $itemOferta->item_pedido_pedido_id,
                'pac_valor_adicional_cobrado' => $oferta->pao_valor_adicional,
                'pac_quantidade' => $quantidade,
            ]);
        });
    }

    /** Devolve ao saldo o consumo de uma linha de oferta específica. Não faz nada se já foi revertido. */
    public function estornarItemOferta(ItensPedido $itemOferta): void
    {
        $consumo = PromocaoAdicionalConsumo::where('pac_item_pedido_oferta_id', $itemOferta->id)
            ->whereNull('pac_revertido_em')
            ->first();

        if (! $consumo) {
            return;
        }

        $this->estornarConsumo($consumo);
    }

    /**
     * Devolve ao saldo todos os consumos ativos originados por um item-gatilho
     * (ex.: a pizza). Quem chama ainda precisa apagar a(s) linha(s) de oferta
     * correspondentes — este método só estorna o saldo, não deleta itens.
     *
     * @return array<int, int> ids das linhas de item de pedido da oferta cujo
     *                         consumo foi estornado (para o chamador deletar).
     */
    public function estornarItensDoGatilho(ItensPedido $itemGatilho): array
    {
        $consumos = PromocaoAdicionalConsumo::where('pac_item_pedido_gatilho_id', $itemGatilho->id)
            ->whereNull('pac_revertido_em')
            ->get();

        $itensOferta = [];

        foreach ($consumos as $consumo) {
            $itensOferta[] = (int) $consumo->pac_item_pedido_oferta_id;
            $this->estornarConsumo($consumo);
        }

        return $itensOferta;
    }

    /** Devolve ao saldo todos os consumos ainda ativos de um pedido (cancelamento total). */
    public function estornarPedido(Pedido $pedido): void
    {
        PromocaoAdicionalConsumo::where('pac_pedido_id', $pedido->id)
            ->whereNull('pac_revertido_em')
            ->get()
            ->each(fn (PromocaoAdicionalConsumo $consumo) => $this->estornarConsumo($consumo));
    }

    /** Quantas vezes a oferta desta regra já foi aceita (consumos ativos) num pedido em montagem. */
    public function aceitesNoPedido(PromocaoAdicionalRegra $regra, int $pedidoId): int
    {
        return PromocaoAdicionalConsumo::where('pac_regra_id', $regra->id)
            ->where('pac_pedido_id', $pedidoId)
            ->whereNull('pac_revertido_em')
            ->count();
    }

    /**
     * @throws PromocaoIndisponivelException
     */
    public function validarLimitePorPedido(PromocaoAdicionalRegra $regra, int $aceitesComEsteIncluso): void
    {
        $limite = $regra->par_qtd_maxima_por_pedido;

        if ($limite !== null && $aceitesComEsteIncluso > $limite) {
            throw new PromocaoIndisponivelException(
                "Esta oferta pode ser aceita no máximo {$limite} vez(es) por pedido."
            );
        }
    }

    private function estornarConsumo(PromocaoAdicionalConsumo $consumo): void
    {
        DB::transaction(function () use ($consumo) {
            // Marca primeiro, com guarda: se outra transação já reverteu este
            // consumo, nenhuma linha é afetada e o saldo não sobe duas vezes.
            $marcadas = DB::table('promocao_adicional_consumos')
                ->where('id', $consumo->id)
                ->whereNull('pac_revertido_em')
                ->update([
                    'pac_revertido_em' => now(),
                    'updated_at' => now(),
                ]);

            if ($marcadas === 0) {
                return;
            }

            DB::update(
                'UPDATE promocao_adicional_ofertas
                    SET pao_qtd_vendida = GREATEST(pao_qtd_vendida - ?, 0), updated_at = ?
                  WHERE id = ?',
                [(float) $consumo->pac_quantidade, now(), $consumo->pac_oferta_id]
            );
        });
    }

    /**
     * Débito atômico do teto agregado da oferta escolhida. O WHERE carrega a
     * condição: o banco recusa o UPDATE que estouraria pao_qtd_total.
     *
     * @throws PromocaoIndisponivelException
     */
    private function debitarOferta(int $ofertaId, float $quantidade): void
    {
        $afetadas = DB::update(
            'UPDATE promocao_adicional_ofertas
                SET pao_qtd_vendida = pao_qtd_vendida + ?, updated_at = ?
              WHERE id = ?
                AND (pao_qtd_total IS NULL OR pao_qtd_vendida + ? <= pao_qtd_total)',
            [$quantidade, now(), $ofertaId, $quantidade]
        );

        if ($afetadas === 0) {
            throw new PromocaoIndisponivelException(
                'A oferta esgotou enquanto você finalizava o pedido.'
            );
        }
    }
}
