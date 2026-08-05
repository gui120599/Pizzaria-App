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
