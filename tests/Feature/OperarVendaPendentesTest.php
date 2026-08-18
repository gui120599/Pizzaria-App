<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\CartoesPagamento;
use App\Models\Cliente;
use App\Models\Lancamento;
use App\Models\LancamentoPagamento;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperarVendaPendentesTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixa $sessaoCaixa;

    private Venda $venda;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $this->sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        $this->cliente = Cliente::create(['cliente_nome' => 'João', 'cliente_tipo' => 'PF', 'cliente_limite_credito' => 200]);

        $this->venda = Venda::create([
            'venda_status' => 'FINALIZADA',
            'venda_sessao_caixa_id' => $this->sessaoCaixa->id,
            'venda_cliente_id' => $this->cliente->id,
            'venda_valor_total' => 100,
            'venda_valor_pago' => 40,
            'venda_valor_troco' => 0,
        ]);
    }

    private function lancamentoFiado(float $valor = 60): Lancamento
    {
        return Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'venda_id' => $this->venda->id,
            'cliente_id' => $this->cliente->id,
            'descricao' => "Venda #{$this->venda->id} - saldo fiado",
            'valor' => $valor,
            'vencimento' => now()->addDays(7),
            'status' => StatusLancamento::Pendente,
        ]);
    }

    public function test_aba_pendentes_lista_apenas_titulos_originados_de_venda(): void
    {
        $lancamentoDaVenda = $this->lancamentoFiado();

        // Título a receber avulso, cadastrado direto no Financeiro (sem venda_id) — não deve aparecer no PDV.
        Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'cliente_id' => $this->cliente->id,
            'descricao' => 'Recebível avulso',
            'valor' => 500,
            'vencimento' => now()->addDays(7),
            'status' => StatusLancamento::Pendente,
        ]);

        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id]);

        $component = Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->set('abaAtiva', 'pendentes');

        $pendentes = $component->get('pendentes');

        $this->assertCount(1, $pendentes);
        $this->assertSame($lancamentoDaVenda->id, $pendentes->first()->id);
    }

    public function test_registrar_recebimento_quita_o_titulo_e_reflete_na_lista(): void
    {
        $lancamento = $this->lancamentoFiado(60);
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id]);

        Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->call('abrirModalRecebimento', $lancamento->id)
            ->assertSet('valorRecebimento', 60.0)
            ->call('confirmarRecebimento')
            ->assertSet('modalRecebimentoAberto', false);

        $lancamentoFresh = $lancamento->fresh();
        $this->assertSame('pago', $lancamentoFresh->status->value);
        $this->assertSame(0.0, $lancamentoFresh->valor_restante);
    }

    public function test_registrar_recebimento_parcial_mantem_titulo_como_parcial(): void
    {
        $lancamento = $this->lancamentoFiado(60);
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id]);

        Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->call('abrirModalRecebimento', $lancamento->id)
            ->set('valorRecebimento', 20)
            ->call('confirmarRecebimento');

        $lancamentoFresh = $lancamento->fresh();
        $this->assertSame('parcial', $lancamentoFresh->status->value);
        $this->assertSame(40.0, $lancamentoFresh->valor_restante);
    }

    public function test_busca_filtra_pendentes_por_nome_do_cliente(): void
    {
        $this->lancamentoFiado();

        $outroCliente = Cliente::create(['cliente_nome' => 'Maria', 'cliente_tipo' => 'PF', 'cliente_limite_credito' => 200]);
        $outraVenda = Venda::create([
            'venda_status' => 'FINALIZADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id,
            'venda_cliente_id' => $outroCliente->id, 'venda_valor_total' => 50, 'venda_valor_pago' => 0,
        ]);
        Lancamento::create([
            'tipo' => TipoLancamento::Receber, 'venda_id' => $outraVenda->id, 'cliente_id' => $outroCliente->id,
            'descricao' => 'fiado', 'valor' => 50, 'vencimento' => now()->addDays(7), 'status' => StatusLancamento::Pendente,
        ]);

        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id]);

        $pendentes = Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->set('abaAtiva', 'pendentes')
            ->set('buscaPendentes', 'Maria')
            ->get('pendentes');

        $this->assertCount(1, $pendentes);
        $this->assertSame($outroCliente->id, $pendentes->first()->cliente_id);
    }

    public function test_busca_filtra_pendentes_por_numero_da_venda(): void
    {
        $lancamento = $this->lancamentoFiado();
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id]);

        $pendentes = Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->set('abaAtiva', 'pendentes')
            ->set('buscaPendentes', (string) $this->venda->id)
            ->get('pendentes');

        $this->assertCount(1, $pendentes);
        $this->assertSame($lancamento->id, $pendentes->first()->id);
    }

    public function test_modal_de_recebimento_lista_pagamentos_ja_registrados(): void
    {
        $lancamento = $this->lancamentoFiado(100);
        $lancamento->registrarPagamento(30, forma: FormaPagamento::Pix);

        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id]);

        $historico = Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->call('abrirModalRecebimento', $lancamento->id)
            ->get('pagamentosDoLancamentoEmRecebimento');

        $this->assertCount(1, $historico);
        $this->assertSame(30.0, (float) $historico->first()->valor);
    }

    public function test_recebimento_via_cartao_registra_bandeira_e_autorizacao(): void
    {
        $lancamento = $this->lancamentoFiado(60);
        $cartao = CartoesPagamento::create(['cartao_bandeira' => 'Visa']);
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id]);

        Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->call('abrirModalRecebimento', $lancamento->id)
            ->set('formaRecebimento', FormaPagamento::CartaoCredito->value)
            ->set('cartaoRecebimentoId', $cartao->id)
            ->set('numeroAutorizacaoRecebimento', '123456')
            ->call('confirmarRecebimento');

        $pagamento = LancamentoPagamento::where('lancamento_id', $lancamento->id)->first();
        $this->assertSame($cartao->id, $pagamento->cartao_id);
        $this->assertSame('123456', $pagamento->numero_autorizacao_cartao);
    }

    public function test_recebimento_em_dinheiro_nao_grava_dados_de_cartao_mesmo_se_informados(): void
    {
        $lancamento = $this->lancamentoFiado(60);
        $cartao = CartoesPagamento::create(['cartao_bandeira' => 'Visa']);
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id]);

        Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->call('abrirModalRecebimento', $lancamento->id)
            ->set('formaRecebimento', FormaPagamento::Dinheiro->value)
            ->set('cartaoRecebimentoId', $cartao->id)
            ->set('numeroAutorizacaoRecebimento', '123456')
            ->call('confirmarRecebimento');

        $pagamento = LancamentoPagamento::where('lancamento_id', $lancamento->id)->first();
        $this->assertNull($pagamento->cartao_id);
        $this->assertNull($pagamento->numero_autorizacao_cartao);
    }

    public function test_recebimento_pelo_pdv_entra_na_sessao_de_caixa_aberta(): void
    {
        $lancamento = $this->lancamentoFiado(60);
        $venda = Venda::create(['venda_status' => 'INICIADA', 'venda_sessao_caixa_id' => $this->sessaoCaixa->id]);

        Livewire::test(OperarVenda::class, ['venda' => $venda])
            ->call('abrirModalRecebimento', $lancamento->id)
            ->call('confirmarRecebimento');

        $pagamento = LancamentoPagamento::where('lancamento_id', $lancamento->id)->first();
        $this->assertNotNull($pagamento);
        $this->assertSame($this->sessaoCaixa->id, $pagamento->sessao_caixa_id);
    }
}
