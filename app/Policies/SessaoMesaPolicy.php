<?php

namespace App\Policies;

use App\Models\SessaoMesa;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * SessaoMesa não tem Resource no Filament (é módulo só da interface legada),
 * então o Shield não gera Policy pra ela — feita à mão, seguindo o mesmo padrão
 * das Policies geradas (Admin passa por tudo via Gate::before em config/filament-shield.php).
 */
class SessaoMesaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:sessao_mesa');
    }

    public function view(AuthUser $authUser, SessaoMesa $sessaoMesa): bool
    {
        return $authUser->can('view:sessao_mesa');
    }

    /** Abertura pelo Resource novo do Filament (CreateRecord::canCreate()). */
    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:sessao_mesa');
    }

    /**
     * Gerente mexe em qualquer sessão de mesa; Atendente/Garçom só na própria
     * (a que ele abriu), pra não interferir no atendimento de outro colega.
     */
    public function update(AuthUser $authUser, SessaoMesa $sessaoMesa): bool
    {
        if ($authUser->hasRole('Gerente')) {
            return true;
        }

        return $sessaoMesa->sessao_mesa_usuario_id === $authUser->id;
    }

    /**
     * Excluir a sessão inteira (diferente de fechar/reabrir) fica restrito ao
     * Gerente — mais destrutivo que update, sem a exceção de dono.
     */
    public function delete(AuthUser $authUser, SessaoMesa $sessaoMesa): bool
    {
        return $authUser->hasRole('Gerente');
    }
}
