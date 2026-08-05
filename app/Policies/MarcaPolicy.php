<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Marca;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MarcaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:marca');
    }

    public function view(AuthUser $authUser, Marca $marca): bool
    {
        return $authUser->can('view:marca');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:marca');
    }

    public function update(AuthUser $authUser, Marca $marca): bool
    {
        return $authUser->can('update:marca');
    }

    public function delete(AuthUser $authUser, Marca $marca): bool
    {
        return $authUser->can('delete:marca');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:marca');
    }

    public function restore(AuthUser $authUser, Marca $marca): bool
    {
        return $authUser->can('restore:marca');
    }

    public function forceDelete(AuthUser $authUser, Marca $marca): bool
    {
        return $authUser->can('force_delete:marca');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:marca');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:marca');
    }

    public function replicate(AuthUser $authUser, Marca $marca): bool
    {
        return $authUser->can('replicate:marca');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:marca');
    }
}
