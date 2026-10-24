<?php

namespace Tests\Feature;

use App\Enums\StatusInfluenciadorEnum;
use App\Filament\Influenciador\Pages\Auth\Register;
use App\Models\Influenciador;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobre o autocadastro público em /influenciadores/register: nasce pendente,
 * NÃO é autenticado automaticamente, e é redirecionado para a página pública
 * "cadastro em análise" em vez de bater 403 no painel (ver Register::register()).
 */
class InfluenciadorRegistroPublicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadastro_nasce_pendente_nao_autentica_e_redireciona_para_aguardando_aprovacao(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));

        Livewire::test(Register::class)
            ->fillForm([
                'influenciador_nome' => 'Fulano de Tal',
                'email' => 'fulano@teste.com',
                'influenciador_whatsapp' => '11999998888',
                'influenciador_instagram' => '@fulano',
                'password' => 'senha-segura-123',
                'passwordConfirmation' => 'senha-segura-123',
            ])
            ->call('register')
            ->assertRedirect(route('influenciador.aguardando-aprovacao'));

        $influenciador = Influenciador::where('email', 'fulano@teste.com')->firstOrFail();

        $this->assertSame(StatusInfluenciadorEnum::PENDENTE, $influenciador->influenciador_status);
        $this->assertGuest('influenciador');
    }

    public function test_pagina_de_aguardando_aprovacao_e_publica_e_responde_200(): void
    {
        $this->get(route('influenciador.aguardando-aprovacao'))->assertOk();
    }
}
