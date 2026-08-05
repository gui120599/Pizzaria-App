<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AvaliacaoLink;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AvaliacaoLinkPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:avaliacao_link');
    }

    public function view(AuthUser $authUser, AvaliacaoLink $avaliacaoLink): bool
    {
        return $authUser->can('view:avaliacao_link');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:avaliacao_link');
    }

    public function update(AuthUser $authUser, AvaliacaoLink $avaliacaoLink): bool
    {
        return $authUser->can('update:avaliacao_link');
    }

    public function delete(AuthUser $authUser, AvaliacaoLink $avaliacaoLink): bool
    {
        return $authUser->can('delete:avaliacao_link');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:avaliacao_link');
    }

    public function restore(AuthUser $authUser, AvaliacaoLink $avaliacaoLink): bool
    {
        return $authUser->can('restore:avaliacao_link');
    }

    public function forceDelete(AuthUser $authUser, AvaliacaoLink $avaliacaoLink): bool
    {
        return $authUser->can('force_delete:avaliacao_link');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:avaliacao_link');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:avaliacao_link');
    }

    public function replicate(AuthUser $authUser, AvaliacaoLink $avaliacaoLink): bool
    {
        return $authUser->can('replicate:avaliacao_link');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:avaliacao_link');
    }
}
