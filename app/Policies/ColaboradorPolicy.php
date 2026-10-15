<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Ponto\PermissoesPonto;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Cadastro do colaborador e acesso ao ponto dele. As regras de "próprio
 * ponto" moram em PermissoesPonto (o Admin passa em qualquer Policy pelo
 * Gate::before do Shield; o Service é quem garante de verdade).
 */
class ColaboradorPolicy
{
    use HandlesAuthorization;

    public function __construct(private readonly PermissoesPonto $permissoes) {}

    public function viewAny(User $user): bool
    {
        return $user->can('view_any:colaborador');
    }

    public function view(User $user, Colaborador $colaborador): bool
    {
        return $user->can('view:colaborador') || $colaborador->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('create:colaborador');
    }

    public function update(User $user, Colaborador $colaborador): bool
    {
        return $user->can('update:colaborador');
    }

    public function delete(User $user, Colaborador $colaborador): bool
    {
        return $user->can('delete:colaborador');
    }

    public function restore(User $user, Colaborador $colaborador): bool
    {
        return $user->can('restore:colaborador');
    }

    /** Valores de remuneração (o Gerente não vê por padrão). */
    public function verRemuneracao(User $user, Colaborador $colaborador): bool
    {
        return $user->can('view_remuneracao:colaborador');
    }

    public function verEspelho(User $user, Colaborador $colaborador): bool
    {
        return $this->permissoes->podeVerEspelho($user, $colaborador);
    }

    /** Incluir, corrigir horário, desconsiderar, lançar dia, abonar e desfazer (spec §5.2.1). */
    public function corrigirPonto(User $user, Colaborador $colaborador): bool
    {
        return $this->permissoes->podeCorrigir($user, $colaborador);
    }

    /** Só o próprio colaborador pede ajuste do seu ponto. */
    public function solicitarAjuste(User $user, Colaborador $colaborador): bool
    {
        return $colaborador->user_id === $user->id;
    }
}
