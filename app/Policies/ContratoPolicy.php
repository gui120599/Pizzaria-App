<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contrato;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ContratoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:contrato');
    }

    public function view(AuthUser $authUser, Contrato $contrato): bool
    {
        return $authUser->can('view:contrato');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:contrato');
    }

    public function update(AuthUser $authUser, Contrato $contrato): bool
    {
        return $authUser->can('update:contrato');
    }

    public function delete(AuthUser $authUser, Contrato $contrato): bool
    {
        return $authUser->can('delete:contrato');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:contrato');
    }

    public function restore(AuthUser $authUser, Contrato $contrato): bool
    {
        return $authUser->can('restore:contrato');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:contrato');
    }

    public function forceDelete(AuthUser $authUser, Contrato $contrato): bool
    {
        return $authUser->can('force_delete:contrato');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:contrato');
    }

    public function replicate(AuthUser $authUser, Contrato $contrato): bool
    {
        return $authUser->can('replicate:contrato');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:contrato');
    }
}
