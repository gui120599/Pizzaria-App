<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BannerCardapio;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Banners do carrossel do cardápio. Admin passa pelo Gate::before; o Gerente
 * recebe as permissões na migration que cria a tabela. Excluir de vez fica
 * só com o Admin.
 */
class BannerCardapioPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:banner_cardapio');
    }

    public function view(AuthUser $authUser, BannerCardapio $bannerCardapio): bool
    {
        return $authUser->can('view:banner_cardapio');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:banner_cardapio');
    }

    public function update(AuthUser $authUser, BannerCardapio $bannerCardapio): bool
    {
        return $authUser->can('update:banner_cardapio');
    }

    public function delete(AuthUser $authUser, BannerCardapio $bannerCardapio): bool
    {
        return $authUser->can('delete:banner_cardapio');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:banner_cardapio');
    }

    public function restore(AuthUser $authUser, BannerCardapio $bannerCardapio): bool
    {
        return $authUser->can('restore:banner_cardapio');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore:banner_cardapio');
    }

    public function forceDelete(AuthUser $authUser, BannerCardapio $bannerCardapio): bool
    {
        return $authUser->can('force_delete:banner_cardapio');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete:banner_cardapio');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:banner_cardapio');
    }
}
