<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DispositivoCozinha;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DispositivoCozinhaPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:dispositivo_cozinha');
    }

    public function view(User $user, DispositivoCozinha $dispositivoCozinha): bool
    {
        return $user->can('view:dispositivo_cozinha');
    }

    public function create(User $user): bool
    {
        return $user->can('create:dispositivo_cozinha');
    }

    public function update(User $user, DispositivoCozinha $dispositivoCozinha): bool
    {
        return $user->can('update:dispositivo_cozinha');
    }

    public function delete(User $user, DispositivoCozinha $dispositivoCozinha): bool
    {
        return $user->can('delete:dispositivo_cozinha');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:dispositivo_cozinha');
    }

    public function restore(User $user, DispositivoCozinha $dispositivoCozinha): bool
    {
        return $user->can('restore:dispositivo_cozinha');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:dispositivo_cozinha');
    }

    public function forceDelete(User $user, DispositivoCozinha $dispositivoCozinha): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /** Gerar o QR de pareamento (e revogar o tablet). */
    public function parear(User $user, DispositivoCozinha $dispositivoCozinha): bool
    {
        return $user->can('parear:dispositivo_cozinha');
    }
}
