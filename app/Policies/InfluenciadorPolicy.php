<?php

namespace App\Policies;

use App\Models\Influenciador;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Duas identidades passam por aqui: o próprio Influenciador (painel
 * /influenciadores, só enxerga/edita a si mesmo) e o User de staff (painel
 * /admin), que depende das permissions `{verbo}:influenciador` (Admin via
 * Gate::before; Gerente via PermissionSeeder). Não existe exclusão física.
 */
class InfluenciadorPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $this->staffPode($user, 'view_any');
    }

    public function view(Authenticatable $user, Influenciador $influenciador): bool
    {
        if ($user instanceof Influenciador) {
            return $user->id === $influenciador->id;
        }

        return $this->staffPode($user, 'view');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->staffPode($user, 'create');
    }

    public function update(Authenticatable $user, Influenciador $influenciador): bool
    {
        if ($user instanceof Influenciador) {
            return $user->id === $influenciador->id;
        }

        return $this->staffPode($user, 'update');
    }

    public function delete(Authenticatable $user, Influenciador $influenciador): bool
    {
        return $this->staffPode($user, 'delete');
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return $this->staffPode($user, 'delete');
    }

    public function restore(Authenticatable $user, Influenciador $influenciador): bool
    {
        return $this->staffPode($user, 'update');
    }

    public function restoreAny(Authenticatable $user): bool
    {
        return $this->staffPode($user, 'update');
    }

    public function forceDelete(Authenticatable $user, Influenciador $influenciador): bool
    {
        return false;
    }

    public function forceDeleteAny(Authenticatable $user): bool
    {
        return false;
    }

    private function staffPode(Authenticatable $user, string $verbo): bool
    {
        return $user instanceof User && $user->can("{$verbo}:influenciador");
    }
}
