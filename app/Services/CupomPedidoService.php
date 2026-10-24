<?php

namespace App\Services;

use App\Enums\TipoDescontoEnum;
use App\Exceptions\CupomInvalidoException;
use App\Models\Cupom;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Support\RateioCentavos;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leva o desconto do cupom para dentro dos itens do pedido.
 *
 * O desconto é rateado em centavos entre as linhas (`item_pedido_desconto_cupom`)
 * e `item_pedido_valor` já o desconta, então venda, NFC-e, caixa e Stone herdam o
 * valor certo sem código extra. `pedido_valor_desconto_cupom` é só informativo
 * (soma das partes); nenhum cálculo subtrai esse campo de novo.
 *
 * Linhas já vendidas (`item_pedido_venda_id`) ficam congeladas: a redistribuição
 * só mexe nas livres, e com venda o cupom não pode ser trocado nem removido.
 */
class CupomPedidoService
{
    /**
     * @throws CupomInvalidoException
     */
    public function aplicar(Pedido $pedido, Cupom $cupom): void
    {
        DB::transaction(function () use ($pedido, $cupom): void {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            $this->exigirSemVenda($pedido);

            if ($pedido->pedido_cupom_id !== $cupom->id) {
                $this->limparPartes($pedido);
                Pedido::whereKey($pedido->id)->update(['pedido_cupom_id' => $cupom->id]);
                $pedido->pedido_cupom_id = $cupom->id;
            }

            $this->reaplicar($pedido);

            app(ComissaoService::class)->sincronizarComCupom($pedido);
        });
    }

    /**
     * @throws CupomInvalidoException
     */
    public function remover(Pedido $pedido): void
    {
        DB::transaction(function () use ($pedido): void {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            if (! $pedido->pedido_cupom_id) {
                return;
            }

            $this->exigirSemVenda($pedido);
            $this->limparPartes($pedido);

            Pedido::whereKey($pedido->id)->update([
                'pedido_cupom_id' => null,
                'pedido_valor_desconto_cupom' => 0,
            ]);

            app(ComissaoService::class)->sincronizarComCupom($pedido);
        });
    }

    /**
     * Recalcula o desconto sobre a soma líquida dos itens INSERIDO (fora cortesia)
     * e redistribui entre as linhas livres. Idempotente; sem cupom, não faz nada.
     * Chamar com o pedido travado, dentro da transação que altera os itens.
     */
    public function reaplicar(Pedido $pedido): void
    {
        if (! $pedido->pedido_cupom_id) {
            return;
        }

        $cupom = Cupom::withTrashed()->find($pedido->pedido_cupom_id);

        if (! $cupom) {
            return;
        }

        /** @var Collection<int, ItensPedido> $itens */
        $itens = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->lockForUpdate()
            ->get();

        $elegiveis = $itens->reject(fn (ItensPedido $item): bool => (bool) $item->item_pedido_cortesia);
        $bases = $elegiveis->mapWithKeys(fn (ItensPedido $item): array => [$item->id => $this->baseEmCentavos($item)]);

        $descontoTotal = $this->descontoEmCentavos($cupom, (int) $bases->sum());

        $congelados = $elegiveis->filter(fn (ItensPedido $item): bool => $item->item_pedido_venda_id !== null);
        $livres = $elegiveis->filter(fn (ItensPedido $item): bool => $item->item_pedido_venda_id === null);

        $parteCongelada = (int) $congelados->sum(fn (ItensPedido $item): int => $this->emCentavos($item->item_pedido_desconto_cupom));
        $baseLivre = (int) $livres->sum(fn (ItensPedido $item): int => $bases[$item->id]);
        $descontoLivre = max(0, min($descontoTotal - $parteCongelada, $baseLivre));

        $partes = RateioCentavos::ratearProporcional(
            $descontoLivre,
            $livres->mapWithKeys(fn (ItensPedido $item): array => [$item->id => $bases[$item->id]])->all(),
        );

        foreach ($livres as $item) {
            $this->gravarParte($item, $bases[$item->id], $partes[$item->id] ?? 0);
        }

        // Cortesia nunca carrega parte de cupom.
        foreach ($itens->filter(fn (ItensPedido $item): bool => (bool) $item->item_pedido_cortesia && (float) $item->item_pedido_desconto_cupom > 0) as $item) {
            $item->update(['item_pedido_desconto_cupom' => 0]);
        }

        $this->atualizarInformativo($pedido);
    }

    /** Valor da linha antes do cupom, em centavos. */
    private function baseEmCentavos(ItensPedido $item): int
    {
        return $this->emCentavos($item->item_pedido_valor) + $this->emCentavos($item->item_pedido_desconto_cupom);
    }

    private function emCentavos(mixed $valor): int
    {
        return (int) round((float) $valor * 100);
    }

    private function descontoEmCentavos(Cupom $cupom, int $somaCentavos): int
    {
        $desconto = $cupom->cupom_tipo_desconto === TipoDescontoEnum::PERCENTUAL
            ? (int) round($somaCentavos * (float) $cupom->cupom_valor_desconto / 100)
            : $this->emCentavos($cupom->cupom_valor_desconto);

        return max(0, min($desconto, $somaCentavos));
    }

    private function gravarParte(ItensPedido $item, int $baseCentavos, int $parteCentavos): void
    {
        $parte = $parteCentavos / 100;
        $valor = ($baseCentavos - $parteCentavos) / 100;

        if (abs((float) $item->item_pedido_desconto_cupom - $parte) < 0.001 && abs((float) $item->item_pedido_valor - $valor) < 0.001) {
            return;
        }

        $item->update([
            'item_pedido_desconto_cupom' => $parte,
            'item_pedido_valor' => $valor,
        ]);
    }

    private function atualizarInformativo(Pedido $pedido): void
    {
        $soma = round((float) ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->sum('item_pedido_desconto_cupom'), 2);

        Pedido::whereKey($pedido->id)->update(['pedido_valor_desconto_cupom' => $soma]);
        $pedido->pedido_valor_desconto_cupom = $soma;
    }

    /** Devolve o valor cheio às linhas livres (troca ou remoção de cupom). */
    private function limparPartes(Pedido $pedido): void
    {
        ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->whereNull('item_pedido_venda_id')
            ->where('item_pedido_desconto_cupom', '>', 0)
            ->lockForUpdate()
            ->get()
            ->each(fn (ItensPedido $item) => $item->update([
                'item_pedido_valor' => round((float) $item->item_pedido_valor + (float) $item->item_pedido_desconto_cupom, 2),
                'item_pedido_desconto_cupom' => 0,
            ]));
    }

    /**
     * @throws CupomInvalidoException
     */
    private function exigirSemVenda(Pedido $pedido): void
    {
        $temVenda = $pedido->pedido_venda_id !== null
            || ItensPedido::where('item_pedido_pedido_id', $pedido->id)->whereNotNull('item_pedido_venda_id')->exists();

        if ($temVenda) {
            throw new CupomInvalidoException('O pedido já tem venda: o cupom não pode ser alterado.');
        }
    }
}
