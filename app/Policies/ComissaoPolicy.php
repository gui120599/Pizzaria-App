<?php

namespace App\Policies;

use App\Models\Comissao;
use App\Models\Influenciador;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * O Influenciador só lê as próprias comissões (nunca altera); o User de staff
 * depende das permissions `{verbo}:comissao`. Comissão não é excluída fisicamente.
 */
class ComissaoPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user instanceof Influenciador || $this->staffPode($user, 'view_any');
    }

    public function view(Authenticatable $user, Comissao $comissao): bool
    {
        if ($user instanceof Influenciador) {
            return $comissao->comissao_influenciador_id === $user->id;
        }

        return $this->staffPode($user, 'view');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->staffPode($user, 'create');
    }

    public function update(Authenticatable $user, Comissao $comissao): bool
    {
        return $this->staffPode($user, 'update');
    }

    public function delete(Authenticatable $user, Comissao $comissao): bool
    {
        return $this->staffPode($user, 'delete');
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return $this->staffPode($user, 'delete');
    }

    public function forceDelete(Authenticatable $user, Comissao $comissao): bool
    {
        return false;
    }

    public function forceDeleteAny(Authenticatable $user): bool
    {
        return false;
    }

    private function staffPode(Authenticatable $user, string $verbo): bool
    {
        return $user instanceof User && $user->can("{$verbo}:comissao");
    }
}
