<?php

namespace Tests\Feature;

use App\Enums\OrigemCupomEnum;
use App\Enums\StatusComissaoEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Filament\Resources\Comissoes\ComissaoResource;
use App\Filament\Resources\Influenciadores\InfluenciadorResource;
use App\Filament\Resources\Influenciadores\Pages\ManageInfluenciadores;
use App\Filament\Resources\MateriaisDivulgacao\MaterialDivulgacaoResource;
use App\Filament\Resources\PagamentosComissao\PagamentoComissaoResource;
use App\Models\Cliente;
use App\Models\Comissao;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Fase 0 (F0.2): Policies do programa de influenciadores, nos dois guards. */
class InfluenciadorAutorizacaoTest extends TestCase
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
        ]);
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        return $influenciador;
    }

    private function cupomDe(?Influenciador $dono): Cupom
    {
        return Cupom::create([
            'cupom_influenciador_id' => $dono?->id,
            'cupom_codigo' => 'PROMO'.strtoupper(uniqid()),
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);
    }

    /** @return array<string, array{class-string}> */
    public static function resourcesDoAdmin(): array
    {
        return [
            'influenciadores' => [InfluenciadorResource::class],
            'comissoes' => [ComissaoResource::class],
            'resgates' => [PagamentoComissaoResource::class],
            'materiais' => [MaterialDivulgacaoResource::class],
        ];
    }

    #[DataProvider('resourcesDoAdmin')]
    public function test_garcom_atendente_e_caixa_nao_acessam_os_resources(string $resource): void
    {
        foreach (['garcom', 'atendente', 'caixa'] as $papel) {
            $this->actingAs(User::factory()->{$papel}()->create(['name_first' => ucfirst($papel)]));

            $this->assertFalse($resource::canViewAny(), "{$papel} não deveria ver {$resource}");
            $this->get($resource::getUrl('index'))->assertForbidden();
        }
    }

    #[DataProvider('resourcesDoAdmin')]
    public function test_gerente_acessa_os_resources(string $resource): void
    {
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));

        $this->assertTrue($resource::canViewAny());
        $this->get($resource::getUrl('index'))->assertOk();
    }

    public function test_nao_existe_force_delete_nem_para_admin(): void
    {
        $influenciador = $this->influenciador();
        $cupom = $this->cupomDe($influenciador);

        // O Admin passa em qualquer Gate (super_admin via Gate::before); a
        // garantia para ele é nenhuma tela oferecer a ação, conferida abaixo.
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $this->assertFalse(Gate::forUser($gerente)->allows('forceDelete', $influenciador));
        $this->assertFalse(Gate::forUser($gerente)->allows('forceDeleteAny', Influenciador::class));
        $this->assertFalse(Gate::forUser($gerente)->allows('forceDelete', $cupom));

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        Livewire::test(ManageInfluenciadores::class)
            ->assertTableActionDoesNotExist('forceDelete')
            ->assertTableBulkActionDoesNotExist('forceDelete');
    }

    public function test_cupom_policy_para_staff_depende_de_permission(): void
    {
        $cupom = $this->cupomDe($this->influenciador());
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $caixa = User::factory()->caixa()->create(['name_first' => 'Caixa']);

        $this->assertTrue(Gate::forUser($gerente)->allows('viewAny', Cupom::class));
        $this->assertTrue(Gate::forUser($gerente)->allows('update', $cupom));
        $this->assertFalse(Gate::forUser($caixa)->allows('viewAny', Cupom::class));
        $this->assertFalse(Gate::forUser($caixa)->allows('update', $cupom));
    }

    public function test_cupom_policy_para_influenciador_so_alcanca_os_proprios_cupons(): void
    {
        $dono = $this->influenciador();
        $outro = $this->influenciador();
        $cupom = $this->cupomDe($dono);

        $this->assertTrue(Gate::forUser($dono)->allows('update', $cupom));
        $this->assertTrue(Gate::forUser($dono)->allows('create', Cupom::class));
        $this->assertFalse(Gate::forUser($outro)->allows('view', $cupom));
        $this->assertFalse(Gate::forUser($outro)->allows('update', $cupom));
        $this->assertFalse(Gate::forUser($outro)->allows('delete', $cupom));
    }

    public function test_comissao_so_e_visivel_para_o_dono_e_nunca_editavel_por_ele(): void
    {
        $dono = $this->influenciador();
        $outro = $this->influenciador();
        $cupom = $this->cupomDe($dono);
        $pedido = Pedido::create([
            'pedido_cliente_id' => Cliente::create(['cliente_nome' => 'Cliente', 'cliente_tipo' => 'PF'])->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_valor_total' => 100.0,
            'pedido_status' => 'INICIADO',
        ]);
        $comissao = Comissao::where('comissao_pedido_id', $pedido->id)->firstOrFail();

        $this->assertSame(StatusComissaoEnum::PENDENTE, $comissao->comissao_status);
        $this->assertTrue(Gate::forUser($dono)->allows('view', $comissao));
        $this->assertFalse(Gate::forUser($dono)->allows('update', $comissao));
        $this->assertFalse(Gate::forUser($outro)->allows('view', $comissao));
    }

    public function test_exclusao_do_influenciador_e_barrada_com_comissao_pendente(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $influenciador = $this->influenciador();
        $cupom = $this->cupomDe($influenciador);
        Pedido::create([
            'pedido_cliente_id' => Cliente::create(['cliente_nome' => 'Cliente', 'cliente_tipo' => 'PF'])->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_valor_total' => 100.0,
            'pedido_status' => 'INICIADO',
        ]);

        Livewire::test(ManageInfluenciadores::class)
            ->callTableAction('delete', $influenciador);

        $this->assertNull($influenciador->fresh()->deleted_at);
    }

    public function test_exclusao_do_influenciador_sem_pendencia_e_soft_delete(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $influenciador = $this->influenciador();

        Livewire::test(ManageInfluenciadores::class)
            ->callTableAction('delete', $influenciador);

        $this->assertSoftDeleted($influenciador);
    }

    public function test_origem_do_cupom_continua_valendo_na_migration_consolidada(): void
    {
        $this->assertSame(OrigemCupomEnum::INTERNO, $this->cupomDe(null)->fresh()->cupom_origem);
    }
}
