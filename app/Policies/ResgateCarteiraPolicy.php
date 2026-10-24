<?php

namespace App\Policies;

use App\Models\Influenciador;
use App\Models\ResgateCarteira;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resgates são registros financeiros imutáveis: só `view_any`/`create` para o
 * staff (permissions `{verbo}:resgate_carteira`), nunca edição ou exclusão.
 * O Influenciador só lê os próprios.
 */
class ResgateCarteiraPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user instanceof Influenciador || $this->staffPode($user, 'view_any');
    }

    public function view(Authenticatable $user, ResgateCarteira $resgate): bool
    {
        if ($user instanceof Influenciador) {
            return $resgate->rc_influenciador_id === $user->id;
        }

        return $this->staffPode($user, 'view_any');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->staffPode($user, 'create');
    }

    public function update(Authenticatable $user, ResgateCarteira $resgate): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, ResgateCarteira $resgate): bool
    {
        return false;
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return false;
    }

    public function forceDelete(Authenticatable $user, ResgateCarteira $resgate): bool
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
