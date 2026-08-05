<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Compra;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CompraPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:compra');
    }

    public function view(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('view:compra');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:compra');
    }

    public function update(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('update:compra');
    }

    public function delete(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('delete:compra');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:compra');
    }

    public function restore(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('restore:compra');
    }

    public function forceDelete(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('force_delete:compra');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:compra');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:compra');
    }

    public function replicate(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('replicate:compra');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:compra');
    }

    /**
     * Confirmar uma compra em rascunho gera estoque + conta a pagar —
     * ação financeira/operacional sensível.
     */
    public function confirm(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('confirm:compra');
    }
}
