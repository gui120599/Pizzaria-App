<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HorarioFuncionamento;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class HorarioFuncionamentoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:horario_funcionamento');
    }

    public function view(AuthUser $authUser, HorarioFuncionamento $horarioFuncionamento): bool
    {
        return $authUser->can('view:horario_funcionamento');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:horario_funcionamento');
    }

    public function update(AuthUser $authUser, HorarioFuncionamento $horarioFuncionamento): bool
    {
        return $authUser->can('update:horario_funcionamento');
    }

    public function delete(AuthUser $authUser, HorarioFuncionamento $horarioFuncionamento): bool
    {
        return $authUser->can('delete:horario_funcionamento');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:horario_funcionamento');
    }

    public function restore(AuthUser $authUser, HorarioFuncionamento $horarioFuncionamento): bool
    {
        return $authUser->can('restore:horario_funcionamento');
    }

    public function forceDelete(AuthUser $authUser, HorarioFuncionamento $horarioFuncionamento): bool
    {
        return $authUser->can('force_delete:horario_funcionamento');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:horario_funcionamento');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:horario_funcionamento');
    }

    public function replicate(AuthUser $authUser, HorarioFuncionamento $horarioFuncionamento): bool
    {
        return $authUser->can('replicate:horario_funcionamento');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:horario_funcionamento');
    }
}
