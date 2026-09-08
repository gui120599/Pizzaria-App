<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Caixa;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CaixaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:caixa');
    }

    public function view(AuthUser $authUser, Caixa $caixa): bool
    {
        return $authUser->can('view:caixa');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:caixa');
    }

    public function update(AuthUser $authUser, Caixa $caixa): bool
    {
        return $authUser->can('update:caixa');
    }

    public function delete(AuthUser $authUser, Caixa $caixa): bool
    {
        return $authUser->can('delete:caixa');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:caixa');
    }

    public function restore(AuthUser $authUser, Caixa $caixa): bool
    {
        return $authUser->can('restore:caixa');
    }

    public function forceDelete(AuthUser $authUser, Caixa $caixa): bool
    {
        return $authUser->can('force_delete:caixa');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:caixa');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:caixa');
    }

    public function replicate(AuthUser $authUser, Caixa $caixa): bool
    {
        return $authUser->can('replicate:caixa');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:caixa');
    }
}
