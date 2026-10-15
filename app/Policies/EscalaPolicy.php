<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Escala;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EscalaPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:escala');
    }

    public function view(User $user, Escala $registro): bool
    {
        return $user->can('view:escala');
    }

    public function create(User $user): bool
    {
        return $user->can('create:escala');
    }

    public function update(User $user, Escala $registro): bool
    {
        return $user->can('update:escala');
    }

    public function delete(User $user, Escala $registro): bool
    {
        return $user->can('delete:escala');
    }

    public function restore(User $user, Escala $registro): bool
    {
        return $user->can('restore:escala');
    }
}
