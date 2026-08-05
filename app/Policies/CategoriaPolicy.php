<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Categoria;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CategoriaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:categoria');
    }

    public function view(AuthUser $authUser, Categoria $categoria): bool
    {
        return $authUser->can('view:categoria');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:categoria');
    }

    public function update(AuthUser $authUser, Categoria $categoria): bool
    {
        return $authUser->can('update:categoria');
    }

    public function delete(AuthUser $authUser, Categoria $categoria): bool
    {
        return $authUser->can('delete:categoria');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:categoria');
    }

    public function restore(AuthUser $authUser, Categoria $categoria): bool
    {
        return $authUser->can('restore:categoria');
    }

    public function forceDelete(AuthUser $authUser, Categoria $categoria): bool
    {
        return $authUser->can('force_delete:categoria');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:categoria');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:categoria');
    }

    public function replicate(AuthUser $authUser, Categoria $categoria): bool
    {
        return $authUser->can('replicate:categoria');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:categoria');
    }
}
