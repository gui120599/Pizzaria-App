<?php

namespace Tests\Feature;

use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Models\Cupom;
use App\Models\Influenciador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfluenciadorPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * influenciador_status não é $fillable de propósito (ver Influenciador
     * model) — precisa de forceFill() após o create(), mesmo padrão usado na
     * página de registro real.
     */
    private function influenciador(StatusInfluenciadorEnum $status): Influenciador
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);

        $influenciador->forceFill(['influenciador_status' => $status])->save();

        return $influenciador;
    }

    public function test_influenciador_ativo_acessa_o_painel(): void
    {
        $influenciador = $this->influenciador(StatusInfluenciadorEnum::ATIVO);

        $this->actingAs($influenciador, 'influenciador');

        // Desde o Bloco 2, "/influenciadores" resolve direto no Dashboard
        // (App\Filament\Influenciador\Pages\Dashboard), sem redirect.
        $this->get('/influenciadores')->assertOk();
    }

    public function test_influenciador_pendente_e_redirecionado_ao_tentar_acessar_o_painel(): void
    {
        $influenciador = $this->influenciador(StatusInfluenciadorEnum::PENDENTE);

        $this->actingAs($influenciador, 'influenciador');

        $this->get('/influenciadores')->assertForbidden();
    }

    public function test_influenciador_nao_ve_cupom_de_outro_influenciador(): void
    {
        $dono = $this->influenciador(StatusInfluenciadorEnum::ATIVO);
        $outro = $this->influenciador(StatusInfluenciadorEnum::ATIVO);

        $cupom = Cupom::create([
            'cupom_influenciador_id' => $dono->id,
            'cupom_codigo' => 'DODONO',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);

        $this->actingAs($outro, 'influenciador');

        // getEloquentQuery() já escopa por influenciador_id: o registro de
        // outro influenciador nem existe dentro da query do resource, então
        // o binding de rota falha com 404 antes mesmo da Policy ser chamada.
        $this->get("/influenciadores/cupoms/{$cupom->id}/edit")->assertNotFound();
    }
}
