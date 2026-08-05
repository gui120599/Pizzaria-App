<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PromocaoRelampago;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PromocaoRelampagoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:promocao_relampago');
    }

    public function view(AuthUser $authUser, PromocaoRelampago $promocaoRelampago): bool
    {
        return $authUser->can('view:promocao_relampago');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:promocao_relampago');
    }

    public function update(AuthUser $authUser, PromocaoRelampago $promocaoRelampago): bool
    {
        return $authUser->can('update:promocao_relampago');
    }

    public function delete(AuthUser $authUser, PromocaoRelampago $promocaoRelampago): bool
    {
        return $authUser->can('delete:promocao_relampago');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:promocao_relampago');
    }

    public function restore(AuthUser $authUser, PromocaoRelampago $promocaoRelampago): bool
    {
        return $authUser->can('restore:promocao_relampago');
    }

    public function forceDelete(AuthUser $authUser, PromocaoRelampago $promocaoRelampago): bool
    {
        return $authUser->can('force_delete:promocao_relampago');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:promocao_relampago');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:promocao_relampago');
    }

    public function replicate(AuthUser $authUser, PromocaoRelampago $promocaoRelampago): bool
    {
        return $authUser->can('replicate:promocao_relampago');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:promocao_relampago');
    }
}
