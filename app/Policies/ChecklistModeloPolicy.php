<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChecklistModelo;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChecklistModeloPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:checklist_modelo');
    }

    public function view(User $user, ChecklistModelo $checklistModelo): bool
    {
        return $user->can('view:checklist_modelo');
    }

    public function create(User $user): bool
    {
        return $user->can('create:checklist_modelo');
    }

    public function update(User $user, ChecklistModelo $checklistModelo): bool
    {
        return $user->can('update:checklist_modelo');
    }

    public function delete(User $user, ChecklistModelo $checklistModelo): bool
    {
        return $user->can('delete:checklist_modelo');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:checklist_modelo');
    }

    public function restore(User $user, ChecklistModelo $checklistModelo): bool
    {
        return $user->can('restore:checklist_modelo');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:checklist_modelo');
    }

    public function forceDelete(User $user, ChecklistModelo $checklistModelo): bool
    {
        return $user->can('force_delete:checklist_modelo');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:checklist_modelo');
    }

    /** Duplicar = criar um modelo novo a partir deste. */
    public function replicate(User $user, ChecklistModelo $checklistModelo): bool
    {
        return $user->can('create:checklist_modelo');
    }

    public function reorder(User $user): bool
    {
        return $user->can('update:checklist_modelo');
    }

    /** Executar no /garcom: papel atribuído ao modelo (Admin/Gerente executam todos). */
    public function executar(User $user, ChecklistModelo $checklistModelo): bool
    {
        return $checklistModelo->ativo && $checklistModelo->podeSerExecutadoPor($user);
    }
}
