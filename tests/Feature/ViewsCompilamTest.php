<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * O deploy roda `php artisan view:cache`, que compila todas as views. Um
 * componente Blade removido mas ainda usado em alguma tela (como o
 * <x-categoria-button> das telas legadas de pedido) só quebra ali — e só na
 * tela que o usa, se ninguém rodar o comando.
 */
class ViewsCompilamTest extends TestCase
{
    protected function tearDown(): void
    {
        Artisan::call('view:clear');

        parent::tearDown();
    }

    public function test_todas_as_views_compilam_como_no_deploy(): void
    {
        $this->artisan('view:cache')->assertSuccessful();
    }
}
