<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PontoPeriodo;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Períodos de apuração: quem fecha período (Admin, spec §5.6) cadastra e
 * conduz o fechamento. As transições passam pelo FechamentoPeriodoService.
 */
class PontoPeriodoPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('fechar:ponto_periodo');
    }

    public function view(User $user, PontoPeriodo $pontoPeriodo): bool
    {
        return $user->can('fechar:ponto_periodo');
    }

    public function create(User $user): bool
    {
        return $user->can('fechar:ponto_periodo');
    }

    /** Só a data de pagamento muda, e só antes do fechamento. */
    public function update(User $user, PontoPeriodo $pontoPeriodo): bool
    {
        return $user->can('fechar:ponto_periodo') && ! $pontoPeriodo->estaTravado();
    }

    public function delete(User $user, PontoPeriodo $pontoPeriodo): bool
    {
        return false;
    }
}
