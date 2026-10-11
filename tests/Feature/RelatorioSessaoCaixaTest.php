<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\FormaTrocoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Filament\Pages\RelatorioSessaoCaixa;
use App\Filament\Widgets\SessaoCaixaFiadoWidget;
use App\Filament\Widgets\SessaoCaixaStatsOverview;
use App\Filament\Widgets\SessaoCaixaVendasWidget;
use App\Models\Caixa;
use App\Models\Cliente;
use App\Models\Lancamento;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\RelatorioSessaoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RelatorioSessaoCaixaTest extends TestCase
{
    use RefreshDatabase;

    private SessaoCaixa $sessao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(6),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => auth()->id(),
        ]);
    }

    private function opcao(string $nome, string $descNfe): OpcoesPagamento
    {
        return OpcoesPagamento::firstOrCreate(
            ['opcaopag_nome' => $nome],
            ['opcaopag_desc_nfe' => $descNfe, 'opcaopag_tipo_taxa' => 'N/A', 'opcaopag_valor_percentual_taxa' => 0],
        );
    }

    private function venda(float $total, ?Cliente $cliente = null): Venda
    {
        return Venda::create([
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_cliente_id' => $cliente?->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => $total,
            'venda_valor_pago' => $total,
            'venda_datahora_iniciada' => now(),
            'venda_datahora_finalizada' => now(),
        ]);
    }

    private function pagar(Venda $venda, OpcoesPagamento $opcao, float $valor, float $troco = 0): PagamentosVenda
    {
        return PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => $valor,
            'pg_venda_valor_troco' => $troco,
            'pg_venda_forma_troco' => $troco > 0 ? FormaTrocoEnum::Dinheiro : null,
        ]);
    }

    private function pedido(Venda $venda, PedidoOrigemEnum $origem, ?OpcoesPagamento $formaCombinada = null, ?string $descricao = null, ?string $observacao = null): Pedido
    {
        $pedido = Pedido::create([
            'pedido_status' => 'FINALIZADO',
            'pedido_venda_id' => $venda->id,
            'pedido_origem' => $origem,
            'pedido_descricao_pagamento' => $descricao ?? $formaCombinada?->opcaopag_nome,
            'pedido_observacao_pagamento' => $observacao,
        ]);

        if ($formaCombinada) {
            PagamentosPedido::create([
                'pg_pedido_pedido_id' => $pedido->id,
                'pg_pedido_opcaopagamento_id' => $formaCombinada->id,
                'pg_pedido_opcaopagamento_nome' => $formaCombinada->opcaopag_nome,
                'pg_pedido_valor' => (float) $venda->venda_valor_total,
                'pg_pedido_ordem' => 0,
            ]);
        }

        return $pedido;
    }

    private function fiado(Venda $venda, float $valor, ?Cliente $cliente = null): Lancamento
    {
        $venda->update(['venda_valor_pago' => (float) $venda->venda_valor_total - $valor]);

        return Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'descricao' => "Venda #{$venda->id} - saldo fiado",
            'venda_id' => $venda->id,
            'cliente_id' => $cliente?->id,
            'valor' => $valor,
            'vencimento' => now()->addDays(30),
            'status' => StatusLancamento::Pendente,
        ]);
    }

    /** @return array<int, string> */
    private function tiposDeAviso(Venda $venda, array $filtros = []): array
    {
        return collect((new RelatorioSessaoCaixaService(['sessoes' => [$this->sessao->id], ...$filtros]))
            ->vendas()
            ->firstWhere('venda_id', $venda->id)['avisos'] ?? [])
            ->pluck('tipo')
            ->all();
    }

    public function test_aponta_venda_lancada_em_forma_diferente_da_combinada_no_pedido(): void
    {
        $venda = $this->venda(64.45);
        $this->pagar($venda, $this->opcao('Cartão Crédito', 'creditCard'), 64.45);
        $this->pedido($venda, PedidoOrigemEnum::ATENDENTE, $this->opcao('Cartão Débito', 'debitCard'));

        $this->assertSame([RelatorioSessaoCaixaService::AVISO_FORMA_DIVERGENTE], $this->tiposDeAviso($venda));
    }

    public function test_sem_forma_combinada_compara_pela_descricao_do_pedido(): void
    {
        $venda = $this->venda(64.00);
        $this->pagar($venda, $this->opcao('Cartão Crédito', 'creditCard'), 64.00);
        $this->pedido($venda, PedidoOrigemEnum::CARDAPIO, descricao: 'Cartão Débito');

        $this->assertSame([RelatorioSessaoCaixaService::AVISO_FORMA_DIVERGENTE], $this->tiposDeAviso($venda));
    }

    public function test_nao_aponta_quando_uma_das_formas_lancadas_e_a_do_pedido(): void
    {
        $venda = $this->venda(100.00);
        $pix = $this->opcao('Pix QRCode', 'InstantPayment');
        $this->pagar($venda, $pix, 60.00);
        $this->pagar($venda, $this->opcao('Dinheiro', 'cash'), 40.00);
        $this->pedido($venda, PedidoOrigemEnum::ATENDENTE, $pix);

        $this->assertSame([], $this->tiposDeAviso($venda));
    }

    public function test_aponta_troco_em_forma_que_nao_e_dinheiro_e_nao_em_dinheiro(): void
    {
        $vendaPix = $this->venda(95.70);
        $this->pagar($vendaPix, $this->opcao('PIX CNPJ', 'InstantPayment'), 95.70, 4.30);
        $vendaDinheiro = $this->venda(69.90);
        $this->pagar($vendaDinheiro, $this->opcao('Dinheiro', 'cash'), 69.90, 30.10);

        $this->assertSame([RelatorioSessaoCaixaService::AVISO_TROCO_FORA_DINHEIRO], $this->tiposDeAviso($vendaPix));
        $this->assertSame([], $this->tiposDeAviso($vendaDinheiro));
    }

    public function test_aponta_fiado_de_pedido_com_forma_combinada_ou_observacao_pago(): void
    {
        $comForma = $this->venda(27.00);
        $this->pedido($comForma, PedidoOrigemEnum::ATENDENTE, $this->opcao('Dinheiro', 'cash'));
        $this->fiado($comForma, 27.00);
        $comObservacao = $this->venda(30.00);
        $this->pedido($comObservacao, PedidoOrigemEnum::CARDAPIO, observacao: 'PAGO');
        $this->fiado($comObservacao, 30.00);

        $this->assertSame([RelatorioSessaoCaixaService::AVISO_FIADO_COM_FORMA_NO_PEDIDO], $this->tiposDeAviso($comForma));
        $this->assertSame([RelatorioSessaoCaixaService::AVISO_FIADO_COM_FORMA_NO_PEDIDO], $this->tiposDeAviso($comObservacao));
    }

    public function test_nao_aponta_notinha_com_so_a_descricao_do_pedido(): void
    {
        $venda = $this->venda(85.90);
        $this->pedido($venda, PedidoOrigemEnum::ATENDENTE, descricao: 'Cartão Crédito', observacao: 'NOTINHA, PAGAR NA TERÇA');
        $this->fiado($venda, 85.90);

        $this->assertSame([], $this->tiposDeAviso($venda));
    }

    public function test_filtra_por_forma_origem_cliente_e_situacao(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Marquin', 'cliente_tipo' => 'PF']);
        $debito = $this->opcao('Cartão Débito', 'debitCard');
        $direta = $this->venda(10.00);
        $this->pagar($direta, $debito, 10.00);
        $mesa = $this->venda(20.00);
        $this->pagar($mesa, $this->opcao('Dinheiro', 'cash'), 20.00);
        $this->pedido($mesa, PedidoOrigemEnum::MESA);
        $divergente = $this->venda(30.00);
        $this->pagar($divergente, $this->opcao('Cartão Crédito', 'creditCard'), 30.00);
        $this->pedido($divergente, PedidoOrigemEnum::ATENDENTE, $debito);
        $aPrazo = $this->venda(40.00, $cliente);
        $this->fiado($aPrazo, 40.00, $cliente);

        $ids = fn (array $filtros): array => (new RelatorioSessaoCaixaService(['sessoes' => [$this->sessao->id], ...$filtros]))
            ->vendas()->pluck('venda_id')->all();

        $this->assertSame([$direta->id], $ids(['opcoes' => [$debito->id]]));
        $this->assertSame([$direta->id, $aPrazo->id], $ids(['origem' => RelatorioSessaoCaixaService::ORIGEM_DIRETO]));
        $this->assertSame([$mesa->id], $ids(['origem' => PedidoOrigemEnum::MESA->value]));
        $this->assertSame([$aPrazo->id], $ids(['cliente_id' => $cliente->id]));
        $this->assertSame([$aPrazo->id], $ids(['situacao' => RelatorioSessaoCaixaService::SITUACAO_FIADO]));
        $this->assertSame([$divergente->id], $ids(['situacao' => RelatorioSessaoCaixaService::SITUACAO_DIVERGENCIA]));
    }

    public function test_fiado_gerado_nas_vendas_e_recebido_na_sessao_mesmo_de_venda_antiga(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Marquin', 'cliente_tipo' => 'PF']);
        $this->fiado($this->venda(737.77, $cliente), 737.77, $cliente);
        $antiga = Venda::create(['venda_status' => 'FINALIZADA', 'venda_valor_total' => 86, 'venda_datahora_finalizada' => now()->subMonth()]);
        $this->fiado($antiga, 86.00, $cliente)
            ->registrarPagamento(86.00, forma: FormaPagamento::PixCnpj, sessaoCaixaId: $this->sessao->id);

        $relatorio = new RelatorioSessaoCaixaService(['sessoes' => [$this->sessao->id]]);

        $this->assertSame([737.77], $relatorio->fiadoGerado()->pluck('valor')->all());
        $this->assertSame([[86.0, $antiga->id, 'PIX CNPJ']], $relatorio->fiadoRecebido()->map(fn (array $linha): array => [$linha['valor'], $linha['venda_id'], $linha['forma']])->all());
        $this->assertSame([737.77, 86.0], [$relatorio->resumo()['fiado_gerado'], $relatorio->resumo()['fiado_recebido']]);
    }

    public function test_pagina_e_widgets_renderizam_para_o_gerente_e_a_impressao_para_o_admin(): void
    {
        $venda = $this->venda(95.70);
        $this->pagar($venda, $this->opcao('PIX CNPJ', 'InstantPayment'), 95.70, 4.30);
        $filtros = ['sessoes' => [$this->sessao->id]];
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));

        Livewire::test(RelatorioSessaoCaixa::class)->assertOk();
        Livewire::test(SessaoCaixaStatsOverview::class, ['pageFilters' => $filtros])->assertSeeText('R$ 4,30');
        Livewire::test(SessaoCaixaVendasWidget::class, ['pageFilters' => $filtros])->assertSeeText('Troco de R$ 4,30 em PIX CNPJ');
        Livewire::test(SessaoCaixaFiadoWidget::class, ['pageFilters' => $filtros])->assertOk();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']))
            ->get(route('relatorios.sessao_caixa.imprimir', ['filters' => $filtros]))
            ->assertOk()
            ->assertSeeText('Relatório de Sessão de Caixa')
            ->assertSeeText('Troco de R$ 4,30 em PIX CNPJ');
    }

    public function test_impressao_e_negada_sem_permissao_de_relatorio_financeiro(): void
    {
        $this->actingAs(User::factory()->garcom()->create(['name_first' => 'Garçom']))
            ->get(route('relatorios.sessao_caixa.imprimir'))
            ->assertForbidden();
    }
}
