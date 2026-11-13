<?php

namespace App\Services\Compras;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Usuário técnico que assina o que a automação de compras faz sem ninguém
 * logado (polling da SEFAZ, comandos agendados): a compra e as entradas de
 * estoque exigem um usuário (movimentacao_produtos.mov_user_id). Não tem
 * papel, então não entra em nenhum painel; a senha é aleatória e nunca usada.
 */
class UsuarioAutomacaoCompras
{
    public const EMAIL = 'automacao.compras@sistema.local';

    public function obter(): User
    {
        return User::withTrashed()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Automação de Compras',
                'name_first' => 'Automação de Compras',
                'password' => Hash::make(Str::random(64)),
            ],
        );
    }

    public function id(): int
    {
        return (int) $this->obter()->getKey();
    }
}
