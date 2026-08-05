<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Pedido;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PedidoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:pedido');
    }

    public function view(AuthUser $authUser, Pedido $pedido): bool
    {
        return $authUser->can('view:pedido');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:pedido');
    }

    public function update(AuthUser $authUser, Pedido $pedido): bool
    {
        return $authUser->can('update:pedido');
    }

    public function delete(AuthUser $authUser, Pedido $pedido): bool
    {
        return $authUser->can('delete:pedido');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:pedido');
    }

    public function restore(AuthUser $authUser, Pedido $pedido): bool
    {
        return $authUser->can('restore:pedido');
    }

    public function forceDelete(AuthUser $authUser, Pedido $pedido): bool
    {
        return $authUser->can('force_delete:pedido');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:pedido');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:pedido');
    }

    public function replicate(AuthUser $authUser, Pedido $pedido): bool
    {
        return $authUser->can('replicate:pedido');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:pedido');
    }

    public function accept(AuthUser $authUser): bool
    {
        return $authUser->can('accept:pedido');
    }

    public function reject(AuthUser $authUser): bool
    {
        return $authUser->can('reject:pedido');
    }

    /**
     * Gerente cancela qualquer pedido; Atendente só o que ele mesmo abriu
     * (pedido_usuario_garcom_id), pra não interferir no atendimento de outro colega.
     */
    public function cancel(AuthUser $authUser, Pedido $pedido): bool
    {
        if (! $authUser->can('cancel:pedido')) {
            return false;
        }

        if ($authUser->hasRole('Gerente')) {
            return true;
        }

        return $pedido->pedido_usuario_garcom_id === $authUser->id;
    }

    /**
     * Mesma regra de cancel: restaurar um pedido cancelado usa a mesma
     * permission (cancel:pedido, ver routes/web.php) e a mesma ownership.
     */
    public function restaurar(AuthUser $authUser, Pedido $pedido): bool
    {
        return $this->cancel($authUser, $pedido);
    }

    public function advance(AuthUser $authUser): bool
    {
        return $authUser->can('advance:pedido');
    }

    /**
     * Só o entregador atribuído ao pedido consegue marcar como entregue —
     * mesma regra de EntregaService::marcarEntregue().
     */
    public function deliver(AuthUser $authUser, Pedido $pedido): bool
    {
        return $authUser->can('deliver:pedido') && $pedido->pedido_usuario_entrega_id === $authUser->id;
    }

    /**
     * Qualquer entregador pode tentar assumir uma entrega ainda sem dono —
     * quem ganha a corrida é o update atômico em EntregaService::aceitar(),
     * esta Policy só decide quem pode tentar.
     */
    public function claim(AuthUser $authUser, Pedido $pedido): bool
    {
        return $authUser->can('deliver:pedido') && $pedido->pedido_usuario_entrega_id === null;
    }
}
