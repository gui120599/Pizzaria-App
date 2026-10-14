<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DispositivoCozinha;
use App\Models\ProducaoItem;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Ações de produção (spec §5.9). No tablet compartilhado, quem chama passa o
 * usuário do operador (a pessoa) quando houver; sem operador, o da conta da
 * estação. O dispositivo limita aos setores pareados.
 */
class ProducaoItemPolicy
{
    use HandlesAuthorization;

    /** Ver a produção do pedido (linha do tempo no Painel de Pedidos). */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:producao_item');
    }

    /** Iniciar, concluir, devolver, desfazer, ciência. */
    public function produzir(User $user, ProducaoItem $item, ?DispositivoCozinha $dispositivo = null): bool
    {
        if ($dispositivo !== null && ! $dispositivo->atendeSetor($item->setor_producao_id)) {
            return false;
        }

        return $user->can('produzir:producao_item');
    }

    public function despachar(User $user): bool
    {
        return $user->can('despachar:producao_item')
            || ((bool) config('cozinha.cozinha_despacha') && $user->hasRole('Cozinha'));
    }

    public function refazer(User $user): bool
    {
        return $user->can('refazer:producao_item');
    }

    /** Priorizar e "liberar agora" pelo Painel de Pedidos / Expedição. */
    public function priorizar(User $user): bool
    {
        return $user->can('priorizar:producao_item');
    }

    /** Forçar pronto/saiu pelo Painel de Pedidos com itens ainda em produção. */
    public function forcar(User $user): bool
    {
        return $user->can('forcar:producao_item');
    }

    /** Liberar o setor sem o checklist de abertura (bloqueio ligado). */
    public function liberarSemAbertura(User $user): bool
    {
        return $user->can('liberar_sem_abertura:producao_item');
    }

    public function verRelatorio(User $user): bool
    {
        return $user->can('view:relatorio_producao');
    }
}
