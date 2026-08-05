<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cliente;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ClientePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:cliente');
    }

    public function view(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('view:cliente');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:cliente');
    }

    public function update(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('update:cliente');
    }

    public function delete(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('delete:cliente');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:cliente');
    }

    public function restore(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('restore:cliente');
    }

    public function forceDelete(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('force_delete:cliente');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:cliente');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:cliente');
    }

    public function replicate(AuthUser $authUser, Cliente $cliente): bool
    {
        return $authUser->can('replicate:cliente');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:cliente');
    }
}
