<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\OperadoraMaquininha;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Pages\OperarVenda;
use App\Filament\Resources\FechamentosCaixa\Pages\EditFechamentoCaixa;
use App\Filament\Resources\Lancamentos\Pages\ListLancamentos;
use App\Models\Caixa;
use App\Models\CartoesPagamento;
use App\Models\FechamentoCaixa;
use App\Models\FechamentoCaixaMaquininha;
use App\Models\Lancamento;
use App\Models\LancamentoPagamento;
use App\Models\Maquininha;
use App\Models\MaquininhaTaxa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\FechamentoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PIX CNPJ com conferência própria no fechamento (extrato) e tarifa bancária;
 * recebimento de fiado vinculado à maquininha, com retrato da taxa.
 */
class PixCnpjEFiadoNaMaquininhaTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixa $sessao;

    private Maquininha $stone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => auth()->id(),
        ]);
        $this->stone = Maquininha::create(['nome' => 'Stone 1', 'operadora' => OperadoraMaquininha::Stone, 'maquininha_padrao' => true]);
    }

    private function opcao(string $nome, string $descNfe, bool $pixCnpj = false, ?float $tarifa = null): OpcoesPagamento
    {
        return OpcoesPagamento::create([
            'opcaopag_nome' => $nome,
            'opcaopag_desc_nfe' => $descNfe,
            'opcaopag_tipo_taxa' => 'N/A',
            'opcaopag_valor_percentual_taxa' => 0,
            'opcaopag_pix_cnpj' => $pixCnpj,
            'opcaopag_tarifa_percentual' => $tarifa,
        ]);
    }

    private function vendaPaga(OpcoesPagamento $opcao, float $valor): PagamentosVenda
    {
        $venda = Venda::create([
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => $valor,
            'venda_datahora_finalizada' => now(),
        ]);

        return PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => $valor,
        ]);
    }

    private function tituloAReceber(float $valor = 100, TipoLancamento $tipo = TipoLancamento::Receber): Lancamento
    {
        return Lancamento::create([
            'tipo' => $tipo,
            'descricao' => 'Fiado',
            'valor' => $valor,
            'vencimento' => now()->addDays(7),
            'status' => StatusLancamento::Pendente,
        ]);
    }

    public function test_esperado_separa_pix_das_maquininhas_do_pix_cnpj(): void
    {
        $this->vendaPaga($this->opcao('Pix QRCode', 'InstantPayment'), 80.00);
        $this->vendaPaga($this->opcao('PIX CNPJ', 'InstantPayment', pixCnpj: true), 120.00);
        $this->tituloAReceber()->registrarPagamento(30, forma: FormaPagamento::PixCnpj, sessaoCaixaId: $this->sessao->id);

        $fechamento = app(FechamentoCaixaService::class)->criarOuAtualizarRascunho($this->sessao);

        $this->assertSame('80.00', $fechamento->total_esperado_pix);
        $this->assertSame('150.00', $fechamento->total_esperado_pix_cnpj);
        $this->assertSame(230.0, $fechamento->totalEsperadoGeral);
    }

    public function test_extrato_do_pix_cnpj_informado_no_fechamento_entra_no_apurado_e_na_diferenca(): void
    {
        $this->vendaPaga($this->opcao('PIX CNPJ', 'InstantPayment', pixCnpj: true), 120.00);
        $fechamento = app(FechamentoCaixaService::class)->criarOuAtualizarRascunho($this->sessao);

        Livewire::test(EditFechamentoCaixa::class, ['record' => $fechamento->getKey()])
            ->fillForm(['valor_pix_cnpj' => '110,00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $fechamento->refresh();
        $this->assertSame(110.0, $fechamento->totalPixCnpj);
        $this->assertSame(-10.0, $fechamento->diferencaPixCnpj);
        $this->assertSame(-10.0, $fechamento->diferencaGeral);
    }

    public function test_pix_cnpj_usa_a_tarifa_da_forma_e_nao_passa_pela_maquininha(): void
    {
        MaquininhaTaxa::create(['mt_maquininha_id' => $this->stone->id, 'mt_tipo' => 'pix', 'mt_percentual' => 1.50]);

        $pagamento = $this->vendaPaga($this->opcao('PIX CNPJ', 'InstantPayment', pixCnpj: true, tarifa: 0.99), 200.00)->fresh();

        $this->assertNull($pagamento->pg_venda_maquininha_id);
        $this->assertSame('0.99', $pagamento->pg_venda_taxa_maquininha_percentual);
        $this->assertSame('1.98', $pagamento->pg_venda_taxa_maquininha_valor);
    }

    public function test_recebimento_de_fiado_grava_o_retrato_da_taxa_da_maquininha_e_da_bandeira(): void
    {
        $visa = CartoesPagamento::create(['cartao_bandeira' => 'Visa']);
        $outra = Maquininha::create(['nome' => 'Cielo', 'operadora' => OperadoraMaquininha::Cielo]);
        MaquininhaTaxa::create(['mt_maquininha_id' => $outra->id, 'mt_tipo' => 'credito', 'mt_cartao_id' => $visa->id, 'mt_percentual' => 3.00]);
        MaquininhaTaxa::create(['mt_maquininha_id' => $this->stone->id, 'mt_tipo' => 'pix', 'mt_percentual' => 1.00]);
        $this->opcao('PIX CNPJ', 'InstantPayment', pixCnpj: true, tarifa: 0.50);
        $titulo = $this->tituloAReceber(300);

        $cartao = $titulo->registrarPagamento(100, forma: FormaPagamento::CartaoCredito, cartaoId: $visa->id, maquininhaId: $outra->id);
        $pixNaPadrao = $titulo->registrarPagamento(100, forma: FormaPagamento::Pix);
        $pixCnpj = $titulo->registrarPagamento(100, forma: FormaPagamento::PixCnpj, maquininhaId: $outra->id);

        $this->assertSame(['3.00', '3.00', $outra->id], [$cartao->taxa_percentual, $cartao->taxa_valor, $cartao->maquininha_id]);
        $this->assertSame(['1.00', '1.00', null], [$pixNaPadrao->taxa_percentual, $pixNaPadrao->taxa_valor, $pixNaPadrao->maquininha_id]);
        $this->assertSame(['0.50', '0.50', null], [$pixCnpj->taxa_percentual, $pixCnpj->taxa_valor, $pixCnpj->maquininha_id]);
    }

    public function test_pagamento_de_conta_a_pagar_nao_grava_taxa_de_maquininha(): void
    {
        MaquininhaTaxa::create(['mt_maquininha_id' => $this->stone->id, 'mt_tipo' => 'credito', 'mt_percentual' => 3.00]);

        $pagamento = $this->tituloAReceber(100, TipoLancamento::Pagar)->registrarPagamento(100, forma: FormaPagamento::CartaoCredito);

        $this->assertNull($pagamento->taxa_percentual);
        $this->assertNull($pagamento->taxa_valor);
    }

    public function test_recebimento_no_pdv_em_cartao_vincula_a_maquininha_escolhida(): void
    {
        $outra = Maquininha::create(['nome' => 'Cielo', 'operadora' => OperadoraMaquininha::Cielo]);
        MaquininhaTaxa::create(['mt_maquininha_id' => $outra->id, 'mt_tipo' => 'debito', 'mt_percentual' => 1.00]);
        $this->sessao->update(['sessaocaixa_status' => 'ABERTA', 'sessaocaixa_data_hora_fechamento' => null]);
        $titulo = $this->tituloAReceber(50);
        $titulo->update(['venda_id' => Venda::create(['venda_status' => 'FINALIZADA', 'venda_sessao_caixa_id' => $this->sessao->id])->id]);
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessao->id]);

        Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->call('abrirModalRecebimento', $titulo->id)
            ->assertSet('maquininhaRecebimentoId', $this->stone->id)
            ->set('formaRecebimento', FormaPagamento::CartaoDebito->value)
            ->assertSee('Maquininha')
            ->set('maquininhaRecebimentoId', $outra->id)
            ->call('confirmarRecebimento');

        $recebimento = LancamentoPagamento::where('lancamento_id', $titulo->id)->sole();
        $this->assertSame($outra->id, $recebimento->maquininha_id);
        $this->assertSame('0.50', $recebimento->taxa_valor);
        $this->assertSame($this->sessao->id, $recebimento->sessao_caixa_id);
    }

    public function test_contas_a_receber_registra_recebimento_com_maquininha_e_bandeira(): void
    {
        $visa = CartoesPagamento::create(['cartao_bandeira' => 'Visa']);
        MaquininhaTaxa::create(['mt_maquininha_id' => $this->stone->id, 'mt_tipo' => 'credito', 'mt_percentual' => 2.00]);
        $titulo = $this->tituloAReceber(100);

        Livewire::test(ListLancamentos::class)
            ->callTableAction('marcarComoPago', $titulo, data: [
                'pagamentos' => [[
                    'data_pagamento' => now()->toDateString(),
                    'valor' => '100,00',
                    'forma_pagamento' => FormaPagamento::CartaoCredito->value,
                    'maquininha_id' => $this->stone->id,
                    'cartao_id' => $visa->id,
                ]],
            ])
            ->assertHasNoTableActionErrors();

        $recebimento = LancamentoPagamento::where('lancamento_id', $titulo->id)->sole();
        $this->assertSame([$this->stone->id, $visa->id, '2.00'], [$recebimento->maquininha_id, $recebimento->cartao_id, $recebimento->taxa_valor]);
    }

    public function test_migracao_passa_a_maquininha_falsa_para_o_pix_cnpj_sem_mudar_o_total(): void
    {
        $pixCnpj = $this->opcao('PIX CNPJ', 'InstantPayment');
        $this->vendaPaga($this->opcao('Pix QRCode', 'InstantPayment'), 50.00);
        $this->vendaPaga($pixCnpj, 120.00);
        $falsa = Maquininha::create(['nome' => 'PIX CNPJ', 'operadora' => OperadoraMaquininha::Stone]);
        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $this->sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Confirmado,
            'total_esperado_pix' => 170.00,
        ]);
        FechamentoCaixaMaquininha::create(['fechamento_caixa_id' => $fechamento->id, 'maquininha_id' => $this->stone->id, 'valor_debito' => 0, 'valor_credito' => 0, 'valor_pix' => 50]);
        FechamentoCaixaMaquininha::create(['fechamento_caixa_id' => $fechamento->id, 'maquininha_id' => $falsa->id, 'valor_debito' => 0, 'valor_credito' => 0, 'valor_pix' => 118]);
        $antes = $fechamento->fresh()->diferencaGeral;

        (require base_path('database/migrations/2026_10_07_151522_add_pix_cnpj_to_opcoes_pagamentos_table.php'))->down();
        (require base_path('database/migrations/2026_10_07_151522_add_pix_cnpj_to_opcoes_pagamentos_table.php'))->up();
        (require base_path('database/migrations/2026_10_07_151532_migrar_pix_cnpj_dos_fechamentos.php'))->up();

        $fechamento->refresh();
        $this->assertTrue($pixCnpj->fresh()->opcaopag_pix_cnpj);
        $this->assertSame(['50.00', '120.00', '118.00'], [$fechamento->total_esperado_pix, $fechamento->total_esperado_pix_cnpj, $fechamento->valor_pix_cnpj]);
        $this->assertSame($antes, $fechamento->diferencaGeral);
        $this->assertSame(1, $fechamento->maquininhas()->count());
        $this->assertSoftDeleted($falsa);
    }
}
