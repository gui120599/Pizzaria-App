<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Mesa;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MesaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:mesa');
    }

    public function view(AuthUser $authUser, Mesa $mesa): bool
    {
        return $authUser->can('view:mesa');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:mesa');
    }

    public function update(AuthUser $authUser, Mesa $mesa): bool
    {
        return $authUser->can('update:mesa');
    }

    public function delete(AuthUser $authUser, Mesa $mesa): bool
    {
        return $authUser->can('delete:mesa');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:mesa');
    }

    public function restore(AuthUser $authUser, Mesa $mesa): bool
    {
        return $authUser->can('restore:mesa');
    }

    public function forceDelete(AuthUser $authUser, Mesa $mesa): bool
    {
        return $authUser->can('force_delete:mesa');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:mesa');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:mesa');
    }

    public function replicate(AuthUser $authUser, Mesa $mesa): bool
    {
        return $authUser->can('replicate:mesa');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:mesa');
    }
}
