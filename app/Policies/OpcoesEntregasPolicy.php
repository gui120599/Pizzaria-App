<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OpcoesEntregas;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OpcoesEntregasPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:opcoes_entregas');
    }

    public function view(AuthUser $authUser, OpcoesEntregas $opcoesEntregas): bool
    {
        return $authUser->can('view:opcoes_entregas');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:opcoes_entregas');
    }

    public function update(AuthUser $authUser, OpcoesEntregas $opcoesEntregas): bool
    {
        return $authUser->can('update:opcoes_entregas');
    }

    public function delete(AuthUser $authUser, OpcoesEntregas $opcoesEntregas): bool
    {
        return $authUser->can('delete:opcoes_entregas');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:opcoes_entregas');
    }

    public function restore(AuthUser $authUser, OpcoesEntregas $opcoesEntregas): bool
    {
        return $authUser->can('restore:opcoes_entregas');
    }

    public function forceDelete(AuthUser $authUser, OpcoesEntregas $opcoesEntregas): bool
    {
        return $authUser->can('force_delete:opcoes_entregas');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:opcoes_entregas');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:opcoes_entregas');
    }

    public function replicate(AuthUser $authUser, OpcoesEntregas $opcoesEntregas): bool
    {
        return $authUser->can('replicate:opcoes_entregas');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:opcoes_entregas');
    }
}
