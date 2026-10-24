<?php

namespace App\Console\Commands;

use App\Exceptions\NfeIoException;
use App\Models\Empresa;
use App\Models\Venda;
use App\Services\NfeIoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
        {--dias= : Só vendas finalizadas nos últimos N dias}
        {--minutos=0 : Só vendas finalizadas há mais de N minutos (não compete com uma emissão em curso)}
        {--limite=0 : Máximo de vendas a consultar (0 = todas)}';

    protected $description = 'Atualiza o status das NFC-e pendentes consultando a NFe.io';

    public function handle(NfeIoService $nfe): int
    {
        $simular = (bool) $this->option('simular');

        if (! Empresa::first()?->nfeIoConfigurado()) {
            return self::SUCCESS;
        }

        $vendas = Venda::query()
            ->whereNotNull('venda_id_nfe')
            ->where('venda_id_nfe', '!=', '')
            ->where(fn ($query) => $query->whereNull('venda_status_nfe')->orWhereNotIn('venda_status_nfe', ['Issued', 'Cancelled']))
            ->when($this->option('desde'), fn ($query, $data) => $query->whereDate('venda_datahora_finalizada', '>=', $data))
            ->when($this->option('ate'), fn ($query, $data) => $query->whereDate('venda_datahora_finalizada', '<=', $data))
            ->when($this->option('dias'), fn ($query, $dias) => $query->where('venda_datahora_finalizada', '>=', now()->subDays((int) $dias)))
            ->when((int) $this->option('minutos') > 0, fn ($query) => $query->where('venda_datahora_finalizada', '<=', now()->subMinutes((int) $this->option('minutos'))))
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

        // Rodando agendado, o log só ganha linha quando houve o que contar.
        if (! $simular && $mudancas > 0) {
            Log::info('nfe:sincronizar-status atualizou o status de NFC-e paradas', ['atualizadas' => $mudancas, 'vendas' => array_column($linhas, 0)]);
        }

        if ($falhas > 0) {
            Log::warning('nfe:sincronizar-status teve falhas ao consultar a NFe.io', ['falhas' => $falhas]);
        }

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }
}
