<?php

namespace Tests\Feature;

use App\Enums\OrigemCupomEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Filament\Influenciador\Resources\Cupoms\Pages\CreateCupom;
use App\Models\Cupom;
use App\Models\Influenciador;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CupomOrigemTest extends TestCase
{
    use RefreshDatabase;

    public function test_cupom_criado_sem_influenciador_nasce_com_origem_interna(): void
    {
        $cupom = Cupom::create([
            'cupom_codigo' => 'INTERNO10',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);

        $this->assertSame(OrigemCupomEnum::INTERNO, $cupom->fresh()->cupom_origem);
    }

    public function test_cupom_criado_pelo_influenciador_no_painel_nasce_com_origem_influenciador(): void
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        $this->actingAs($influenciador, 'influenciador');

        // Livewire::test() não navega pela rota real do painel, então o
        // Filament não sabe qual painel está ativo (só funciona "de graça"
        // pro painel default()) — precisa ser setado manualmente aqui.
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));

        Livewire::test(CreateCupom::class)
            ->fillForm([
                'cupom_codigo' => 'PROMO20',
                'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
                'cupom_valor_desconto' => 20,
                'cupom_limite_uso_por_cliente' => 1,
                'cupom_ativo' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cupom = Cupom::where('cupom_codigo', 'PROMO20')->firstOrFail();

        $this->assertSame(OrigemCupomEnum::INFLUENCIADOR, $cupom->cupom_origem);
        $this->assertSame($influenciador->id, $cupom->cupom_influenciador_id);
    }
}
