<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ponto\StatusRepasse;
use App\Models\Repasse;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Repasse (spec §5.6): o Admin vê todos; o Gerente não vê; o colaborador vê
 * só o próprio, depois de aprovado. Aprovar/cancelar passam pelo
 * RepasseService, que confere `aprovar:repasse`.
 */
class RepassePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:repasse');
    }

    public function view(User $user, Repasse $repasse): bool
    {
        if ($user->can('view:repasse')) {
            return true;
        }

        return $repasse->colaborador->user_id === $user->id
            && in_array($repasse->status, [StatusRepasse::Aprovado, StatusRepasse::Pago], true);
    }

    public function create(User $user): bool
    {
        return false;
    }

    /** Só itens manuais do rascunho. */
    public function update(User $user, Repasse $repasse): bool
    {
        return $user->can('update:repasse') && $repasse->podeEditar();
    }

    public function delete(User $user, Repasse $repasse): bool
    {
        return false;
    }

    public function aprovar(User $user, Repasse $repasse): bool
    {
        return $user->can('aprovar:repasse');
    }

    /** "Li e confere" do próprio colaborador. */
    public function darCiencia(User $user, Repasse $repasse): bool
    {
        return $repasse->colaborador->user_id === $user->id
            && $repasse->ciente_em === null
            && in_array($repasse->status, [StatusRepasse::Aprovado, StatusRepasse::Pago], true);
    }
}
