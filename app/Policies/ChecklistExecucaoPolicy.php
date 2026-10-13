<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ChecklistExecucao;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Execuções não são criadas nem editadas por formulário: nascem e mudam só
 * pelo ChecklistExecucaoService. O histórico do /garcom (só as próprias
 * execuções) libera a listagem no próprio Resource, não aqui.
 */
class ChecklistExecucaoPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:checklist_execucao');
    }

    /** Gestão vê todas; a equipe vê as que iniciou ou em que respondeu itens. */
    public function view(User $user, ChecklistExecucao $checklistExecucao): bool
    {
        return $user->can('view:checklist_execucao') || $checklistExecucao->participou($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ChecklistExecucao $checklistExecucao): bool
    {
        return false;
    }

    /** Responder, anexar foto, finalizar e reabrir após reprovação. */
    public function executar(User $user, ChecklistExecucao $checklistExecucao): bool
    {
        return $checklistExecucao->modelo->podeSerExecutadoPor($user);
    }

    /** Aprovar e reprovar. */
    public function aprovar(User $user, ChecklistExecucao $checklistExecucao): bool
    {
        return $user->can('aprovar:checklist_execucao');
    }

    public function delete(User $user, ChecklistExecucao $checklistExecucao): bool
    {
        return $user->can('delete:checklist_execucao');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:checklist_execucao');
    }

    public function restore(User $user, ChecklistExecucao $checklistExecucao): bool
    {
        return $user->can('restore:checklist_execucao');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:checklist_execucao');
    }
}
