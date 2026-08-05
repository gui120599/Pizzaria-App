<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FechamentoCaixa;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FechamentoCaixaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:fechamento_caixa');
    }

    public function view(AuthUser $authUser, FechamentoCaixa $fechamentoCaixa): bool
    {
        return $authUser->can('view:fechamento_caixa');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:fechamento_caixa');
    }

    public function update(AuthUser $authUser, FechamentoCaixa $fechamentoCaixa): bool
    {
        return $authUser->can('update:fechamento_caixa');
    }

    public function delete(AuthUser $authUser, FechamentoCaixa $fechamentoCaixa): bool
    {
        return $authUser->can('delete:fechamento_caixa');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:fechamento_caixa');
    }

    public function restore(AuthUser $authUser, FechamentoCaixa $fechamentoCaixa): bool
    {
        return $authUser->can('restore:fechamento_caixa');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:fechamento_caixa');
    }

    public function forceDelete(AuthUser $authUser, FechamentoCaixa $fechamentoCaixa): bool
    {
        return $authUser->can('force_delete:fechamento_caixa');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:fechamento_caixa');
    }

    public function replicate(AuthUser $authUser, FechamentoCaixa $fechamentoCaixa): bool
    {
        return $authUser->can('replicate:fechamento_caixa');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:fechamento_caixa');
    }
}
