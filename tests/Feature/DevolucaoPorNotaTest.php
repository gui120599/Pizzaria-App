<?php

namespace Tests\Feature;

use App\Enums\CompraStatusEnum;
use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\MovimentacaoTipoEnum;
use App\Enums\StatusLancamento;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraDevolucao;
use App\Models\Empresa;
use App\Models\MovimentacaoProduto;
use App\Models\Prestador;
use App\Models\PrestadorCredito;
use App\Models\Produto;
use App\Models\User;
use App\Services\CompraDevolucaoService;
use App\Services\Compras\CompraEstornoService;
use App\Services\Compras\DevolucaoPorNotaService;
use App\Services\CompraService;
use App\Services\EstoqueService;
use App\Services\Nfe\NfeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Nota de devolução do fornecedor: nunca vira entrada; vira saída + crédito
 * (DevolucaoPorNotaService) e, se já foi confirmada como compra, é estornada
 * (CompraEstornoService). Fixtures em tests/Fixtures/nfe-entrada/.
 */
class DevolucaoPorNotaTest extends TestCase
{
    use RefreshDatabase;

    private Produto $acucar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        Empresa::create(['empresa_razao_social' => 'Pizzaria Teste LTDA', 'empresa_cnpj' => '11222333000181']);

        $this->acucar = Produto::create([
            'produto_descricao' => 'Açúcar',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Insumos'])->id,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'UN',
        ]);
    }

    private function importar(string $fixture): Compra
    {
        return app(NfeImportService::class)->importar(
            file_get_contents(base_path("tests/Fixtures/nfe-entrada/{$fixture}.xml")),
            auth()->id(),
        );
    }

    /** Compra original (fixture venda-5102-duplicatas, 10 UN de açúcar a R$ 5), já confirmada. */
    private function compraOriginalConfirmada(): Compra
    {
        $original = $this->importar('venda-5102-duplicatas');
        $this->vincularItens($original);
        app(CompraService::class)->confirmar($original->fresh());

        return $original->fresh();
    }

    /** Item 001 = açúcar; item 002 (farinha) vai para outro insumo, para o saldo do açúcar ser só o do item 001. */
    private function vincularItens(Compra $compra): void
    {
        $farinha = Produto::firstOrCreate(['produto_descricao' => 'Farinha'], [
            'produto_categoria_id' => $this->acucar->produto_categoria_id,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'UN',
        ]);

        $compra->itens()->where('ci_codigo_fornecedor', '001')->update(['ci_produto_id' => $this->acucar->id]);
        $compra->itens()->where('ci_codigo_fornecedor', '002')->update(['ci_produto_id' => $farinha->id]);
    }

    private function notaDeDevolucao(string $fixture = 'devolucao-fornecedor-nfref'): Compra
    {
        $nota = $this->importar($fixture);
        $nota->itens()->update(['ci_produto_id' => $this->acucar->id]);

        return $nota->fresh();
    }

    /** Foto do que as operações de simulação/bloqueio não podem alterar. */
    private function foto(): array
    {
        return [
            DB::table('movimentacao_produtos')->orderBy('id')->get()->toJson(),
            DB::table('lancamentos')->orderBy('id')->get()->toJson(),
            DB::table('compras')->orderBy('id')->get(['id', 'compra_status'])->toJson(),
            DB::table('produtos')->orderBy('id')->get(['id', 'produto_saldo_estoque', 'produto_custo_medio'])->toJson(),
        ];
    }

    public function test_nota_de_devolucao_importada_e_classificada_e_nao_pode_ser_confirmada_como_entrada(): void
    {
        $this->compraOriginalConfirmada();
        $nota = $this->notaDeDevolucao();
        $antes = $this->foto();

        $this->assertSame('devolucao_ao_fornecedor', $nota->compra_operacao);
        $this->assertTrue($nota->operacaoImpedeEntrada());

        try {
            app(CompraService::class)->confirmar($nota);
            $this->fail('A confirmação deveria ser recusada.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('devolução ao fornecedor', $e->errors()['compra'][0]);
        }

        $this->assertSame($antes, $this->foto());
        $this->assertSame(CompraStatusEnum::RASCUNHO, $nota->fresh()->compra_status);
    }

    public function test_compra_importada_antes_da_classificacao_e_classificada_na_hora_de_confirmar(): void
    {
        $this->compraOriginalConfirmada();
        $nota = $this->notaDeDevolucao();
        DB::table('compras')->where('id', $nota->id)->update([
            'compra_operacao' => null, 'compra_operacao_confianca' => null, 'compra_operacao_criterios' => null,
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(CompraService::class)->confirmar($nota->fresh());
        } finally {
            $this->assertSame(0, MovimentacaoProduto::where('mov_referencia_id', $nota->id)->count());
        }
    }

    public function test_compra_comum_continua_confirmando_normalmente(): void
    {
        $original = $this->compraOriginalConfirmada();

        $this->assertSame(CompraStatusEnum::CONFIRMADA, $original->compra_status);
        $this->assertEqualsWithDelta(10.0, (float) $this->acucar->fresh()->produto_saldo_estoque, 0.001);
    }

    public function test_registrar_devolucao_por_nota_baixa_o_estoque_gera_credito_e_deixa_a_nota_sem_efeito(): void
    {
        $original = $this->compraOriginalConfirmada();
        $nota = $this->notaDeDevolucao();

        $devolucao = app(DevolucaoPorNotaService::class)->registrar($nota, $original);

        $this->assertSame($original->id, $devolucao->compra_id);
        $this->assertSame($nota->id, $devolucao->compra_nota_id);
        $this->assertSame($nota->compra_chave_nfe, $devolucao->chave_nfe);
        $this->assertEqualsWithDelta(50.0, (float) $devolucao->valor_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $this->acucar->fresh()->produto_saldo_estoque, 0.001);

        $saida = MovimentacaoProduto::where('mov_origem', MovimentacaoOrigemEnum::DEVOLUCAO_COMPRA)->sole();
        $this->assertSame(MovimentacaoTipoEnum::SAIDA, $saida->mov_tipo);
        $this->assertEqualsWithDelta(10.0, (float) $saida->mov_quantidade, 0.001);

        $credito = PrestadorCredito::doPrestador($original->compra_prestador_id)->sole();
        $this->assertEqualsWithDelta(50.0, (float) $credito->valor, 0.01);

        $this->assertSame(CompraStatusEnum::SEM_EFEITO, $nota->fresh()->compra_status);
        $this->assertSame(0, MovimentacaoProduto::where('mov_referencia_id', $nota->id)->where('mov_referencia_type', Compra::class)->count());
        $this->assertSame(0, $nota->lancamentos()->count());
    }

    public function test_a_mesma_nota_nao_registra_duas_devolucoes(): void
    {
        $original = $this->compraOriginalConfirmada();
        $nota = $this->notaDeDevolucao();
        $service = app(DevolucaoPorNotaService::class);
        $service->registrar($nota, $original);
        $antes = $this->foto();

        try {
            $service->registrar($nota->fresh(), $original->fresh());
            $this->fail('Deveria recusar a segunda devolução da mesma nota.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('já está vinculada', $e->errors()['compra'][0]);
        }

        $this->assertSame($antes, $this->foto());
        $this->assertSame(1, CompraDevolucao::count());
    }

    public function test_quantidade_maior_que_a_disponivel_e_recusada_sem_efeito_algum(): void
    {
        $original = $this->compraOriginalConfirmada();
        $item = $original->itens()->where('ci_codigo_fornecedor', '001')->first();
        app(CompraDevolucaoService::class)->registrar($original, [['compra_item_id' => $item->id, 'quantidade' => 5]]);
        $nota = $this->notaDeDevolucao(); // devolve 10, restam 5
        $antes = $this->foto();

        try {
            app(DevolucaoPorNotaService::class)->registrar($nota, $original->fresh());
            $this->fail('Deveria recusar.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('maior que a disponível', $e->errors()['itens'][0]);
        }

        $this->assertSame($antes, $this->foto());
    }

    public function test_vincular_a_devolucao_manual_existente_nao_movimenta_estoque(): void
    {
        $original = $this->compraOriginalConfirmada();
        $item = $original->itens()->where('ci_codigo_fornecedor', '001')->first();
        $manual = app(CompraDevolucaoService::class)->registrar($original, [['compra_item_id' => $item->id, 'quantidade' => 10]]);
        $nota = $this->notaDeDevolucao();
        $movimentacoes = MovimentacaoProduto::count();

        $devolucao = app(DevolucaoPorNotaService::class)->vincularADevolucaoExistente($nota, $manual);

        $this->assertSame($manual->id, $devolucao->id);
        $this->assertSame($nota->id, $devolucao->compra_nota_id);
        $this->assertSame($movimentacoes, MovimentacaoProduto::count());
        $this->assertSame(1, PrestadorCredito::count());
        $this->assertSame(CompraStatusEnum::SEM_EFEITO, $nota->fresh()->compra_status);
    }

    public function test_nota_de_outro_fornecedor_nao_pode_ser_ligada_a_compra_original(): void
    {
        $original = $this->compraOriginalConfirmada();
        $nota = $this->notaDeDevolucao();
        $outro = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => 'Outro', 'nome' => 'Outro']);
        $original->forceFill(['compra_prestador_id' => $outro->id])->save();

        $this->expectException(ValidationException::class);
        app(DevolucaoPorNotaService::class)->registrar($nota, $original->fresh());
    }

    /**
     * Reproduz o incidente: a nota de devolução foi confirmada como compra
     * (entrada de estoque + título) antes de existir o bloqueio.
     */
    private function incidente(): array
    {
        $original = $this->compraOriginalConfirmada();
        $nota = $this->notaDeDevolucao();

        $nota->forceFill(['compra_xml_path' => null])->save();
        $classificacao = $nota->only(['compra_operacao', 'compra_operacao_confianca', 'compra_operacao_criterios']);
        DB::table('compras')->where('id', $nota->id)->update(['compra_operacao' => null]);
        app(CompraService::class)->confirmar($nota->fresh());
        app(CompraService::class)->gerarContaPagar($nota->fresh(), ['parcelas' => [['vencimento' => today()->toDateString(), 'valor' => 50]]]);
        DB::table('compras')->where('id', $nota->id)->update(['compra_operacao' => $classificacao['compra_operacao']]);

        return [$original->fresh(), $nota->fresh()];
    }

    public function test_incidente_simulacao_mostra_o_estorno_sem_gravar_nada(): void
    {
        [, $nota] = $this->incidente();
        $this->assertEqualsWithDelta(20.0, (float) $this->acucar->fresh()->produto_saldo_estoque, 0.001, 'entrada indevida somou ao estoque');
        $antes = $this->foto();

        $relatorio = app(CompraEstornoService::class)->simular($nota);

        $this->assertSame([], $relatorio['bloqueios']);
        $this->assertSame(20.0, $relatorio['produtos'][0]['saldo_antes']);
        $this->assertSame(10.0, $relatorio['produtos'][0]['saldo_depois']);
        $this->assertCount(1, $relatorio['titulos']);
        $this->assertSame($antes, $this->foto());
    }

    public function test_incidente_converter_em_devolucao_estorna_a_entrada_cancela_titulo_e_registra_a_saida(): void
    {
        [$original, $nota] = $this->incidente();

        $devolucao = app(DevolucaoPorNotaService::class)->converterCompraConfirmada($nota, $original, 'Nota de devolução confirmada por engano');

        $this->assertEqualsWithDelta(0.0, (float) $this->acucar->fresh()->produto_saldo_estoque, 0.001, '20 (com a entrada indevida) − 10 estornados − 10 devolvidos');
        $this->assertEqualsWithDelta(5.0, (float) $this->acucar->fresh()->produto_custo_medio, 0.0001);
        $this->assertSoftDeleted('movimentacao_produtos', ['mov_referencia_id' => $nota->id, 'mov_tipo' => 'ENTRADA']);
        $this->assertSame(StatusLancamento::Cancelado, $nota->lancamentos()->sole()->status);
        $this->assertSame(CompraStatusEnum::SEM_EFEITO, $nota->fresh()->compra_status);
        $this->assertStringContainsString('Nota de devolução confirmada por engano', $nota->fresh()->compra_observacao);
        $this->assertSame($nota->id, $devolucao->compra_nota_id);
        $this->assertEqualsWithDelta(50.0, (float) PrestadorCredito::doPrestador($original->compra_prestador_id)->sum('valor'), 0.01);
        $this->assertDatabaseHas('estoque_correcoes', ['ec_produto_id' => $this->acucar->id]);
    }

    public function test_estorno_e_bloqueado_com_titulo_pago_e_nada_e_alterado(): void
    {
        [$original, $nota] = $this->incidente();
        $titulo = $nota->lancamentos()->sole();
        $titulo->registrarPagamento(valor: 50.0, data: today());
        $antes = $this->foto();

        try {
            app(DevolucaoPorNotaService::class)->converterCompraConfirmada($nota->fresh(), $original, 'teste');
            $this->fail('Deveria bloquear.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('já tem pagamento', $e->errors()['compra'][0]);
        }

        $this->assertSame($antes, $this->foto());
        $this->assertSame(0, CompraDevolucao::count());
    }

    public function test_simulacao_avisa_quando_o_estorno_deixaria_saldo_negativo(): void
    {
        [, $nota] = $this->incidente();
        app(EstoqueService::class)->registrarSaida($this->acucar, 15, MovimentacaoOrigemEnum::VENDA);

        $relatorio = app(CompraEstornoService::class)->simular($nota);

        $this->assertSame(-5.0, $relatorio['produtos'][0]['saldo_depois']);
        $this->assertStringContainsString('saldo negativo', implode(' ', $relatorio['avisos']));
    }

    public function test_estorno_exige_motivo(): void
    {
        [, $nota] = $this->incidente();

        $this->expectException(ValidationException::class);
        app(CompraEstornoService::class)->aplicar($nota, '  ');
    }

    public function test_acao_do_filament_registra_a_devolucao_da_nota(): void
    {
        $original = $this->compraOriginalConfirmada();
        $nota = $this->notaDeDevolucao();

        Livewire::test(ListCompras::class)
            ->assertTableActionHidden('confirmar', $nota)
            ->assertTableActionVisible('registrarDevolucaoNota', $nota)
            ->callTableAction('registrarDevolucaoNota', $nota, data: ['compra_original_id' => $original->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame(CompraStatusEnum::SEM_EFEITO, $nota->fresh()->compra_status);
        $this->assertSame(1, CompraDevolucao::count());
    }

    public function test_acao_converter_em_devolucao_so_aparece_para_quem_pode_estornar(): void
    {
        [, $nota] = $this->incidente();

        Livewire::test(ListCompras::class)->assertTableActionVisible('converterEmDevolucao', $nota);

        $usuario = User::factory()->create(['name_first' => 'Comprador']);
        $usuario->givePermissionTo(array_map(
            fn (string $nome) => Permission::findOrCreate($nome),
            ['view_any:compra', 'view:compra', 'registrar:devolucao_compra'],
        ));
        $this->actingAs($usuario);

        Livewire::test(ListCompras::class)->assertTableActionHidden('converterEmDevolucao', $nota);
    }

    public function test_credito_da_devolucao_respeita_o_fator_de_conversao(): void
    {
        $original = $this->importar('venda-5102-duplicatas');
        $this->vincularItens($original);
        // 10 CX de R$ 5 cada, 12 UN por caixa: devolver 2 CX = R$ 10 de crédito (e 24 UN baixadas).
        $original->itens()->where('ci_codigo_fornecedor', '001')->update(['ci_fator_conversao' => 12, 'ci_unidade_compra' => 'CX']);
        app(CompraService::class)->confirmar($original->fresh());
        $item = $original->itens()->where('ci_codigo_fornecedor', '001')->first();

        $devolucao = app(CompraDevolucaoService::class)->registrar($original->fresh(), [['compra_item_id' => $item->id, 'quantidade' => 2]]);

        $this->assertEqualsWithDelta(10.0, (float) $devolucao->valor_total, 0.01);
        $this->assertEqualsWithDelta(24.0, (float) MovimentacaoProduto::where('mov_origem', MovimentacaoOrigemEnum::DEVOLUCAO_COMPRA)->sum('mov_quantidade'), 0.001);
    }
}
