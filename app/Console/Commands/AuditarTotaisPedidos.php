<?php

namespace App\Console\Commands;

use App\Models\ItensPedido;
use App\Models\Pedido;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuditarTotaisPedidos extends Command
{
    protected $signature = 'pedidos:auditar-totais
        {--desde= : Data inicial (Y-m-d) da abertura do pedido}
        {--ids=* : Limita aos ids informados}
        {--corrigir : Grava os valores calculados (exige --ids)}';

    protected $description = 'Lista pedidos (exceto INICIADO) cujo valor bruto gravado difere da soma dos itens; --corrigir --ids= acerta os informados';

    public function handle(): int
    {
        $ids = array_filter(array_map('intval', (array) $this->option('ids')));

        if ($this->option('corrigir') && $ids === []) {
            $this->error('--corrigir exige --ids com os pedidos a corrigir.');

            return self::FAILURE;
        }

        $pedidos = Pedido::query()
            ->where('pedido_status', '!=', 'INICIADO')
            ->when($this->option('desde'), fn ($q, $desde) => $q->whereDate('pedido_datahora_abertura', '>=', $desde))
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        $divergentes = [];

        foreach ($pedidos as $pedido) {
            $itens = $this->itensInseridos($pedido);
            $bruto = round((float) $itens->sum('item_pedido_valor') + (float) $itens->sum('item_pedido_desconto') + (float) $itens->sum('item_pedido_desconto_cupom'), 2);
            $gravado = round((float) $pedido->pedido_valor_itens, 2);

            if (abs($bruto - $gravado) > 0.009) {
                $divergentes[] = [$pedido, $gravado, $bruto];
            }
        }

        $this->table(
            ['Pedido', 'Origem', 'Status', 'Gravado', 'Calculado', 'Diferença'],
            array_map(fn (array $d) => [
                $d[0]->id,
                $d[0]->pedido_origem?->value ?? $d[0]->pedido_origem,
                $d[0]->pedido_status,
                number_format($d[1], 2, ',', '.'),
                number_format($d[2], 2, ',', '.'),
                number_format($d[2] - $d[1], 2, ',', '.'),
            ], $divergentes),
        );

        $this->info(count($divergentes).' pedido(s) divergente(s) de '.$pedidos->count().' auditado(s).');

        if (! $this->option('corrigir')) {
            return self::SUCCESS;
        }

        foreach ($divergentes as [$pedido]) {
            $this->corrigir($pedido);
            $this->line("Pedido #{$pedido->id} corrigido.");
        }

        return self::SUCCESS;
    }

    /** Preserva o frete gravado; recalcula itens, desconto (itens e cupom) e total. */
    private function corrigir(Pedido $pedido): void
    {
        DB::transaction(function () use ($pedido) {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $itens = $this->itensInseridos($pedido);

            $liquido = round((float) $itens->sum('item_pedido_valor'), 2);
            $descontoItens = round((float) $itens->sum('item_pedido_desconto'), 2);
            $cupom = round((float) $itens->sum('item_pedido_desconto_cupom'), 2);
            $frete = (float) $pedido->pedido_valor_frete;

            $antes = $pedido->only(['pedido_valor_itens', 'pedido_valor_desconto', 'pedido_valor_frete', 'pedido_valor_total']);

            $pedido->update([
                'pedido_valor_itens' => round($liquido + $descontoItens + $cupom, 2),
                'pedido_valor_desconto' => round($descontoItens + $cupom, 2),
                'pedido_valor_total' => round($liquido + $frete, 2),
            ]);

            Log::info('pedidos:auditar-totais corrigiu totais', [
                'pedido_id' => $pedido->id,
                'antes' => $antes,
                'depois' => $pedido->only(array_keys($antes)),
            ]);
        });
    }

    private function itensInseridos(Pedido $pedido)
    {
        return ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->get();
    }
}
