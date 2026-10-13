<?php

namespace Tests\Feature;

use App\Enums\Checklist\TipoResposta;
use App\Filament\Resources\ChecklistModelos\ChecklistModeloResource;
use App\Filament\Resources\ChecklistModelos\Pages\CreateChecklistModelo;
use App\Filament\Resources\ChecklistModelos\Pages\EditChecklistModelo;
use App\Filament\Resources\ChecklistModelos\Pages\ListChecklistModelos;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ChecklistModeloResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_modelo_com_secoes_itens_opcoes_e_papeis(): void
    {
        $this->actingAs($this->gerente());
        $cozinha = Role::findByName('Cozinha');
        $desfazerFake = Repeater::fake();

        Livewire::test(CreateChecklistModelo::class)
            ->fillForm([
                'nome' => 'Abertura Cozinha',
                'tipo' => 'abertura',
                'setor' => 'cozinha',
                'dias_semana' => ['2', '1'],
                'janela_inicio' => '16:00',
                'janela_fim' => '18:30',
                'tolerancia_minutos' => 30,
                'roles' => [$cozinha->id],
                'secoes' => [[
                    'titulo' => 'Equipamentos',
                    'itens' => [
                        $this->item(['titulo' => 'Freezer', 'tipo_resposta' => 'temperatura', 'valor_minimo' => -25, 'valor_maximo' => -18, 'unidade' => '°C', 'critico' => true]),
                        $this->item(['titulo' => 'Máquina de cartão', 'tipo_resposta' => 'selecao', 'opcoes' => [
                            ['rotulo' => 'OK', 'conforme' => true],
                            ['rotulo' => 'Sem sinal', 'conforme' => false],
                        ]]),
                    ],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $desfazerFake();
        $modelo = ChecklistModelo::sole();
        $this->assertSame([1, 2], $modelo->dias_semana);
        $this->assertSame(['Cozinha'], $modelo->roles()->pluck('name')->all());
        $freezer = ChecklistModeloItem::where('titulo', 'Freezer')->sole();
        $this->assertSame($modelo->id, $freezer->checklist_modelo_id);
        $this->assertSame('Equipamentos', $freezer->secao->titulo);
        $this->assertSame('-18.00', $freezer->valor_maximo);
        $this->assertTrue($freezer->critico);
        $this->assertSame(
            [['valor' => 'ok', 'rotulo' => 'OK', 'conforme' => true], ['valor' => 'sem_sinal', 'rotulo' => 'Sem sinal', 'conforme' => false]],
            ChecklistModeloItem::where('titulo', 'Máquina de cartão')->sole()->opcoes,
        );
    }

    public function test_minimo_maior_que_o_maximo_e_recusado(): void
    {
        $this->actingAs($this->gerente());
        $desfazerFake = Repeater::fake();

        Livewire::test(CreateChecklistModelo::class)
            ->fillForm([
                'nome' => 'Abertura Cozinha',
                'tipo' => 'abertura',
                'setor' => 'cozinha',
                'tolerancia_minutos' => 30,
                'secoes' => [[
                    'titulo' => 'Equipamentos',
                    'itens' => [$this->item(['titulo' => 'Freezer', 'tipo_resposta' => 'temperatura', 'valor_minimo' => -10, 'valor_maximo' => -18])],
                ]],
            ])
            ->call('create')
            ->assertHasFormErrors(['secoes.0.itens.0.valor_minimo']);

        $desfazerFake();
        $this->assertSame(0, ChecklistModelo::count());
    }

    public function test_editar_itens_de_modelo_com_execucao_gera_nova_versao_e_preserva_a_execucao(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $gerente = $this->gerente();
        $this->actingAs($gerente);
        $modelo = ChecklistModelo::factory()->comItens(1)->create();
        $execucao = app(ChecklistExecucaoService::class)->iniciar($modelo, $gerente);
        $tituloOriginal = $execucao->itens()->sole()->titulo;
        $item = $modelo->itens()->sole();

        Livewire::test(EditChecklistModelo::class, ['record' => $modelo->id])
            ->set("data.secoes.record-{$item->checklist_modelo_secao_id}.itens.record-{$item->id}.titulo", 'Forno ligado e pré-aquecido')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Modelo atualizado para a versão 2');

        $this->assertSame(2, $modelo->fresh()->versao);
        $this->assertSame('Forno ligado e pré-aquecido', $modelo->itens()->sole()->titulo);
        $this->assertSame($tituloOriginal, $execucao->itens()->sole()->titulo);
    }

    public function test_duplicar_pela_tabela_cria_copia_inativa(): void
    {
        $this->actingAs($this->gerente());
        $modelo = ChecklistModelo::factory()->comItens(2)->create(['nome' => 'Fechamento Salão']);

        Livewire::test(ListChecklistModelos::class)
            ->callAction(TestAction::make('duplicar')->table($modelo))
            ->assertNotified('Modelo duplicado');

        $copia = ChecklistModelo::where('nome', 'Fechamento Salão (cópia)')->sole();
        $this->assertFalse($copia->ativo);
        $this->assertSame(2, $copia->itens()->count());
    }

    public function test_pre_visualizar_mostra_os_itens_com_a_faixa(): void
    {
        $this->actingAs($this->gerente());
        $modelo = ChecklistModelo::factory()->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->temperatura(-25, -18)->create(['titulo' => 'Freezer']);

        Livewire::test(ListChecklistModelos::class)
            ->mountAction(TestAction::make('preVisualizar')->table($modelo))
            ->assertMountedActionModalSee('Freezer')
            ->assertMountedActionModalSee('-25,0');
    }

    public function test_atendente_sem_permissao_nao_acessa_os_modelos(): void
    {
        $this->actingAs(User::factory()->atendente()->create(['name_first' => 'Carla']));

        $this->get(ChecklistModeloResource::getUrl('index'))->assertForbidden();
    }

    private function gerente(): User
    {
        return User::factory()->gerente()->create(['name_first' => 'Gerente']);
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function item(array $atributos): array
    {
        return [
            'tipo_resposta' => TipoResposta::Check->value,
            'exige_foto' => 'nunca',
            'obrigatorio' => true,
            'critico' => false,
            'exige_observacao_nao_conforme' => true,
            ...$atributos,
        ];
    }
}
