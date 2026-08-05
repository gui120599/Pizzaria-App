<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MovimentacaoBalanco;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MovimentacaoBalancoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:movimentacao_balanco');
    }

    public function view(AuthUser $authUser, MovimentacaoBalanco $movimentacaoBalanco): bool
    {
        return $authUser->can('view:movimentacao_balanco');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:movimentacao_balanco');
    }

    public function update(AuthUser $authUser, MovimentacaoBalanco $movimentacaoBalanco): bool
    {
        return $authUser->can('update:movimentacao_balanco');
    }

    public function delete(AuthUser $authUser, MovimentacaoBalanco $movimentacaoBalanco): bool
    {
        return $authUser->can('delete:movimentacao_balanco');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:movimentacao_balanco');
    }

    public function restore(AuthUser $authUser, MovimentacaoBalanco $movimentacaoBalanco): bool
    {
        return $authUser->can('restore:movimentacao_balanco');
    }

    public function forceDelete(AuthUser $authUser, MovimentacaoBalanco $movimentacaoBalanco): bool
    {
        return $authUser->can('force_delete:movimentacao_balanco');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:movimentacao_balanco');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:movimentacao_balanco');
    }

    public function replicate(AuthUser $authUser, MovimentacaoBalanco $movimentacaoBalanco): bool
    {
        return $authUser->can('replicate:movimentacao_balanco');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:movimentacao_balanco');
    }
}
