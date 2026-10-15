<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Adiantamento;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/** Adiantamentos nascem e se cancelam só pelo AdiantamentoService (sem form de edição). */
class AdiantamentoPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:adiantamento');
    }

    public function view(User $user, Adiantamento $adiantamento): bool
    {
        return $user->can('view:adiantamento') || $adiantamento->colaborador->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('create:adiantamento');
    }

    public function update(User $user, Adiantamento $adiantamento): bool
    {
        return false;
    }

    public function delete(User $user, Adiantamento $adiantamento): bool
    {
        return false;
    }
}
