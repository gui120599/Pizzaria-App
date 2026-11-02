<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GerarVapidPush extends Command
{
    protected $signature = 'push:gerar-vapid';

    protected $description = 'Gera um par de chaves VAPID para o push do painel do garçom (só imprime, não grava o .env)';

    public function handle(): int
    {
        $chaves = VAPID::createVapidKeys();

        $this->warn('Gere as chaves UMA vez por instalação: trocar invalida todas as inscrições dos aparelhos.');
        $this->newLine();
        $this->line('PIZZARIA_PUSH_VAPID_PUBLICA='.$chaves['publicKey']);
        $this->line('PIZZARIA_PUSH_VAPID_PRIVADA='.$chaves['privateKey']);
        $this->line('PIZZARIA_PUSH_VAPID_SUBJECT=mailto:contato@seudominio.com.br');
        $this->newLine();
        $this->info('Cole no .env, depois rode config:cache e reinicie o PHP (OPcache) em produção.');

        return self::SUCCESS;
    }
}
