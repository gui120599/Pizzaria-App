<?php

namespace App\Console\Commands;

use App\Enums\CompraStatusEnum;
use App\Models\Compra;
use App\Services\Compras\ConferenciaCompraService;
use Illuminate\Console\Command;

/**
 * Dá entrada no estoque das compras automatizadas cuja data de entrada
 * (emissão + N dias do fornecedor) chegou. Ver ConferenciaCompraService.
 */
class ComprasConferirAutomaticas extends Command
{
    protected $signature = 'compras:conferir-automaticas';

    protected $description = 'Confirma as compras importadas automaticamente cuja data de entrada no estoque chegou';

    public function handle(ConferenciaCompraService $conferencia): int
    {
        $conferidas = 0;

        Compra::query()
            ->where('compra_importacao_automatica', true)
            ->where('compra_status', CompraStatusEnum::RASCUNHO)
            ->whereHas('prestador.condicaoCompra', fn ($query) => $query->where('fcc_entrada_automatica', true))
            ->orderBy('id')
            ->each(function (Compra $compra) use ($conferencia, &$conferidas): void {
                if ($conferencia->tentarConferir($compra)) {
                    $conferidas++;
                }
            });

        $this->info("Compras confirmadas: {$conferidas}");

        return self::SUCCESS;
    }
}
