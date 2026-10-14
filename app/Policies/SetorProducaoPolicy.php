<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SetorProducao;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SetorProducaoPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:setor_producao');
    }

    public function view(User $user, SetorProducao $setorProducao): bool
    {
        return $user->can('view:setor_producao');
    }

    public function create(User $user): bool
    {
        return $user->can('create:setor_producao');
    }

    public function update(User $user, SetorProducao $setorProducao): bool
    {
        return $user->can('update:setor_producao');
    }

    public function delete(User $user, SetorProducao $setorProducao): bool
    {
        return $user->can('delete:setor_producao');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:setor_producao');
    }

    public function restore(User $user, SetorProducao $setorProducao): bool
    {
        return $user->can('restore:setor_producao');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:setor_producao');
    }

    public function forceDelete(User $user, SetorProducao $setorProducao): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder:setor_producao');
    }
}
