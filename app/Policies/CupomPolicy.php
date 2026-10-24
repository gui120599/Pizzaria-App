<?php

namespace App\Policies;

use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Dois guards: o Influenciador só enxerga e altera os próprios cupons; o User
 * de staff depende das permissions `{verbo}:cupom` (Admin via Gate::before;
 * Gerente via PermissionSeeder).
 */
class CupomPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user instanceof Influenciador || $this->staffPode($user, 'view_any');
    }

    public function view(Authenticatable $user, Cupom $cupom): bool
    {
        if ($user instanceof Influenciador) {
            return $this->ehDono($user, $cupom);
        }

        return $this->staffPode($user, 'view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user instanceof Influenciador || $this->staffPode($user, 'create');
    }

    public function update(Authenticatable $user, Cupom $cupom): bool
    {
        if ($user instanceof Influenciador) {
            return $this->ehDono($user, $cupom);
        }

        return $this->staffPode($user, 'update');
    }

    public function delete(Authenticatable $user, Cupom $cupom): bool
    {
        if ($user instanceof Influenciador) {
            return $this->ehDono($user, $cupom);
        }

        return $this->staffPode($user, 'delete');
    }

    public function forceDelete(Authenticatable $user, Cupom $cupom): bool
    {
        return false;
    }

    public function forceDeleteAny(Authenticatable $user): bool
    {
        return false;
    }

    private function ehDono(Influenciador $influenciador, Cupom $cupom): bool
    {
        return $cupom->cupom_influenciador_id === $influenciador->id;
    }

    private function staffPode(Authenticatable $user, string $verbo): bool
    {
        return $user instanceof User && $user->can("{$verbo}:cupom");
    }
}
