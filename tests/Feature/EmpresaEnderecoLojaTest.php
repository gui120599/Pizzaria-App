<?php

namespace Tests\Feature;

use App\Filament\Pages\ConfiguracaoPedidos;
use App\Models\Empresa;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmpresaEnderecoLojaTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(array $dados = []): Empresa
    {
        return Empresa::create(array_merge(['empresa_razao_social' => 'Pizzaria Teste'], $dados));
    }

    public function test_endereco_retirada_junta_so_as_partes_preenchidas(): void
    {
        $this->assertSame(
            'Av. Brasil, 10 – Sala 2 – Centro – 75000-000',
            $this->empresa([
                'empresa_endereco_rua' => 'Av. Brasil',
                'empresa_endereco_numero_endereco' => '10',
                'empresa_endereco_complemento_endereco' => 'Sala 2',
                'empresa_endereco_bairro' => 'Centro',
                'empresa_endereco_cep' => '75000-000',
            ])->enderecoRetirada(),
        );

        $this->assertSame('Av. Brasil – Centro', $this->empresa([
            'empresa_endereco_rua' => 'Av. Brasil',
            'empresa_endereco_numero_endereco' => null,
            'empresa_endereco_bairro' => 'Centro',
        ])->enderecoRetirada());
    }

    public function test_sem_rua_nao_ha_endereco(): void
    {
        $this->assertNull($this->empresa(['empresa_endereco_bairro' => 'Centro'])->enderecoRetirada());
    }

    public function test_link_como_chegar_nos_tres_casos(): void
    {
        $cadastrado = $this->empresa([
            'empresa_endereco_rua' => 'Av. Brasil',
            'empresa_google_maps_url' => 'https://maps.app.goo.gl/abc123',
        ]);
        $this->assertSame('https://maps.app.goo.gl/abc123', $cadastrado->linkComoChegar());

        $cadastrado->empresa_google_maps_url = null;
        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query='.rawurlencode('Av. Brasil'),
            $cadastrado->linkComoChegar(),
        );

        $this->assertNull((new Empresa)->linkComoChegar());
    }

    public function test_link_de_dominio_estranho_gravado_nao_vira_link_da_pagina(): void
    {
        $empresa = $this->empresa([
            'empresa_endereco_rua' => 'Av. Brasil',
            'empresa_google_maps_url' => 'https://malicioso.example/x',
        ]);

        $this->assertStringStartsWith('https://www.google.com/maps/search/', $empresa->linkComoChegar());
    }

    public function test_admin_salva_endereco_e_link_pela_pagina(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->empresa();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(ConfiguracaoPedidos::class)
            ->fillForm([
                'empresa_validade_link_ticket_horas' => 24,
                'empresa_endereco_rua' => 'Av. Brasil',
                'empresa_endereco_numero_endereco' => '10',
                'empresa_endereco_bairro' => 'Centro',
                'empresa_endereco_cep' => '75000-000',
                'empresa_google_maps_url' => 'https://maps.app.goo.gl/abc123',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $empresa = Empresa::first();
        $this->assertSame('Av. Brasil', $empresa->empresa_endereco_rua);
        $this->assertSame('https://maps.app.goo.gl/abc123', $empresa->empresa_google_maps_url);
    }

    public function test_link_de_outro_dominio_e_recusado(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->empresa();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        foreach (['https://exemplo.com/mapa', 'http://maps.google.com/x', 'https://google.com.evil.com/x'] as $url) {
            Livewire::test(ConfiguracaoPedidos::class)
                ->fillForm(['empresa_validade_link_ticket_horas' => 24, 'empresa_google_maps_url' => $url])
                ->call('save')
                ->assertHasFormErrors(['empresa_google_maps_url']);
        }

        $this->assertNull(Empresa::first()->empresa_google_maps_url);
    }

    public function test_update_legado_preserva_o_link_do_google_maps(): void
    {
        $empresa = $this->empresa(['empresa_google_maps_url' => 'https://maps.app.goo.gl/abc123']);
        $this->seed(PermissionSeeder::class);
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->patch(route('empresa.update', $empresa), [
            'empresa_razao_social' => 'Pizzaria Nova',
            'empresa_endereco_rua' => 'Rua Nova',
        ]);

        $empresa->refresh();
        $this->assertSame('Pizzaria Nova', $empresa->empresa_razao_social);
        $this->assertSame('https://maps.app.goo.gl/abc123', $empresa->empresa_google_maps_url);
    }
}
