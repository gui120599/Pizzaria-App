<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlanoReceita;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PlanoReceitaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:plano_receita');
    }

    public function view(AuthUser $authUser, PlanoReceita $planoReceita): bool
    {
        return $authUser->can('view:plano_receita');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:plano_receita');
    }

    public function update(AuthUser $authUser, PlanoReceita $planoReceita): bool
    {
        return $authUser->can('update:plano_receita');
    }

    public function delete(AuthUser $authUser, PlanoReceita $planoReceita): bool
    {
        return $authUser->can('delete:plano_receita');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:plano_receita');
    }

    public function restore(AuthUser $authUser, PlanoReceita $planoReceita): bool
    {
        return $authUser->can('restore:plano_receita');
    }

    public function forceDelete(AuthUser $authUser, PlanoReceita $planoReceita): bool
    {
        return $authUser->can('force_delete:plano_receita');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:plano_receita');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:plano_receita');
    }

    public function replicate(AuthUser $authUser, PlanoReceita $planoReceita): bool
    {
        return $authUser->can('replicate:plano_receita');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:plano_receita');
    }
}
