<?php

namespace App\Services\Gestao\Acoes;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Uma ação do chat do Claude (G3). `preparar` não grava nada: valida e
 * monta a prévia que o usuário vê. `executar` grava, na confirmação, a
 * partir da entrada já validada e da prévia aceita.
 */
interface AcaoConfirmavel
{
    /** O perfil pode fazer esta ação (mesma permissão da tela do painel). */
    public function permitido(User $usuario): bool;

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{entrada: array<string, mixed>, previa: array<string, mixed>}
     *
     * @throws ValidationException
     */
    public function preparar(array $entrada, User $usuario): array;

    /**
     * @param  array<string, mixed>  $entrada
     * @param  array<string, mixed>  $previa
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function executar(array $entrada, array $previa, User $usuario): array;
}
