<?php

namespace Tests\Feature;

use App\Enums\BaseVencimentoEnum;
use App\Enums\FormaPagamento;
use App\Enums\RegraDataEntradaEnum;
use App\Filament\Resources\Compras\Pages\EditCompra;
use App\Filament\Resources\Prestadors\Pages\ManagePrestadors;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\FornecedorCondicaoCompra;
use App\Models\PrazoPagamento;
use App\Models\Prestador;
use App\Models\User;
use App\Services\Nfe\NfeImportService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CondicoesCompraFornecedorTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ_EMITENTE = '14200166000166';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function fornecedor(array $atributos = []): Prestador
    {
        return Prestador::create(array_merge([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora X',
            'cpf_cnpj' => self::CNPJ_EMITENTE,
        ], $atributos));
    }

    private function xmlExemplo(): string
    {
        return file_get_contents(base_path('tests/Fixtures/nfe-compra-exemplo.xml'));
    }

    private function compraRascunho(Prestador $fornecedor, ?string $emissao, ?string $entrada = null): Compra
    {
        return Compra::create([
            'compra_prestador_id' => $fornecedor->id,
            'compra_numero' => '1',
            'compra_data_emissao' => $emissao,
            'compra_data_entrada' => $entrada,
            'compra_user_id' => auth()->id(),
        ]);
    }

    // ── Cadastro ─────────────────────────────────────────────────────

    public function test_cria_fornecedor_com_condicoes_de_compra(): void
    {
        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => '30/60', 'prazo_pagamento_ativo' => true]);
        $conta = ContaFinanceira::factory()->banco()->create();

        Livewire::test(ManagePrestadors::class)
            ->callAction(CreateAction::class, [
                'tipo' => 'pj',
                'categoria' => 'fornecedor',
                'razao_social' => 'Atacado Y',
                'condicaoCompra' => [
                    'fcc_prazo_pagamento_id' => $prazo->id,
                    'fcc_prazo_base' => 'emissao',
                    'fcc_forma_pagamento' => 'boleto',
                    'fcc_conta_financeira_id' => $conta->id,
                    'fcc_data_entrada_regra' => 'emissao',
                ],
            ])
            ->assertHasNoFormErrors();

        $condicao = Prestador::sole()->condicaoCompra;

        $this->assertSame($prazo->id, $condicao->fcc_prazo_pagamento_id);
        $this->assertSame(BaseVencimentoEnum::Emissao, $condicao->fcc_prazo_base);
        $this->assertSame(FormaPagamento::Boleto, $condicao->fcc_forma_pagamento);
        $this->assertSame($conta->id, $condicao->fcc_conta_financeira_id);
        $this->assertSame(RegraDataEntradaEnum::Emissao, $condicao->fcc_data_entrada_regra);
    }

    public function test_edita_condicoes_de_fornecedor_sem_criar_segundo_registro(): void
    {
        $fornecedor = $this->fornecedor();
        FornecedorCondicaoCompra::factory()->create(['fcc_prestador_id' => $fornecedor->id]);

        Livewire::test(ManagePrestadors::class)
            ->callAction(TestAction::make(EditAction::class)->table($fornecedor), [
                'condicaoCompra' => [
                    'fcc_prazo_base' => 'emissao',
                    'fcc_forma_pagamento' => 'pix',
                    'fcc_data_entrada_regra' => 'emissao',
                ],
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(1, FornecedorCondicaoCompra::count());
        $condicao = $fornecedor->fresh()->condicaoCompra;
        $this->assertSame(BaseVencimentoEnum::Emissao, $condicao->fcc_prazo_base);
        $this->assertSame(FormaPagamento::Pix, $condicao->fcc_forma_pagamento);
        $this->assertSame(RegraDataEntradaEnum::Emissao, $condicao->fcc_data_entrada_regra);
    }

    public function test_categoria_diferente_de_fornecedor_nao_grava_condicoes(): void
    {
        Livewire::test(ManagePrestadors::class)
            ->callAction(CreateAction::class, [
                'tipo' => 'pf',
                'categoria' => 'autonomo',
                'nome' => 'João da Silva',
                'condicaoCompra' => ['fcc_prazo_base' => 'emissao', 'fcc_data_entrada_regra' => 'emissao'],
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(0, FornecedorCondicaoCompra::count());
    }

    // ── Importador ───────────────────────────────────────────────────

    public function test_importador_usa_a_emissao_como_entrada_quando_o_fornecedor_tem_essa_regra(): void
    {
        $fornecedor = $this->fornecedor();
        FornecedorCondicaoCompra::factory()->dataEntradaPelaEmissao()->create(['fcc_prestador_id' => $fornecedor->id]);

        $compra = app(NfeImportService::class)->importar($this->xmlExemplo());

        $this->assertSame('2026-01-15', $compra->compra_data_entrada->toDateString());
    }

    public function test_importador_usa_hoje_sem_condicao_ou_com_regra_hoje(): void
    {
        $fornecedor = $this->fornecedor();

        $semCondicao = app(NfeImportService::class)->importar($this->xmlExemplo());
        $this->assertSame(today()->toDateString(), $semCondicao->compra_data_entrada->toDateString());

        FornecedorCondicaoCompra::factory()->create(['fcc_prestador_id' => $fornecedor->id]);
        $outraNota = str_replace(
            ['35260114200166000166550010000000461123456789', '<nNF>46</nNF>'],
            ['35260114200166000166550010000000471123456789', '<nNF>47</nNF>'],
            $this->xmlExemplo(),
        );

        $comRegraHoje = app(NfeImportService::class)->importar($outraNota);
        $this->assertSame(today()->toDateString(), $comRegraHoje->compra_data_entrada->toDateString());
    }

    // ── Modal Confirmar compra ───────────────────────────────────────

    public function test_modal_sugere_a_emissao_como_data_de_entrada_e_avisa_a_regra(): void
    {
        $fornecedor = $this->fornecedor();
        FornecedorCondicaoCompra::factory()->dataEntradaPelaEmissao()->create(['fcc_prestador_id' => $fornecedor->id]);
        $compra = $this->compraRascunho($fornecedor, emissao: '2026-07-01', entrada: '2026-07-20');

        $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('confirmar');

        $this->assertSame('2026-07-01', substr((string) $testable->get('mountedActions.0.data.data_entrada'), 0, 10));
        $this->assertSame('2026-07-01', substr((string) $testable->get('mountedActions.0.data.data_base'), 0, 10));
        $this->assertTrue($compra->fresh()->dataEntradaPelaRegraDeEmissao());
    }

    public function test_modal_sem_condicao_mantem_a_data_de_entrada_gravada(): void
    {
        $compra = $this->compraRascunho($this->fornecedor(), emissao: '2026-07-01', entrada: '2026-07-20');

        $testable = Livewire::test(EditCompra::class, ['record' => $compra->getKey()])->mountAction('confirmar');

        $this->assertSame('2026-07-20', substr((string) $testable->get('mountedActions.0.data.data_entrada'), 0, 10));
        $this->assertFalse($compra->fresh()->dataEntradaPelaRegraDeEmissao());
    }

    public function test_regra_de_emissao_nunca_sugere_data_futura(): void
    {
        $fornecedor = $this->fornecedor();
        FornecedorCondicaoCompra::factory()->dataEntradaPelaEmissao()->create(['fcc_prestador_id' => $fornecedor->id]);
        $compra = $this->compraRascunho($fornecedor, emissao: today()->addDays(3)->toDateString());

        $this->assertSame(today()->toDateString(), $compra->fresh()->dataEntradaSugerida()->toDateString());
    }
}
