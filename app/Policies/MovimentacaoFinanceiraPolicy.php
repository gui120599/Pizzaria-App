<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MovimentacaoFinanceira;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/** Extrato somente leitura: movimentações nascem e se corrigem pelo MovimentacaoService. */
class MovimentacaoFinanceiraPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:movimentacao_financeira');
    }

    public function view(User $user, MovimentacaoFinanceira $movimentacaoFinanceira): bool
    {
        return $user->can('view:movimentacao_financeira');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MovimentacaoFinanceira $movimentacaoFinanceira): bool
    {
        return false;
    }

    public function delete(User $user, MovimentacaoFinanceira $movimentacaoFinanceira): bool
    {
        return false;
    }

    public function estornar(User $user, MovimentacaoFinanceira $movimentacaoFinanceira): bool
    {
        return $user->can('estornar:movimentacao_financeira');
    }
}
