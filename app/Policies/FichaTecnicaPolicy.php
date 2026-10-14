<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FichaTecnica;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FichaTecnicaPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:ficha_tecnica');
    }

    public function view(User $user, FichaTecnica $fichaTecnica): bool
    {
        return $user->can('view:ficha_tecnica');
    }

    public function create(User $user): bool
    {
        return $user->can('create:ficha_tecnica');
    }

    public function update(User $user, FichaTecnica $fichaTecnica): bool
    {
        return $user->can('update:ficha_tecnica');
    }

    public function delete(User $user, FichaTecnica $fichaTecnica): bool
    {
        return $user->can('delete:ficha_tecnica');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:ficha_tecnica');
    }

    public function restore(User $user, FichaTecnica $fichaTecnica): bool
    {
        return $user->can('restore:ficha_tecnica');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:ficha_tecnica');
    }

    public function forceDelete(User $user, FichaTecnica $fichaTecnica): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }
}
