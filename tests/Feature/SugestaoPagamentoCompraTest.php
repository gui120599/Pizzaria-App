<?php

namespace Tests\Feature;

use App\Enums\BaseVencimentoEnum;
use App\Enums\FormaPagamento;
use App\Enums\VencimentoOrigemEnum;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\FornecedorCondicaoCompra;
use App\Models\PrazoPagamento;
use App\Models\Prestador;
use App\Models\User;
use App\Services\Compras\SugestaoPagamentoCompra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SugestaoPagamentoCompraTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVES = ['vencimento', 'valor', 'forma_pagamento', 'ja_pago', 'conta_financeira_id', 'sessao_caixa_id'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function compra(array $condicao = [], array $compra = []): Compra
    {
        $fornecedor = Prestador::create(['tipo' => 'pj', 'categoria' => 'fornecedor', 'razao_social' => 'Distribuidora X']);

        if ($condicao !== []) {
            FornecedorCondicaoCompra::factory()->create(['fcc_prestador_id' => $fornecedor->id, ...$condicao]);
        }

        return Compra::create([
            'compra_prestador_id' => $fornecedor->id,
            'compra_numero' => '10',
            'compra_data_emissao' => '2026-07-01',
            'compra_data_entrada' => '2026-07-10',
            'compra_valor_total' => 100,
            'compra_user_id' => auth()->id(),
            ...$compra,
        ])->fresh();
    }

    private function prazo(array $dias, bool $ativo = true, array $formas = []): PrazoPagamento
    {
        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => implode('/', $dias), 'prazo_pagamento_ativo' => $ativo]);
        foreach ($dias as $i => $d) {
            $prazo->parcelas()->create([
                'parcela_numero' => $i + 1,
                'parcela_dias' => $d,
                'parcela_percentual' => 100 / count($dias),
                'parcela_forma_pagamento' => $formas[$i] ?? null,
            ]);
        }

        return $prazo;
    }

    private function sugestao(Compra $compra)
    {
        return app(SugestaoPagamentoCompra::class)->para($compra);
    }

    private function assertLinhasCompletas(array $parcelas): void
    {
        $this->assertNotEmpty($parcelas);
        foreach ($parcelas as $linha) {
            foreach (self::CHAVES as $chave) {
                $this->assertArrayHasKey($chave, $linha);
            }
            $this->assertFalse($linha['ja_pago']);
            $this->assertNull($linha['sessao_caixa_id']);
        }
    }

    public function test_duplicatas_validas_viram_as_parcelas(): void
    {
        $compra = $this->compra(compra: ['compra_duplicatas_nf' => [
            ['nDup' => '001', 'dVenc' => '2026-08-01', 'vDup' => 40],
            ['nDup' => '002', 'dVenc' => '2026-09-01', 'vDup' => 60],
        ]]);

        $s = $this->sugestao($compra);

        $this->assertSame(VencimentoOrigemEnum::DuplicatasNf, $s->origem);
        $this->assertNull($s->prazoPagamentoId);
        $this->assertSame(['2026-08-01', '2026-09-01'], array_column($s->parcelas, 'vencimento'));
        $this->assertSame(['40.00', '60.00'], array_column($s->parcelas, 'valor'));
        $this->assertSame([], $s->avisos);
        $this->assertLinhasCompletas($s->parcelas);
    }

    public function test_duplicatas_somando_menos_acrescentam_linha_com_a_diferenca_e_avisam(): void
    {
        $compra = $this->compra(compra: [
            'compra_duplicatas_nf' => [['nDup' => '001', 'dVenc' => '2026-08-01', 'vDup' => 70]],
            'compra_pagamentos_nf' => [['tPag' => '17', 'indPag' => '0', 'vPag' => 30]],
        ]);

        $s = $this->sugestao($compra);
        $linhas = array_values($s->parcelas);

        $this->assertCount(2, $linhas);
        $this->assertSame('30.00', $linhas[1]['valor']);
        $this->assertSame('2026-07-01', $linhas[1]['vencimento']);
        $this->assertSame(FormaPagamento::Pix->value, $linhas[1]['forma_pagamento']);
        $this->assertCount(1, $s->avisos);
        $this->assertLinhasCompletas($s->parcelas);
    }

    public function test_duplicatas_somando_mais_avisam_sem_linha_extra(): void
    {
        $compra = $this->compra(compra: ['compra_duplicatas_nf' => [['nDup' => '001', 'dVenc' => '2026-08-01', 'vDup' => 120]]]);

        $s = $this->sugestao($compra);

        $this->assertCount(1, $s->parcelas);
        $this->assertCount(1, $s->avisos);
    }

    public function test_duplicata_sem_vencimento_e_descartada_e_todas_invalidas_caem_no_prazo(): void
    {
        $prazo = $this->prazo([30]);
        $compra = $this->compra(['fcc_prazo_pagamento_id' => $prazo->id], ['compra_duplicatas_nf' => [
            ['nDup' => '001', 'dVenc' => null, 'vDup' => 100],
            ['nDup' => '002', 'dVenc' => '2026-08-01', 'vDup' => 0],
        ]]);

        $s = $this->sugestao($compra);

        $this->assertSame(VencimentoOrigemEnum::PrazoEntrada, $s->origem);
        $this->assertSame($prazo->id, $s->prazoPagamentoId);
        $this->assertSame(['2026-08-09'], array_column($s->parcelas, 'vencimento'));
        $this->assertNotEmpty($s->avisos);
        $this->assertLinhasCompletas($s->parcelas);
    }

    public function test_aviso_quando_o_prazo_do_fornecedor_diverge_das_duplicatas(): void
    {
        $compra = $this->compra(
            ['fcc_prazo_pagamento_id' => $this->prazo([30, 60])->id],
            ['compra_duplicatas_nf' => [['nDup' => '001', 'dVenc' => '2026-11-28', 'vDup' => 100]]],
        );

        $s = $this->sugestao($compra);

        $this->assertSame(VencimentoOrigemEnum::DuplicatasNf, $s->origem);
        $this->assertStringContainsString('30/60', $s->avisos[0]);
    }

    public function test_base_emissao_e_base_entrada_geram_vencimentos_diferentes(): void
    {
        $prazo = $this->prazo([30]);

        $porEntrada = $this->sugestao($this->compra(['fcc_prazo_pagamento_id' => $prazo->id, 'fcc_prazo_base' => BaseVencimentoEnum::Entrada]));
        $porEmissao = $this->sugestao($this->compra(['fcc_prazo_pagamento_id' => $prazo->id, 'fcc_prazo_base' => BaseVencimentoEnum::Emissao]));

        $this->assertSame(['2026-08-09'], array_column($porEntrada->parcelas, 'vencimento'));
        $this->assertSame(VencimentoOrigemEnum::PrazoEntrada, $porEntrada->origem);
        $this->assertSame(['2026-07-31'], array_column($porEmissao->parcelas, 'vencimento'));
        $this->assertSame(VencimentoOrigemEnum::PrazoEmissao, $porEmissao->origem);
        $this->assertSame('2026-07-01', $porEmissao->dataBase->toDateString());
    }

    public function test_prazo_inativo_ou_excluido_cai_na_parcela_unica(): void
    {
        $inativo = $this->prazo([30], ativo: false);
        $s = $this->sugestao($this->compra(['fcc_prazo_pagamento_id' => $inativo->id]));
        $this->assertCount(1, $s->parcelas);
        $this->assertNull($s->prazoPagamentoId);

        $excluido = $this->prazo([60]);
        $compra = $this->compra(['fcc_prazo_pagamento_id' => $excluido->id]);
        $excluido->delete();
        $s = $this->sugestao($compra->fresh());
        $this->assertCount(1, $s->parcelas);
        $this->assertNull($s->prazoPagamentoId);
        $this->assertSame(['2026-07-10'], array_column($s->parcelas, 'vencimento'));
        $this->assertLinhasCompletas($s->parcelas);
    }

    public function test_sem_condicao_mantem_parcela_unica_na_entrada(): void
    {
        $s = $this->sugestao($this->compra());

        $this->assertSame(VencimentoOrigemEnum::PrazoEntrada, $s->origem);
        $this->assertSame(['2026-07-10'], array_column($s->parcelas, 'vencimento'));
        $this->assertSame(['100.00'], array_column($s->parcelas, 'valor'));
        $this->assertSame([], $s->fontes);
        $this->assertLinhasCompletas($s->parcelas);
    }

    public function test_precedencia_da_forma_parcela_do_prazo_fornecedor_tpag_nenhuma(): void
    {
        $prazo = $this->prazo([0, 30], formas: [FormaPagamento::Dinheiro->value, null]);
        $compra = $this->compra(
            ['fcc_prazo_pagamento_id' => $prazo->id, 'fcc_forma_pagamento' => FormaPagamento::Boleto],
            ['compra_pagamentos_nf' => [['tPag' => '17', 'indPag' => '0', 'vPag' => 100]]],
        );
        $formas = array_column($this->sugestao($compra)->parcelas, 'forma_pagamento');
        $this->assertSame([FormaPagamento::Dinheiro->value, FormaPagamento::Boleto->value], array_values($formas));

        $semFornecedor = $this->compra(['fcc_prazo_pagamento_id' => $prazo->id], ['compra_pagamentos_nf' => [['tPag' => '17', 'indPag' => '0', 'vPag' => 100]]]);
        $formas = array_column($this->sugestao($semFornecedor)->parcelas, 'forma_pagamento');
        $this->assertSame([FormaPagamento::Dinheiro->value, FormaPagamento::Pix->value], array_values($formas));

        $semNada = $this->compra(['fcc_prazo_pagamento_id' => $this->prazo([30])->id]);
        $this->assertSame([null], array_values(array_column($this->sugestao($semNada)->parcelas, 'forma_pagamento')));
    }

    public function test_tpag_sem_mapeamento_ou_ambiguo_nao_sugere_forma(): void
    {
        $service = app(SugestaoPagamentoCompra::class);

        $this->assertNull($service->formaDaNota($this->compra(compra: ['compra_pagamentos_nf' => [['tPag' => '99', 'indPag' => '0', 'vPag' => 100]]])));
        $this->assertNull($service->formaDaNota($this->compra(compra: ['compra_pagamentos_nf' => [
            ['tPag' => '01', 'indPag' => '0', 'vPag' => 50],
            ['tPag' => '15', 'indPag' => '0', 'vPag' => 50],
        ]])));
    }

    public function test_conta_sugerida_entra_em_todas_as_linhas_sem_marcar_ja_pago_e_conta_excluida_vira_nula(): void
    {
        $conta = ContaFinanceira::factory()->banco()->create();
        $prazo = $this->prazo([30, 60]);
        $compra = $this->compra(['fcc_prazo_pagamento_id' => $prazo->id, 'fcc_conta_financeira_id' => $conta->id]);

        $s = $this->sugestao($compra);
        $this->assertSame([$conta->id, $conta->id], array_values(array_column($s->parcelas, 'conta_financeira_id')));
        $this->assertLinhasCompletas($s->parcelas);

        $conta->delete();
        $s = $this->sugestao($compra->fresh());
        $this->assertSame([null, null], array_values(array_column($s->parcelas, 'conta_financeira_id')));
        $this->assertLinhasCompletas($s->parcelas);
    }
}
