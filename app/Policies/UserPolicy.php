<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:user');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('view:user');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:user');
    }

    /**
     * Ninguém edita as próprias roles/permissions por aqui — mesmo que o
     * bypass de Admin via Gate::before torne isso irrelevante para o Admin
     * (o guarda real dele está na UI, em UserResource), mantém a regra
     * explícita para qualquer outra role que ganhe update:user no futuro.
     */
    public function update(AuthUser $authUser, User $user): bool
    {
        if ($authUser->is($user)) {
            return false;
        }

        return $authUser->can('update:user');
    }

    public function delete(AuthUser $authUser, User $user): bool
    {
        if ($authUser->is($user)) {
            return false;
        }

        return $authUser->can('delete:user');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:user');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('restore:user');
    }

    public function forceDelete(AuthUser $authUser, User $user): bool
    {
        if ($authUser->is($user)) {
            return false;
        }

        return $authUser->can('force_delete:user');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:user');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:user');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('replicate:user');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:user');
    }
}
