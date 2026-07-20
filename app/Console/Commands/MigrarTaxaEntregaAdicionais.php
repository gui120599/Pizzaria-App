<?php

namespace App\Console\Commands;

use App\Models\AdicionaisItemPedido;
use App\Models\ItensPedido;
use App\Models\Pedido;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrarTaxaEntregaAdicionais extends Command
{
    protected $signature = 'app:migrar-taxa-entrega
                            {--dry-run : Exibe o que seria alterado sem gravar nada}
                            {--ids=7,11 : IDs dos adicionais de taxa de entrega separados por vírgula}';

    protected $description = 'Migra adicionais de taxa de entrega dos itens para o campo pedido_valor_frete';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $adicionalIds = array_map('intval', explode(',', $this->option('ids')));

        $this->info('');
        $this->info('══════════════════════════════════════════════════');
        $this->info(' Migração: Taxa de Entrega → pedido_valor_frete');
        $this->info('══════════════════════════════════════════════════');
        $this->info(' IDs dos adicionais: '.implode(', ', $adicionalIds));
        if ($isDryRun) {
            $this->warn(' Modo DRY-RUN — nenhuma alteração será gravada.');
        }
        $this->info('');

        $aips = AdicionaisItemPedido::whereIn('aip_adicional_id', $adicionalIds)
            ->with('itemPedido')
            ->get();

        if ($aips->isEmpty()) {
            $this->info('Nenhum adicional de taxa de entrega encontrado. Nada a migrar.');

            return self::SUCCESS;
        }

        $porPedido = $aips
            ->groupBy(fn ($a) => $a->itemPedido?->item_pedido_pedido_id)
            ->filter(fn ($g, $key) => $key !== null);

        $totalPedidos = $porPedido->count();
        $totalRegistros = $aips->count();
        $totalValor = $aips->sum('aip_valor_total');

        $this->line(" Adicionais encontrados : <fg=yellow>{$totalRegistros}</>");
        $this->line(" Pedidos afetados       : <fg=yellow>{$totalPedidos}</>");
        $this->line(' Valor total a migrar   : <fg=yellow>R$ '.number_format($totalValor, 2, ',', '.').'</>');
        $this->info('');

        if ($isDryRun) {
            $this->info('Prévia (primeiros 10 pedidos):');
            $this->table(
                ['Pedido', 'Adicionais', 'Frete (R$)', 'Total atual (R$)'],
                $porPedido->take(10)->map(function ($grupo, $pedidoId) {
                    $pedido = Pedido::find($pedidoId);

                    return [
                        $pedidoId,
                        $grupo->count(),
                        number_format($grupo->sum('aip_valor_total'), 2, ',', '.'),
                        number_format($pedido?->pedido_valor_total ?? 0, 2, ',', '.'),
                    ];
                })->values()->toArray()
            );
            $this->warn('Execute sem --dry-run para aplicar a migração.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Confirma a migração de {$totalPedidos} pedidos?", true)) {
            $this->warn('Operação cancelada.');

            return self::SUCCESS;
        }

        $this->info('Processando...');
        $bar = $this->output->createProgressBar($totalPedidos);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $bar->start();

        $erros = 0;
        $processados = 0;

        foreach ($porPedido as $pedidoId => $grupo) {
            try {
                DB::transaction(function () use ($pedidoId, $grupo) {

                    $pedido = Pedido::lockForUpdate()->find($pedidoId);
                    if (! $pedido) {
                        return;
                    }

                    // Processa cada item afetado individualmente
                    $porItem = $grupo->groupBy('aip_item_pedido_id');

                    foreach ($porItem as $itemId => $adicionaisDoItem) {
                        $item = ItensPedido::lockForUpdate()->find($itemId);
                        if (! $item) {
                            continue;
                        }

                        $valorRemover = round($adicionaisDoItem->sum('aip_valor_total'), 2);

                        $item->update([
                            'item_pedido_valor_adicionais' => max(0, round(
                                (float) $item->item_pedido_valor_adicionais - $valorRemover, 2
                            )),
                            'item_pedido_valor' => max(0, round(
                                (float) $item->item_pedido_valor - $valorRemover, 2
                            )),
                        ]);

                        AdicionaisItemPedido::whereIn('id', $adicionaisDoItem->pluck('id'))->delete();
                    }

                    // Recalcula valor_itens a partir dos itens atualizados
                    $novoValorItens = round(
                        ItensPedido::where('item_pedido_pedido_id', $pedidoId)
                            ->where('item_pedido_status', 'INSERIDO')
                            ->sum('item_pedido_valor'),
                        2
                    );

                    $freteMigrado = round($grupo->sum('aip_valor_total'), 2);

                    // Total permanece igual: o frete saiu dos itens e entrou em pedido_valor_frete
                    $pedido->update([
                        'pedido_valor_itens' => $novoValorItens,
                        'pedido_valor_frete' => round((float) $pedido->pedido_valor_frete + $freteMigrado, 2),
                        'pedido_valor_total' => round(
                            max(0, $novoValorItens - (float) $pedido->pedido_valor_desconto + $freteMigrado),
                            2
                        ),
                    ]);
                });

                $processados++;

            } catch (\Throwable $e) {
                $erros++;
                $this->newLine();
                $this->error("Erro no pedido #{$pedidoId}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('══════════════════════════════════════════════════');
        $this->info(" Processados : <fg=green>{$processados}</>");
        if ($erros > 0) {
            $this->error(" Com erro    : {$erros} pedido(s)");
        }
        $this->info(' Status      : <fg=green>Migração concluída</>');
        $this->info('══════════════════════════════════════════════════');
        $this->info('');

        return $erros > 0 ? self::FAILURE : self::SUCCESS;
    }
}
