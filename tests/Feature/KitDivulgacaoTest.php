<?php

namespace Tests\Feature;

use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Enums\TipoMaterialEnum;
use App\Filament\Influenciador\Pages\KitDivulgacao;
use App\Filament\Resources\MateriaisDivulgacao\Pages\ManageMateriaisDivulgacao;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\MaterialDivulgacao;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KitDivulgacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_gerencia_materiais_de_divulgacao(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(ManageMateriaisDivulgacao::class)
            ->assertOk()
            ->callAction('create', data: [
                'material_titulo' => 'Story de lançamento',
                'material_tipo' => TipoMaterialEnum::TEXTO->value,
                'material_conteudo_texto' => 'Use o cupom {codigo} e ganhe desconto!',
                'material_ativo' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, MaterialDivulgacao::count());
    }

    public function test_influenciador_ve_legenda_com_seu_cupom_substituido(): void
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        Cupom::create([
            'cupom_influenciador_id' => $influenciador->id,
            'cupom_codigo' => 'FULANO10',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);

        MaterialDivulgacao::create([
            'material_titulo' => 'Legenda padrão',
            'material_tipo' => TipoMaterialEnum::TEXTO->value,
            'material_conteudo_texto' => 'Use o cupom {codigo} e ganhe desconto!',
            'material_ativo' => true,
        ]);

        $this->actingAs($influenciador, 'influenciador');
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));

        Livewire::test(KitDivulgacao::class)
            ->assertOk()
            ->assertSee('Use o cupom FULANO10 e ganhe desconto!');
    }

    public function test_material_inativo_nao_aparece_no_kit(): void
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        MaterialDivulgacao::create([
            'material_titulo' => 'Material inativo',
            'material_tipo' => TipoMaterialEnum::TEXTO->value,
            'material_conteudo_texto' => 'Não deveria aparecer',
            'material_ativo' => false,
        ]);

        $this->actingAs($influenciador, 'influenciador');
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));

        Livewire::test(KitDivulgacao::class)
            ->assertOk()
            ->assertDontSee('Material inativo');
    }
}
