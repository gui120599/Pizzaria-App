<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MovimentacaoProduto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MovimentacaoProdutoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:movimentacao_produto');
    }

    public function view(AuthUser $authUser, MovimentacaoProduto $movimentacaoProduto): bool
    {
        return $authUser->can('view:movimentacao_produto');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:movimentacao_produto');
    }

    public function update(AuthUser $authUser, MovimentacaoProduto $movimentacaoProduto): bool
    {
        return $authUser->can('update:movimentacao_produto');
    }

    public function delete(AuthUser $authUser, MovimentacaoProduto $movimentacaoProduto): bool
    {
        return $authUser->can('delete:movimentacao_produto');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:movimentacao_produto');
    }

    public function restore(AuthUser $authUser, MovimentacaoProduto $movimentacaoProduto): bool
    {
        return $authUser->can('restore:movimentacao_produto');
    }

    public function forceDelete(AuthUser $authUser, MovimentacaoProduto $movimentacaoProduto): bool
    {
        return $authUser->can('force_delete:movimentacao_produto');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:movimentacao_produto');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:movimentacao_produto');
    }

    public function replicate(AuthUser $authUser, MovimentacaoProduto $movimentacaoProduto): bool
    {
        return $authUser->can('replicate:movimentacao_produto');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:movimentacao_produto');
    }

    /**
     * Correção retroativa de estoque/custo médio: reescreve movimentações já
     * gravadas (e, às vezes, o custo já congelado de vendas passadas), então
     * usa a mesma gramática de permissão sensível do financeiro (ver
     * LancamentoPolicy::reversePayment).
     */
    public function corrigir(AuthUser $authUser, ?MovimentacaoProduto $movimentacaoProduto = null): bool
    {
        return $authUser->can('corrigir:movimentacao_estoque');
    }
}
