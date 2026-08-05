<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Produto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProdutoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:produto');
    }

    public function view(AuthUser $authUser, Produto $produto): bool
    {
        return $authUser->can('view:produto');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:produto');
    }

    public function update(AuthUser $authUser, Produto $produto): bool
    {
        return $authUser->can('update:produto');
    }

    public function delete(AuthUser $authUser, Produto $produto): bool
    {
        return $authUser->can('delete:produto');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:produto');
    }

    public function restore(AuthUser $authUser, Produto $produto): bool
    {
        return $authUser->can('restore:produto');
    }

    public function forceDelete(AuthUser $authUser, Produto $produto): bool
    {
        return $authUser->can('force_delete:produto');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:produto');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:produto');
    }

    public function replicate(AuthUser $authUser, Produto $produto): bool
    {
        return $authUser->can('replicate:produto');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:produto');
    }
}
