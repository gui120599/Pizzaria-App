<?php

namespace App\Policies;

use App\Models\SessaoCaixa;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * SessaoCaixa não tem Resource no Filament (é módulo só da interface legada),
 * então o Shield não gera Policy pra ela — feita à mão, seguindo o mesmo padrão
 * das Policies geradas (Admin passa por tudo via Gate::before em config/filament-shield.php).
 */
class SessaoCaixaPolicy
{
    use HandlesAuthorization;

    public function open(AuthUser $authUser): bool
    {
        return $authUser->can('abrir:sessao_caixa');
    }

    /**
     * Fechar a própria sessão. Quem abriu é quem fecha — evita um operador
     * encerrar o turno de outro por engano.
     */
    public function close(AuthUser $authUser, SessaoCaixa $sessaoCaixa): bool
    {
        return $authUser->can('fechar:sessao_caixa') && $sessaoCaixa->sessaocaixa_user_id === $authUser->id;
    }

    public function update(AuthUser $authUser, SessaoCaixa $sessaoCaixa): bool
    {
        return $this->close($authUser, $sessaoCaixa);
    }

    /**
     * Excluir uma sessão de caixa quebra a trilha de auditoria financeira —
     * fica restrito ao bypass de Admin, ninguém mais.
     */
    public function delete(AuthUser $authUser, SessaoCaixa $sessaoCaixa): bool
    {
        return false;
    }
}
