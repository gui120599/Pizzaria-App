<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Pages\RelatorioFiscalNotas;
use App\Models\Categoria;
use App\Models\ItensVenda;
use App\Models\NfEmissao;
use App\Models\NfNumeracao;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Produto;
use App\Models\User;
use App\Models\Venda;
use App\Services\RelatorioFiscalNotasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Relatório fiscal (NFC-e): só nota autorizada no período, itens como na
 * nota (sem cortesia) e todo agrupamento fechando com o total.
 */
class RelatorioFiscalNotasTest extends TestCase
{
    use RefreshDatabase;

    private Produto $pizza;

    private Produto $refri;

    private int $numero = 0;

    private NfNumeracao $numeracao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Ana', 'name' => 'Ana']));

        $this->numeracao = NfNumeracao::create(['emitente_cnpj' => '12345678000199', 'ambiente' => 'homologacao', 'modelo' => 65, 'serie' => 1, 'ultimo_numero' => 0]);

        $comidas = Categoria::create(['categoria_nome' => 'Comidas']);
        $pizzas = Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_pai_id' => $comidas->id]);
        $bebidas = Categoria::create(['categoria_nome' => 'Bebidas']);

        $this->pizza = Produto::create(['produto_descricao' => 'Calabresa', 'produto_categoria_id' => $pizzas->id, 'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value, 'produto_preco_venda' => 40, 'produto_codigo_NCM' => '19059090', 'produto_CFOP' => '5102', 'produto_CSOSN' => '102']);
        $this->refri = Produto::create(['produto_descricao' => 'Refrigerante', 'produto_categoria_id' => $bebidas->id, 'produto_tipo' => ProdutoTipoEnum::REVENDA->value, 'produto_preco_venda' => 10, 'produto_codigo_NCM' => '22021000', 'produto_CFOP' => '5405', 'produto_CSOSN' => '500']);
    }

    private function venda(string $statusNota, array $itens, ?string $autorizadaEm = null, float $frete = 0): Venda
    {
        $liquido = collect($itens)->sum(fn (array $item) => $item[2] ? 0 : $item[1]);
        $venda = Venda::create(['venda_status' => 'FINALIZADA', 'venda_datahora_iniciada' => now(), 'venda_valor_frete' => $frete, 'venda_valor_total' => $liquido + $frete, 'venda_valor_pago' => $liquido + $frete]);

        foreach ($itens as $n => [$produto, $valor, $cortesia]) {
            ItensVenda::create([
                'item_numero' => $n + 1,
                'item_venda_venda_id' => $venda->id,
                'item_venda_produto_id' => $produto->id,
                'item_venda_quantidade' => 1,
                'item_venda_quantidade_tributavel' => 1,
                'item_venda_valor_unitario' => $valor,
                'item_venda_valor' => $cortesia ? 0 : $valor,
                'item_venda_cortesia' => $cortesia,
                'item_venda_status' => 'INSERIDO',
            ]);
        }

        NfEmissao::create(['venda_id' => $venda->id, 'numeracao_id' => $this->numeracao->id, 'modelo' => 65, 'serie' => 1, 'numero' => ++$this->numero, 'status' => $statusNota, 'decisao_envio' => NfEmissao::DECISAO_AUTOMATICA, 'autorizada_em' => $autorizadaEm]);

        return $venda;
    }

    private function servico(): RelatorioFiscalNotasService
    {
        return new RelatorioFiscalNotasService(now()->startOfMonth(), now());
    }

    public function test_so_entra_nota_autorizada_no_periodo_e_sem_cortesia(): void
    {
        $this->venda('Issued', [[$this->pizza, 40, false], [$this->refri, 10, false], [$this->refri, 10, true]], now()->toDateTimeString(), 5);
        $this->venda('Reservado', [[$this->pizza, 40, false]]);
        $this->venda('Cancelled', [[$this->pizza, 40, false]], now()->toDateTimeString());
        $this->venda('Issued', [[$this->pizza, 40, false]], now()->subMonths(2)->toDateTimeString());

        $resumo = $this->servico()->resumo();

        $this->assertSame(1, $resumo['notas']);
        $this->assertSame(2, $resumo['itens']);
        $this->assertSame(50.0, $resumo['liquido']);
        $this->assertSame(5.0, $resumo['outros']);
        $this->assertSame(55.0, $resumo['total']);
    }

    public function test_nota_antiga_sem_emissao_entra_pela_data_da_venda(): void
    {
        $antiga = Venda::create(['venda_status' => 'FINALIZADA', 'venda_status_nfe' => 'Issued', 'venda_datahora_iniciada' => now()->subMonths(3), 'venda_datahora_finalizada' => now()->subMonths(3), 'venda_valor_total' => 40, 'venda_valor_pago' => 40]);
        ItensVenda::create(['item_numero' => 1, 'item_venda_venda_id' => $antiga->id, 'item_venda_produto_id' => $this->pizza->id, 'item_venda_quantidade' => 1, 'item_venda_quantidade_tributavel' => 1, 'item_venda_valor_unitario' => 40, 'item_venda_valor' => 40, 'item_venda_status' => 'INSERIDO']);

        $servico = new RelatorioFiscalNotasService(now()->subMonths(3)->startOfMonth(), now()->subMonths(3)->endOfMonth());

        $this->assertSame(1, $servico->resumo()['notas']);
        $this->assertSame(40.0, $servico->agrupar('ncm')['total']['liquido']);
        $this->assertSame(40.0, $servico->agrupar('dia')['total']['liquido']);
        $this->assertSame(0, $this->servico()->resumo()['notas']);
    }

    public function test_agrupamentos_fecham_com_o_total(): void
    {
        $this->venda('Issued', [[$this->pizza, 40, false], [$this->refri, 10, false]], now()->toDateTimeString());
        $this->venda('Issued', [[$this->pizza, 40, false]], now()->toDateTimeString());

        $servico = $this->servico();

        foreach (['ncm', 'categoria', 'produto', 'cfop_csosn', 'dia'] as $dimensao) {
            $grupo = $servico->agrupar($dimensao);
            $this->assertSame(90.0, $grupo['total']['liquido'], $dimensao);
            $this->assertSame(3, $grupo['total']['itens'], $dimensao);
        }

        $ncm = $servico->agrupar('ncm')['linhas']->firstWhere('rotulo', '19059090');
        $this->assertSame(80.0, $ncm['liquido']);

        $this->assertSame('Comidas › Pizzas', $servico->agrupar('categoria')['linhas']->first()['rotulo']);
        $this->assertSame('CFOP 5102 / CSOSN 102', $servico->agrupar('cfop_csosn')['linhas']->first()['rotulo']);
    }

    public function test_forma_de_pagamento_fecha_com_o_total_das_notas(): void
    {
        $venda = $this->venda('Issued', [[$this->pizza, 40, false]], now()->toDateTimeString());
        $venda->update(['venda_valor_pago' => 30]);
        $pix = OpcoesPagamento::create(['opcaopag_nome' => 'Pix']);
        PagamentosVenda::create(['pg_venda_venda_id' => $venda->id, 'pg_venda_opcaopagamento_id' => $pix->id, 'pg_venda_valor_pagamento' => 30, 'pg_venda_valor_pago_pelo_cliente' => 30, 'pg_venda_valor_troco' => 0]);

        $grupo = $this->servico()->agrupar('forma_pagamento');

        $this->assertSame(40.0, $grupo['total']['liquido']);
        $this->assertSame(10.0, $grupo['linhas']->firstWhere('rotulo', 'Sem pagamento (a prazo)')['liquido']);
    }

    public function test_pagina_mostra_so_os_agrupamentos_marcados_e_exporta(): void
    {
        $this->venda('Issued', [[$this->pizza, 40, false]], now()->toDateTimeString());

        Livewire::test(RelatorioFiscalNotas::class)
            ->set('agrupamentos', ['ncm'])
            ->assertSee('Por ncm')
            ->assertDontSee('Por categoria de produto');

        $query = ['agrupamentos' => ['ncm', 'produto']];
        $this->get(route('relatorios.fiscal_notas.imprimir', $query))->assertOk()->assertSee('19059090');
        $csv = $this->get(route('relatorios.fiscal_notas.csv', $query));
        $csv->assertOk();
        $this->assertStringContainsString('19059090;1;1,00;40,00;0,00;40,00', $csv->streamedContent());
    }
}
