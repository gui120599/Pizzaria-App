<?php

namespace App\Console\Commands;

use App\Exceptions\NfeIoException;
use App\Models\Venda;
use App\Services\NfeIoService;
use Illuminate\Console\Command;

/**
 * Reconcilia com a NFe.io o status das NFC-e que ficaram "paradas" aqui
 * (webhook que não chegou): vendas com venda_id_nfe e status diferente de
 * Issued/Cancelled. Com --simular só consulta e lista, sem gravar.
 */
class NfeSincronizarStatus extends Command
{
    protected $signature = 'nfe:sincronizar-status
        {--simular : Consulta a NFe.io e lista as mudanças, sem gravar}
        {--desde= : Só vendas finalizadas a partir desta data (AAAA-MM-DD)}
        {--ate= : Só vendas finalizadas até esta data (AAAA-MM-DD)}
        {--limite=0 : Máximo de vendas a consultar (0 = todas)}';

    protected $description = 'Atualiza o status das NFC-e pendentes consultando a NFe.io';

    public function handle(NfeIoService $nfe): int
    {
        $simular = (bool) $this->option('simular');

        $vendas = Venda::query()
            ->whereNotNull('venda_id_nfe')
            ->where('venda_id_nfe', '!=', '')
            ->where(fn ($query) => $query->whereNull('venda_status_nfe')->orWhereNotIn('venda_status_nfe', ['Issued', 'Cancelled']))
            ->when($this->option('desde'), fn ($query, $data) => $query->whereDate('venda_datahora_finalizada', '>=', $data))
            ->when($this->option('ate'), fn ($query, $data) => $query->whereDate('venda_datahora_finalizada', '<=', $data))
            ->orderBy('id')
            ->when((int) $this->option('limite') > 0, fn ($query) => $query->limit((int) $this->option('limite')))
            ->get();

        if ($vendas->isEmpty()) {
            $this->info('Nenhuma NFC-e pendente de sincronização.');

            return self::SUCCESS;
        }

        $this->info(($simular ? '[simulação] ' : '').$vendas->count().' venda(s) a consultar.');

        $mudancas = 0;
        $falhas = 0;
        $linhas = [];

        foreach ($vendas as $venda) {
            $antes = $venda->venda_status_nfe ?? '—';

            try {
                $depois = $simular
                    ? ($nfe->consultarStatus($venda->venda_id_nfe)['status'] ?? $antes)
                    : $nfe->sincronizarStatusLocal($venda);
            } catch (NfeIoException $e) {
                $falhas++;
                $this->warn("Venda {$venda->id}: {$e->getMessage()}");

                continue;
            }

            if ($depois !== $antes) {
                $mudancas++;
                $linhas[] = [$venda->id, $antes, $depois];
            }

            usleep(150_000);
        }

        if ($linhas !== []) {
            $this->table(['Venda', 'Antes', 'NFe.io'], $linhas);
        }

        $this->info(($simular ? 'Mudariam: ' : 'Atualizadas: ').$mudancas.' | Falhas: '.$falhas);

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }
}
