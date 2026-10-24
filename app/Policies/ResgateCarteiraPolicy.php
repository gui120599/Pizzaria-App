<?php

namespace App\Policies;

use App\Models\Influenciador;
use App\Models\PagamentoComissao;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resgates são registros financeiros imutáveis: só `view_any`/`create` para o
 * staff (permissions `{verbo}:resgate_carteira`), nunca edição ou exclusão.
 * O Influenciador só lê os próprios. Até a Fase 2 renomear o model, esta
 * policy está registrada para PagamentoComissao (AuthServiceProvider).
 */
class ResgateCarteiraPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user instanceof Influenciador || $this->staffPode($user, 'view_any');
    }

    public function view(Authenticatable $user, PagamentoComissao $resgate): bool
    {
        if ($user instanceof Influenciador) {
            return $resgate->pgcom_influenciador_id === $user->id;
        }

        return $this->staffPode($user, 'view_any');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->staffPode($user, 'create');
    }

    public function update(Authenticatable $user, PagamentoComissao $resgate): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, PagamentoComissao $resgate): bool
    {
        return false;
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return false;
    }

    public function forceDelete(Authenticatable $user, PagamentoComissao $resgate): bool
    {
        return false;
    }

    public function forceDeleteAny(Authenticatable $user): bool
    {
        return false;
    }

    private function staffPode(Authenticatable $user, string $verbo): bool
    {
        return $user instanceof User && $user->can("{$verbo}:resgate_carteira");
    }
}
