<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\VendaAlteracao;
use App\Notifications\VendaEmAlteracaoNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa o gerente (uma vez por alteração) quando uma venda reaberta para
 * alteração passa de N horas sem ser finalizada nem descartada: até lá o
 * caixa da sessão fica sem a entrada dela.
 */
class VendasAlertarAlteracoesAbertas extends Command
{
    protected $signature = 'vendas:alertar-alteracoes-abertas {--horas= : Prazo em horas (padrão: config vendas.alerta_alteracao_horas)}';

    protected $description = 'Notifica os gestores sobre vendas reabertas para alteração há muito tempo';

    public function handle(): int
    {
        $horas = (int) ($this->option('horas') ?? config('vendas.alerta_alteracao_horas', 2));

        $alteracoes = VendaAlteracao::query()
            ->with('user')
            ->where('status', VendaAlteracao::ABERTA)
            ->whereNull('alertada_em')
            ->where('created_at', '<=', now()->subHours($horas))
            ->get();

        if ($alteracoes->isEmpty()) {
            return self::SUCCESS;
        }

        $gestores = User::query()
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'Admin'))
                ->orWhereHas('permissions', fn ($permissoes) => $permissoes->where('name', 'alterar_finalizada:venda'))
                ->orWhereHas('roles.permissions', fn ($permissoes) => $permissoes->where('name', 'alterar_finalizada:venda')))
            ->get();

        foreach ($alteracoes as $alteracao) {
            Notification::send($gestores, new VendaEmAlteracaoNotification($alteracao));
            $alteracao->forceFill(['alertada_em' => now()])->save();
        }

        $this->info("{$alteracoes->count()} venda(s) em alteração notificada(s).");

        return self::SUCCESS;
    }
}
