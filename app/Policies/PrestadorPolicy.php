<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Prestador;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PrestadorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:prestador');
    }

    public function view(AuthUser $authUser, Prestador $prestador): bool
    {
        return $authUser->can('view:prestador');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:prestador');
    }

    public function update(AuthUser $authUser, Prestador $prestador): bool
    {
        return $authUser->can('update:prestador');
    }

    public function delete(AuthUser $authUser, Prestador $prestador): bool
    {
        return $authUser->can('delete:prestador');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:prestador');
    }

    public function restore(AuthUser $authUser, Prestador $prestador): bool
    {
        return $authUser->can('restore:prestador');
    }

    public function forceDelete(AuthUser $authUser, Prestador $prestador): bool
    {
        return $authUser->can('force_delete:prestador');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:prestador');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:prestador');
    }

    public function replicate(AuthUser $authUser, Prestador $prestador): bool
    {
        return $authUser->can('replicate:prestador');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:prestador');
    }
}
