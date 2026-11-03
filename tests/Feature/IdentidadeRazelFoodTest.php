<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Favicon e ícones da marca RazelFood nos painéis internos. */
class IdentidadeRazelFoodTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function paineis(): array
    {
        return [
            'admin' => ['/admin/login'],
            'garçom' => ['/garcom/login'],
            'cozinha' => ['/cozinha/login'],
            'influenciador' => ['/influenciadores/login'],
        ];
    }

    /**
     * @dataProvider paineis
     */
    public function test_painel_declara_os_icones_da_marca_com_tipo_e_tamanho(string $url): void
    {
        $this->get($url)
            ->assertOk()
            ->assertSee('rel="icon" type="image/png" sizes="32x32"', false)
            ->assertSee('rel="apple-touch-icon" sizes="180x180"', false)
            ->assertSee('img/razelfood/favicon.ico', false);
    }

    public function test_arquivos_de_icone_existem_e_o_favicon_da_raiz_nao_esta_vazio(): void
    {
        foreach (['favicon.ico', 'img/razelfood/favicon.ico', 'img/razelfood/favicon-32.png', 'img/razelfood/apple-touch-icon.png', 'img/razelfood/icon-512.png', 'img/razelfood/badge-96.png'] as $arquivo) {
            $this->assertGreaterThan(0, filesize(public_path($arquivo)), $arquivo);
        }
    }
}
