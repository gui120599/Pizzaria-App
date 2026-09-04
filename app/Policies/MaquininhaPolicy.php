<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Maquininha;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MaquininhaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:maquininha');
    }

    public function view(AuthUser $authUser, Maquininha $maquininha): bool
    {
        return $authUser->can('view:maquininha');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:maquininha');
    }

    public function update(AuthUser $authUser, Maquininha $maquininha): bool
    {
        return $authUser->can('update:maquininha');
    }

    public function delete(AuthUser $authUser, Maquininha $maquininha): bool
    {
        return $authUser->can('delete:maquininha');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:maquininha');
    }

    public function restore(AuthUser $authUser, Maquininha $maquininha): bool
    {
        return $authUser->can('restore:maquininha');
    }

    public function forceDelete(AuthUser $authUser, Maquininha $maquininha): bool
    {
        return $authUser->can('force_delete:maquininha');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:maquininha');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:maquininha');
    }

    public function replicate(AuthUser $authUser, Maquininha $maquininha): bool
    {
        return $authUser->can('replicate:maquininha');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:maquininha');
    }
}
