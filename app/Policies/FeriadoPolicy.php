<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Feriado;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FeriadoPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:feriado');
    }

    public function view(User $user, Feriado $registro): bool
    {
        return $user->can('view:feriado');
    }

    public function create(User $user): bool
    {
        return $user->can('create:feriado');
    }

    public function update(User $user, Feriado $registro): bool
    {
        return $user->can('update:feriado');
    }

    public function delete(User $user, Feriado $registro): bool
    {
        return $user->can('delete:feriado');
    }

    public function restore(User $user, Feriado $registro): bool
    {
        return $user->can('restore:feriado');
    }
}
