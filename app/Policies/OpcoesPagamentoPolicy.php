<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OpcoesPagamento;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OpcoesPagamentoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:opcoes_pagamento');
    }

    public function view(AuthUser $authUser, OpcoesPagamento $opcoesPagamento): bool
    {
        return $authUser->can('view:opcoes_pagamento');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:opcoes_pagamento');
    }

    public function update(AuthUser $authUser, OpcoesPagamento $opcoesPagamento): bool
    {
        return $authUser->can('update:opcoes_pagamento');
    }

    public function delete(AuthUser $authUser, OpcoesPagamento $opcoesPagamento): bool
    {
        return $authUser->can('delete:opcoes_pagamento');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:opcoes_pagamento');
    }

    public function restore(AuthUser $authUser, OpcoesPagamento $opcoesPagamento): bool
    {
        return $authUser->can('restore:opcoes_pagamento');
    }

    public function forceDelete(AuthUser $authUser, OpcoesPagamento $opcoesPagamento): bool
    {
        return $authUser->can('force_delete:opcoes_pagamento');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:opcoes_pagamento');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:opcoes_pagamento');
    }

    public function replicate(AuthUser $authUser, OpcoesPagamento $opcoesPagamento): bool
    {
        return $authUser->can('replicate:opcoes_pagamento');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:opcoes_pagamento');
    }
}
