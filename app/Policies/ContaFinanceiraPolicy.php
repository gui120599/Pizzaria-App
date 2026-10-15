<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContaFinanceira;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Contas financeiras (spec contas financeiras §6): cadastro e ajuste de saldo
 * só do Admin; o Gerente vê saldos/extrato e transfere entre contas.
 */
class ContaFinanceiraPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:conta_financeira');
    }

    public function view(User $user, ContaFinanceira $contaFinanceira): bool
    {
        return $user->can('view:conta_financeira');
    }

    public function create(User $user): bool
    {
        return $user->can('create:conta_financeira');
    }

    public function update(User $user, ContaFinanceira $contaFinanceira): bool
    {
        return $user->can('update:conta_financeira');
    }

    public function delete(User $user, ContaFinanceira $contaFinanceira): bool
    {
        return $user->can('delete:conta_financeira');
    }

    public function restore(User $user, ContaFinanceira $contaFinanceira): bool
    {
        return $user->can('restore:conta_financeira');
    }

    public function transferir(User $user): bool
    {
        return $user->can('transferir:conta_financeira');
    }

    public function ajustarSaldo(User $user, ContaFinanceira $contaFinanceira): bool
    {
        return $user->can('ajustar_saldo:conta_financeira') && ! $contaFinanceira->ehCaixaPdv();
    }
}
