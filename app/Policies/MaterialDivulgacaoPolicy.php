<?php

namespace App\Policies;

use App\Models\Influenciador;
use App\Models\MaterialDivulgacao;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * O Influenciador só lê os materiais (Kit de Divulgação); o User de staff
 * depende das permissions `{verbo}:material_divulgacao`.
 */
class MaterialDivulgacaoPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user instanceof Influenciador || $this->staffPode($user, 'view_any');
    }

    public function view(Authenticatable $user, MaterialDivulgacao $material): bool
    {
        return $user instanceof Influenciador || $this->staffPode($user, 'view');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->staffPode($user, 'create');
    }

    public function update(Authenticatable $user, MaterialDivulgacao $material): bool
    {
        return $this->staffPode($user, 'update');
    }

    public function delete(Authenticatable $user, MaterialDivulgacao $material): bool
    {
        return $this->staffPode($user, 'delete');
    }

    public function deleteAny(Authenticatable $user): bool
    {
        return $this->staffPode($user, 'delete');
    }

    public function forceDelete(Authenticatable $user, MaterialDivulgacao $material): bool
    {
        return false;
    }

    public function forceDeleteAny(Authenticatable $user): bool
    {
        return false;
    }

    private function staffPode(Authenticatable $user, string $verbo): bool
    {
        return $user instanceof User && $user->can("{$verbo}:material_divulgacao");
    }
}
