<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Venda;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VendaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:venda');
    }

    public function view(AuthUser $authUser, Venda $venda): bool
    {
        return $authUser->can('view:venda');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:venda');
    }

    public function update(AuthUser $authUser, Venda $venda): bool
    {
        return $authUser->can('update:venda');
    }

    public function delete(AuthUser $authUser, Venda $venda): bool
    {
        return $authUser->can('delete:venda');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:venda');
    }

    public function restore(AuthUser $authUser, Venda $venda): bool
    {
        return $authUser->can('restore:venda');
    }

    public function forceDelete(AuthUser $authUser, Venda $venda): bool
    {
        return $authUser->can('force_delete:venda');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:venda');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:venda');
    }

    public function replicate(AuthUser $authUser, Venda $venda): bool
    {
        return $authUser->can('replicate:venda');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:venda');
    }
}
