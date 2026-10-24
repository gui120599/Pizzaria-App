<?php

namespace Tests\Feature;

use App\Enums\OrigemCupomEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Filament\Pages\ConfiguracaoInfluenciadores;
use App\Filament\Resources\Cupons\CupomResource;
use App\Filament\Resources\Cupons\Pages\ManageCupons;
use App\Filament\Resources\Influenciadores\Pages\EditInfluenciador;
use App\Filament\Resources\Influenciadores\RelationManagers\ComissoesRelationManager;
use App\Filament\Resources\Influenciadores\RelationManagers\CuponsRelationManager;
use App\Models\Cliente;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Empresa;
use App\Models\Influenciador;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** F1.3/F1.5: página de configuração, Resource de cupons e RelationManagers do influenciador. */
class CupomAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    private function influenciador(): Influenciador
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
            'influenciador_valor_comissao' => 5,
        ]);
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        return $influenciador;
    }

    private function cupom(string $codigo, array $extra = []): Cupom
    {
        return Cupom::create(array_merge([
            'cupom_codigo' => $codigo,
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ], $extra));
    }

    public function test_so_admin_e_gerente_acessam_o_resource_de_cupons(): void
    {
        $this->actingAs(User::factory()->caixa()->create(['name_first' => 'Caixa']));
        $this->assertFalse(CupomResource::canViewAny());
        $this->get(CupomResource::getUrl('index'))->assertForbidden();

        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $this->assertTrue(CupomResource::canViewAny());
        $this->get(CupomResource::getUrl('index'))->assertOk();
    }

    public function test_lista_todos_os_cupons_com_filtros_de_origem_ativo_e_influenciador(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $dono = $this->influenciador();

        $interno = $this->cupom('INTERNO10');
        $doInflu = $this->cupom('FULANO10', ['cupom_influenciador_id' => $dono->id, 'cupom_origem' => OrigemCupomEnum::INFLUENCIADOR->value]);
        $inativo = $this->cupom('VELHO', ['cupom_ativo' => false]);

        Livewire::test(ManageCupons::class)
            ->assertCanSeeTableRecords([$interno, $doInflu, $inativo])
            ->filterTable('cupom_origem', OrigemCupomEnum::INFLUENCIADOR->value)
            ->assertCanSeeTableRecords([$doInflu])
            ->assertCanNotSeeTableRecords([$interno, $inativo])
            ->removeTableFilters()
            ->filterTable('cupom_influenciador_id', $dono->id)
            ->assertCanSeeTableRecords([$doInflu])
            ->assertCanNotSeeTableRecords([$interno])
            ->removeTableFilters()
            ->filterTable('cupom_ativo', false)
            ->assertCanSeeTableRecords([$inativo])
            ->assertCanNotSeeTableRecords([$interno, $doInflu]);
    }

    public function test_filtro_de_vigencia_esconde_cupom_expirado_e_futuro(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $vigente = $this->cupom('VIGENTE');
        $expirado = $this->cupom('EXPIRADO', ['cupom_data_expiracao' => now()->subDay()->toDateString()]);
        $futuro = $this->cupom('FUTURO', ['cupom_data_inicio' => now()->addDay()->toDateString()]);

        Livewire::test(ManageCupons::class)
            ->filterTable('vigente', true)
            ->assertCanSeeTableRecords([$vigente])
            ->assertCanNotSeeTableRecords([$expirado, $futuro]);
    }

    public function test_usos_e_restantes_contam_so_pedidos_validos(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $cupom = $this->cupom('LIMITADO', ['cupom_limite_uso_total' => 5]);
        $cliente = Cliente::create(['cliente_nome' => 'Cliente', 'cliente_tipo' => 'PF']);

        foreach (['PREPARANDO', 'PREPARANDO', 'CANCELADO'] as $status) {
            Pedido::create(['pedido_cliente_id' => $cliente->id, 'pedido_cupom_id' => $cupom->id, 'pedido_valor_total' => 50, 'pedido_status' => $status]);
        }

        Livewire::test(ManageCupons::class)
            ->assertTableColumnStateSet('pedidos_validos_count', 2, $cupom)
            ->assertTableColumnStateSet('usos_restantes', '3', $cupom);
    }

    public function test_cria_cupom_interno_e_alterna_ativo(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(ManageCupons::class)
            ->callAction('create', [
                'cupom_codigo' => 'black 50',
                'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
                'cupom_valor_desconto' => 50,
                'cupom_limite_uso_por_cliente' => 1,
                'cupom_ativo' => true,
            ])
            ->assertHasNoActionErrors();

        $cupom = Cupom::sole();
        $this->assertSame('BLACK50', $cupom->cupom_codigo);
        $this->assertSame(OrigemCupomEnum::INTERNO, $cupom->cupom_origem);
        $this->assertNull($cupom->cupom_influenciador_id);

        Livewire::test(ManageCupons::class)->callTableAction('alternarAtivo', $cupom);
        $this->assertFalse($cupom->fresh()->cupom_ativo);
    }

    public function test_pagina_de_configuracao_grava_tetos_e_comissao_padrao(): void
    {
        Empresa::create(['empresa_razao_social' => 'Pizzaria']);
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Livewire::test(ConfiguracaoInfluenciadores::class)
            ->fillForm([
                'empresa_influ_teto_percentual' => 12,
                'empresa_influ_teto_valor_fixo' => 15,
                'empresa_influ_teto_usos_total' => 100,
                'empresa_influ_comissao_tipo' => TipoDescontoEnum::PERCENTUAL->value,
                'empresa_influ_comissao_valor' => 6,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $empresa = Empresa::first();
        $this->assertSame('12.00', $empresa->empresa_influ_teto_percentual);
        $this->assertSame(100, $empresa->empresa_influ_teto_usos_total);
        $this->assertSame('6.00', $empresa->empresa_influ_comissao_valor);
    }

    public function test_so_o_admin_abre_a_configuracao_de_influenciadores(): void
    {
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));

        $this->assertFalse(ConfiguracaoInfluenciadores::canAccess());

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->assertTrue(ConfiguracaoInfluenciadores::canAccess());
    }

    public function test_relation_managers_do_influenciador_listam_cupons_e_comissoes(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $dono = $this->influenciador();
        $cupom = $this->cupom('FULANO10', ['cupom_influenciador_id' => $dono->id]);
        $pedido = Pedido::create(['pedido_cupom_id' => $cupom->id, 'pedido_valor_total' => 100, 'pedido_status' => 'PREPARANDO']);
        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->firstOrFail();

        Livewire::test(CuponsRelationManager::class, ['ownerRecord' => $dono, 'pageClass' => EditInfluenciador::class])
            ->assertCanSeeTableRecords([$cupom]);

        Livewire::test(ComissoesRelationManager::class, ['ownerRecord' => $dono, 'pageClass' => EditInfluenciador::class])
            ->assertCanSeeTableRecords([$comissao]);
    }

    public function test_pagina_de_edicao_do_influenciador_abre(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $dono = $this->influenciador();

        $this->get(EditInfluenciador::getUrl(['record' => $dono]))->assertOk();
    }
}
