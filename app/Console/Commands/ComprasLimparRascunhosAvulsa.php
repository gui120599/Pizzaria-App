<?php

namespace App\Console\Commands;

use App\Enums\Compras\StatusRascunhoCompra;
use App\Models\CompraRascunho;
use Illuminate\Console\Command;

/**
 * Rascunhos de compra avulsa (Fase 5): marca como expirados os abertos que
 * passaram do prazo e apaga os que não viraram compra há mais de 30 dias.
 * Rascunho confirmado é mantido (guarda a entrada original da compra).
 */
class ComprasLimparRascunhosAvulsa extends Command
{
    protected $signature = 'compras:limpar-rascunhos-avulsa';

    protected $description = 'Expira e limpa rascunhos de compra avulsa não confirmados';

    public function handle(): int
    {
        $expirados = CompraRascunho::query()
            ->where('status', StatusRascunhoCompra::Aberto)
            ->where('expira_em', '<', now())
            ->update(['status' => StatusRascunhoCompra::Expirado]);

        $apagados = CompraRascunho::query()
            ->whereIn('status', [StatusRascunhoCompra::Expirado, StatusRascunhoCompra::Descartado])
            ->where('updated_at', '<', now()->subDays(30))
            ->delete();

        $this->info("Rascunhos expirados: {$expirados}; apagados: {$apagados}.");

        return self::SUCCESS;
    }
}
