<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChecklistNaoConformidade;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/** NCs nascem na finalização da execução (ChecklistExecucaoService), nunca por formulário. */
class ChecklistNaoConformidadePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:checklist_nao_conformidade');
    }

    /** Gestão vê todas; o responsável vê as suas. */
    public function view(User $user, ChecklistNaoConformidade $checklistNaoConformidade): bool
    {
        return $user->can('view:checklist_nao_conformidade')
            || $checklistNaoConformidade->responsavel_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ChecklistNaoConformidade $checklistNaoConformidade): bool
    {
        return $user->can('update:checklist_nao_conformidade');
    }

    public function delete(User $user, ChecklistNaoConformidade $checklistNaoConformidade): bool
    {
        return $user->can('delete:checklist_nao_conformidade');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:checklist_nao_conformidade');
    }

    public function restore(User $user, ChecklistNaoConformidade $checklistNaoConformidade): bool
    {
        return $user->can('restore:checklist_nao_conformidade');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:checklist_nao_conformidade');
    }
}
