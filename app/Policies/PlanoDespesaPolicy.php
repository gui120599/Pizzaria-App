<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlanoDespesa;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PlanoDespesaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:plano_despesa');
    }

    public function view(AuthUser $authUser, PlanoDespesa $planoDespesa): bool
    {
        return $authUser->can('view:plano_despesa');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:plano_despesa');
    }

    public function update(AuthUser $authUser, PlanoDespesa $planoDespesa): bool
    {
        return $authUser->can('update:plano_despesa');
    }

    public function delete(AuthUser $authUser, PlanoDespesa $planoDespesa): bool
    {
        return $authUser->can('delete:plano_despesa');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:plano_despesa');
    }

    public function restore(AuthUser $authUser, PlanoDespesa $planoDespesa): bool
    {
        return $authUser->can('restore:plano_despesa');
    }

    public function forceDelete(AuthUser $authUser, PlanoDespesa $planoDespesa): bool
    {
        return $authUser->can('force_delete:plano_despesa');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:plano_despesa');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:plano_despesa');
    }

    public function replicate(AuthUser $authUser, PlanoDespesa $planoDespesa): bool
    {
        return $authUser->can('replicate:plano_despesa');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:plano_despesa');
    }
}
