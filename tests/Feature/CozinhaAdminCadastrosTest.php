<?php

namespace Tests\Feature;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Cozinha\EscopoIngrediente;
use App\Enums\Cozinha\TipoSetorProducao;
use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Adicionais\Pages\ManageAdicionais;
use App\Filament\Resources\ChecklistExecucoes\Pages\ListChecklistExecucoes;
use App\Filament\Resources\ChecklistModelos\Pages\EditChecklistModelo;
use App\Filament\Resources\DispositivosCozinha\Pages\ManageDispositivosCozinha;
use App\Filament\Resources\FichasTecnicas\Pages\CreateFichaTecnica;
use App\Filament\Resources\FichasTecnicas\Pages\EditFichaTecnica;
use App\Filament\Resources\FichasTecnicas\Pages\ListFichasTecnicas;
use App\Filament\Resources\Produtos\Pages\EditProduto;
use App\Filament\Resources\SetoresProducao\Pages\CreateSetorProducao;
use App\Filament\Resources\SetoresProducao\Pages\ListSetoresProducao;
use App\Models\Adicional;
use App\Models\ChecklistModelo;
use App\Models\DispositivoCozinha;
use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\FichaTecnicaPasso;
use App\Models\RotaProducao;
use App\Models\SetorProducao;
use App\Models\User;
use App\Services\Cozinha\FichaTecnicaMontador;
use App\Services\Cozinha\ProducaoService;
use App\Services\Cozinha\RotasProducaoService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CenarioCozinha;
use Tests\TestCase;

/** Fase 3: cadastros do módulo de cozinha no admin. */
class CozinhaAdminCadastrosTest extends TestCase
{
    use CenarioCozinha, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_setor_gera_identificador_e_aceita_so_uma_expedicao_ativa(): void
    {
        Livewire::test(CreateSetorProducao::class)
            ->fillForm(['nome' => 'Bar da Frente', 'tipo' => TipoSetorProducao::Expedicao->value, 'tempo_alerta_min' => 5, 'tempo_alvo_min' => 8])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('bar-da-frente', SetorProducao::sole()->slug);

        Livewire::test(CreateSetorProducao::class)
            ->fillForm(['nome' => 'Passe', 'tipo' => TipoSetorProducao::Expedicao->value, 'tempo_alerta_min' => 5, 'tempo_alvo_min' => 4])
            ->call('create')
            ->assertHasFormErrors(['tipo', 'tempo_alvo_min' => 'gte']);
    }

    public function test_setor_com_rota_nao_pode_ser_excluido(): void
    {
        $setor = SetorProducao::factory()->create();
        $this->rotaCategoria($this->categoria('Pizzas'), $setor);

        Livewire::test(ListSetoresProducao::class)
            ->callAction(TestAction::make('delete')->table($setor))
            ->assertNotified('Este setor ainda está em uso');

        $this->assertNotSoftDeleted($setor);
    }

    public function test_rota_do_produto_salva_pela_ordem_e_troca_etapas_sem_colidir(): void
    {
        $pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria']);
        $forno = SetorProducao::factory()->create(['nome' => 'Forno']);
        // Obrigatórios das outras abas: sem eles o save falha por validação alheia ao teste.
        $produto = $this->produto('Calabresa', $this->categoria('Pizza Grande'), [
            'produto_unidade_estoque' => 'UN',
            'produto_preco_custo' => 10,
            'produto_valor_percentual_venda' => 100,
            'produto_codigo_NCM' => '19059090',
            'produto_CFOP' => '5102',
            'produto_CSOSN' => '102',
        ]);
        $desfazer = Repeater::fake();

        Livewire::test(EditProduto::class, ['record' => $produto->getRouteKey()])
            ->fillForm(['rotas_producao' => [
                ['setor_producao_id' => $pizzaria->id, 'tempo_previsto_min' => 6, 'ativo' => true],
                ['setor_producao_id' => $forno->id, 'tempo_previsto_min' => null, 'ativo' => true],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();
        $desfazer();

        $rotas = $produto->rotasProducao()->get();
        $this->assertSame([$pizzaria->id, $forno->id], $rotas->pluck('setor_producao_id')->all());
        $this->assertSame([1, 2], $rotas->pluck('etapa')->all());
        $this->assertSame(6, $rotas[0]->tempo_previsto_min);

        // Inverter a ordem atualiza as mesmas linhas (unique produto+etapa não colide).
        app(RotasProducaoService::class)->sincronizar($produto, [
            ['id' => $rotas[1]->id, 'setor_producao_id' => $forno->id],
            ['id' => $rotas[0]->id, 'setor_producao_id' => $pizzaria->id],
        ]);

        $this->assertSame([$forno->id, $pizzaria->id], $produto->rotasProducao()->pluck('setor_producao_id')->all());
        $this->assertEqualsCanonicalizing($rotas->pluck('id')->all(), RotaProducao::pluck('id')->all());

        app(RotasProducaoService::class)->sincronizar($produto, []);
        $this->assertSame(0, RotaProducao::count());
    }

    public function test_criar_ficha_preserva_a_ficha_de_custo_e_a_edicao_grava_o_escopo(): void
    {
        $insumos = $this->categoria('Insumos');
        $produto = $this->produto('Calabresa', $this->categoria('Pizza Grande'));
        $mussarela = $this->produto('Mussarela', $insumos, ['produto_tipo' => ProdutoTipoEnum::INSUMO->value]);
        $linha = FichaTecnicaItem::factory()->create(['fti_produto_id' => $produto->id, 'fti_insumo_id' => $mussarela->id, 'fti_quantidade' => 0.25, 'fti_unidade' => 'KG']);

        Livewire::withQueryParams(['produto' => $produto->id])
            ->test(CreateFichaTecnica::class)
            ->assertSchemaStateSet(['produto_id' => $produto->id])
            ->fillForm(['tempo_preparo_min' => 12, 'alergenos' => ['Lactose']])
            ->call('create')
            ->assertHasNoFormErrors();

        $ficha = FichaTecnica::sole();
        $this->assertModelExists($linha);
        $this->assertSame(['Lactose'], $ficha->alergenos);

        $desfazer = Repeater::fake();
        Livewire::test(EditFichaTecnica::class, ['record' => $ficha->getRouteKey()])
            ->assertSee('Mussarela')
            ->fillForm(['ingredientes' => [[
                'fti_insumo_id' => $mussarela->id,
                'fti_quantidade' => 0.3,
                'fti_unidade' => 'KG',
                'fti_escopo' => EscopoIngrediente::Base->value,
                'fti_percentual_perda' => 0,
            ]]])
            ->call('save')
            ->assertHasNoFormErrors();
        $desfazer();

        $linha = FichaTecnicaItem::sole();
        $this->assertSame(EscopoIngrediente::Base, $linha->fti_escopo);
        $this->assertEquals(0.3, (float) $linha->fti_quantidade);
    }

    public function test_duplicar_para_outro_tamanho_escala_as_quantidades_e_copia_os_passos(): void
    {
        $insumos = $this->categoria('Insumos');
        $grande = $this->produto('Calabresa', $this->categoria('Pizza Grande'));
        $media = $this->produto('Calabresa', $this->categoria('Pizza Média'));
        $ficha = FichaTecnica::factory()->doProduto($grande)->create(['tempo_preparo_min' => 12]);
        FichaTecnicaPasso::factory()->create(['ficha_tecnica_id' => $ficha->id, 'titulo' => 'Abrir a massa']);
        FichaTecnicaItem::factory()->create([
            'fti_produto_id' => $grande->id,
            'fti_insumo_id' => $this->produto('Mussarela', $insumos, ['produto_tipo' => ProdutoTipoEnum::INSUMO->value])->id,
            'fti_quantidade' => 0.4,
        ]);

        Livewire::test(EditFichaTecnica::class, ['record' => $ficha->getRouteKey()])
            ->mountAction('duplicar')
            ->assertSchemaStateSet(['produto_id' => $media->id], 'mountedActionSchema0')
            ->setActionData(['produto_id' => $media->id, 'fator' => 0.75])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Ficha duplicada');

        $copia = FichaTecnica::where('produto_id', $media->id)->sole();
        $this->assertSame(['Abrir a massa'], $copia->passos->pluck('titulo')->all());
        $this->assertEquals(0.3, (float) $copia->ingredientes->sole()->fti_quantidade);
        $this->assertSame(1, $copia->versao);

        Livewire::test(EditFichaTecnica::class, ['record' => $ficha->getRouteKey()])
            ->callAction('novaVersao');
        $this->assertSame(2, $ficha->fresh()->versao);
    }

    public function test_pre_visualizacao_monta_o_meio_a_meio_e_a_lista_de_fichas_conta_os_usos(): void
    {
        $grande = $this->categoria('Pizza Grande');
        $calabresa = $this->produto('Calabresa', $grande);
        $portuguesa = $this->produto('Portuguesa', $grande);
        $ficha = FichaTecnica::factory()->doProduto($calabresa)->create();

        $view = app(FichaTecnicaMontador::class)->paraSabores([$calabresa, $portuguesa], null, ['cebola']);
        $this->assertSame('½ Calabresa / ½ Portuguesa', $view->subtitulo);
        $this->assertSame(['cebola'], $view->remocoesSemLinha);

        Livewire::test(EditFichaTecnica::class, ['record' => $ficha->getRouteKey()])
            ->mountAction('preVisualizar')
            ->setActionData(['segundo_sabor' => $portuguesa->id, 'remocoes' => 'sem cebola'])
            ->assertMountedActionModalSee(['½ Calabresa / ½ Portuguesa', 'Sem cebola']);

        Livewire::test(ListFichasTecnicas::class)
            ->assertCanSeeTableRecords([$ficha])
            ->mountAction('produtosSemFicha')
            ->assertMountedActionModalSee('Todos os produtos vendidos no período têm ficha');
    }

    public function test_view_da_ficha_mostra_ingredientes_riscados_passos_e_timer(): void
    {
        $setor = SetorProducao::factory()->create();
        $produto = $this->produto('Calabresa', $this->categoria('Pizza Grande'));
        $ficha = FichaTecnica::factory()->doProduto($produto)->create(['alergenos' => ['Lactose'], 'pontos_criticos' => 'Borda dourada']);
        FichaTecnicaItem::factory()->escopo(EscopoIngrediente::Cobertura)->create([
            'fti_produto_id' => $produto->id,
            'fti_insumo_id' => $this->produto('Cebola Roxa', $this->categoria('Insumos'), ['produto_tipo' => ProdutoTipoEnum::INSUMO->value])->id,
            'fti_quantidade' => 0.06,
            'fti_unidade' => 'KG',
        ]);
        FichaTecnicaPasso::factory()->doSetor($setor)->cronometrado(240)->critico()->create(['ficha_tecnica_id' => $ficha->id, 'titulo' => 'Assar']);

        $html = view('filament.cozinha.ficha-preview', [
            'ficha' => app(FichaTecnicaMontador::class)->paraSabores([$produto], $setor, ['cebola']),
        ])->render();

        foreach (['Cebola Roxa', 'SEM', 'Lactose', 'Borda dourada', 'Assar', 'ponto crítico', '04:00', 'Passo a passo'] as $trecho) {
            $this->assertStringContainsString($trecho, $html);
        }
    }

    public function test_adicional_grava_a_porcao_da_cozinha(): void
    {
        Livewire::test(ManageAdicionais::class)
            ->callAction('create', [
                'adicional_nome' => 'Bacon',
                'adicional_valor' => '5,00',
                'adicional_quantidade_porcao' => 0.04,
                'adicional_unidade_porcao' => 'KG',
                'adicional_instrucao_preparo' => 'Antes do forno',
            ])
            ->assertHasNoActionErrors();

        $adicional = Adicional::sole();
        $this->assertEquals(0.04, (float) $adicional->adicional_quantidade_porcao);
        $this->assertSame('Antes do forno', $adicional->adicional_instrucao_preparo);
    }

    public function test_parear_mostra_o_qr_com_token_de_uso_unico_e_revogar_desfaz(): void
    {
        $setor = SetorProducao::factory()->create();
        $tablet = DispositivoCozinha::factory()->paraSetores($setor)->create(['nome' => 'Forno']);

        Livewire::test(ManageDispositivosCozinha::class)
            ->callAction(TestAction::make('parear')->table($tablet))
            ->assertActionMounted('qrPareamento')
            ->assertMountedActionModalSee(['Forno', '/cozinha/parear/']);

        $tablet->refresh();
        $this->assertNotNull($tablet->token_hash);
        $this->assertTrue($tablet->usuario->hasRole('EstacaoCozinha'));

        $tablet->forceFill(['pareado_em' => now()])->save();

        Livewire::test(ManageDispositivosCozinha::class)
            ->callAction(TestAction::make('revogar')->table($tablet));

        $tablet->refresh();
        $this->assertFalse($tablet->ativo);
        $this->assertNull($tablet->pareado_em);
        $this->assertNull($tablet->token_hash);
    }

    public function test_tablet_grava_os_setores_como_inteiros(): void
    {
        $setor = SetorProducao::factory()->create();

        Livewire::test(ManageDispositivosCozinha::class)
            ->callAction('create', ['nome' => 'Bar', 'setores' => [(string) $setor->id], 'modo_exibicao' => 'itens', 'tamanho_fonte' => 'normal'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, DispositivoCozinha::doSetor($setor->id)->count());
    }

    public function test_modelo_de_checklist_escolhe_o_setor_de_producao_e_limpa_ao_sair_da_cozinha(): void
    {
        $bar = SetorProducao::factory()->bar()->create(['nome' => 'Bar']);
        $modelo = ChecklistModelo::factory()->setor(ChecklistSetor::Cozinha)->comItens(1)->create();

        Livewire::test(EditChecklistModelo::class, ['record' => $modelo->getRouteKey()])
            ->fillForm(['setor_producao_id' => $bar->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($bar->id, $modelo->fresh()->setor_producao_id);

        Livewire::test(EditChecklistModelo::class, ['record' => $modelo->getRouteKey()])
            ->fillForm(['setor' => ChecklistSetor::Salao->value])
            ->assertFormFieldHidden('setor_producao_id')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($modelo->fresh()->setor_producao_id);
    }

    public function test_gerente_libera_a_producao_sem_abertura_pela_lista_de_execucoes(): void
    {
        config(['cozinha.checklists.bloquear_producao_sem_abertura' => true]);
        $this->travelTo('2026-10-14 16:10:00');
        $pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria']);
        ChecklistModelo::factory()->tipo(ChecklistTipo::Abertura)->setor(ChecklistSetor::Cozinha)->paraPapeis(['Cozinha'])->comItens(1)->create();
        $this->assertTrue(app(ProducaoService::class)->aberturaPendente($pizzaria));

        Livewire::test(ListChecklistExecucoes::class)
            ->callAction('liberarProducaoSemAbertura', ['setor_producao_id' => $pizzaria->id, 'justificativa' => 'Checklist no papel'])
            ->assertHasNoActionErrors();

        $this->assertFalse(app(ProducaoService::class)->aberturaPendente($pizzaria));

        config(['cozinha.checklists.bloquear_producao_sem_abertura' => false]);
        Livewire::test(ListChecklistExecucoes::class)->assertActionHidden('liberarProducaoSemAbertura');
    }
}
