<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Ponto\StatusAjuste;
use App\Models\PontoAjuste;
use App\Models\User;
use App\Services\Ponto\PermissoesPonto;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Ajustes nascem e mudam só pelos Services (PontoService/CorrecaoPontoService):
 * sem create/update/delete por formulário. Aprovar o próprio é bloqueado no
 * Service também (o Admin passa em qualquer Policy).
 */
class PontoAjustePolicy
{
    use HandlesAuthorization;

    public function __construct(private readonly PermissoesPonto $permissoes) {}

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:ponto_ajuste');
    }

    public function view(User $user, PontoAjuste $pontoAjuste): bool
    {
        return $user->can('view:ponto_ajuste') || $pontoAjuste->colaborador->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PontoAjuste $pontoAjuste): bool
    {
        return false;
    }

    public function delete(User $user, PontoAjuste $pontoAjuste): bool
    {
        return false;
    }

    /** Aprovar e rejeitar solicitações pendentes. */
    public function aprovar(User $user, PontoAjuste $pontoAjuste): bool
    {
        return $pontoAjuste->status === StatusAjuste::Pendente
            && $this->permissoes->podeAprovarAjuste($user, $pontoAjuste->colaborador);
    }
}
