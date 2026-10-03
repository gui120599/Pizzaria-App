<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Adicional;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AdicionalPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:adicional');
    }

    public function view(AuthUser $authUser, Adicional $adicional): bool
    {
        return $authUser->can('view:adicional');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:adicional');
    }

    public function update(AuthUser $authUser, Adicional $adicional): bool
    {
        return $authUser->can('update:adicional');
    }

    public function delete(AuthUser $authUser, Adicional $adicional): bool
    {
        return $authUser->can('delete:adicional');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:adicional');
    }

    public function restore(AuthUser $authUser, Adicional $adicional): bool
    {
        return $authUser->can('restore:adicional');
    }

    public function forceDelete(AuthUser $authUser, Adicional $adicional): bool
    {
        return $authUser->can('force_delete:adicional');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:adicional');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:adicional');
    }

    public function replicate(AuthUser $authUser, Adicional $adicional): bool
    {
        return $authUser->can('replicate:adicional');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:adicional');
    }
}
