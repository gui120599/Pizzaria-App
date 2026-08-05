<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CentroCusto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CentroCustoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:centro_custo');
    }

    public function view(AuthUser $authUser, CentroCusto $centroCusto): bool
    {
        return $authUser->can('view:centro_custo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:centro_custo');
    }

    public function update(AuthUser $authUser, CentroCusto $centroCusto): bool
    {
        return $authUser->can('update:centro_custo');
    }

    public function delete(AuthUser $authUser, CentroCusto $centroCusto): bool
    {
        return $authUser->can('delete:centro_custo');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:centro_custo');
    }

    public function restore(AuthUser $authUser, CentroCusto $centroCusto): bool
    {
        return $authUser->can('restore:centro_custo');
    }

    public function forceDelete(AuthUser $authUser, CentroCusto $centroCusto): bool
    {
        return $authUser->can('force_delete:centro_custo');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:centro_custo');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:centro_custo');
    }

    public function replicate(AuthUser $authUser, CentroCusto $centroCusto): bool
    {
        return $authUser->can('replicate:centro_custo');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:centro_custo');
    }
}
