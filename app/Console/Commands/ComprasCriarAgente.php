<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Cria (ou recria a senha d)o usuário técnico "Agente Compras", com o papel
 * Agente. É com ele que se faz login ao conectar o conector personalizado do
 * Claude ao servidor MCP; ele não entra no painel. A senha só é exibida aqui.
 */
class ComprasCriarAgente extends Command
{
    public const EMAIL = 'agente.compras@sistema.local';

    protected $signature = 'compras:criar-agente {--email= : E-mail de login (padrão agente.compras@sistema.local)} {--nova-senha : Gera uma senha nova para um agente já existente}';

    protected $description = 'Cria o usuário técnico do agente de compras (servidor MCP) e mostra a senha';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: self::EMAIL);
        $usuario = User::withTrashed()->where('email', $email)->first();

        if ($usuario !== null && ! $this->option('nova-senha')) {
            $usuario->restore();
            $usuario->assignRole('Agente');
            $this->info("O agente já existe ({$email}). Use --nova-senha para gerar outra senha.");

            return self::SUCCESS;
        }

        $senha = Str::password(20, symbols: false);

        $usuario ??= new User(['email' => $email]);
        $usuario->forceFill([
            'name' => 'Agente Compras',
            'name_first' => 'Agente Compras',
            'password' => Hash::make($senha),
            'deleted_at' => null,
        ])->save();
        $usuario->assignRole('Agente');

        $this->info('Agente de compras pronto.');
        $this->line("Login: {$email}");
        $this->line("Senha: {$senha}");
        $this->warn('Guarde a senha agora: ela não é exibida de novo.');

        return self::SUCCESS;
    }
}
