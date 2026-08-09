<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PrazoPagamento;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PrazoPagamentoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any:prazo_pagamento');
    }

    public function view(AuthUser $authUser, PrazoPagamento $prazoPagamento): bool
    {
        return $authUser->can('view:prazo_pagamento');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:prazo_pagamento');
    }

    public function update(AuthUser $authUser, PrazoPagamento $prazoPagamento): bool
    {
        return $authUser->can('update:prazo_pagamento');
    }

    public function delete(AuthUser $authUser, PrazoPagamento $prazoPagamento): bool
    {
        return $authUser->can('delete:prazo_pagamento');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any:prazo_pagamento');
    }

    public function restore(AuthUser $authUser, PrazoPagamento $prazoPagamento): bool
    {
        return $authUser->can('restore:prazo_pagamento');
    }

    public function forceDelete(AuthUser $authUser, PrazoPagamento $prazoPagamento): bool
    {
        return $authUser->can('force_delete:prazo_pagamento');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any:prazo_pagamento');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any:prazo_pagamento');
    }

    public function replicate(AuthUser $authUser, PrazoPagamento $prazoPagamento): bool
    {
        return $authUser->can('replicate:prazo_pagamento');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder:prazo_pagamento');
    }
}
