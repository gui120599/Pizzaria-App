<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Caixa;
use App\Models\FechamentoCaixa;
use App\Models\Lancamento;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\PlanoReceita;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use App\Services\ImportacaoCaixaReceberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportacaoCaixaReceberServiceTest extends TestCase
{
    use RefreshDatabase;

    private ImportacaoCaixaReceberService $service;

    private SessaoCaixa $sessao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->service = app(ImportacaoCaixaReceberService::class);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);

        $this->sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 100,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    private function opcao(string $descNfe, ?int $planoReceitaId = null): OpcoesPagamento
    {
        return OpcoesPagamento::create([
            'opcaopag_nome' => $descNfe,
            'opcaopag_desc_nfe' => $descNfe,
            'plano_receita_id' => $planoReceitaId,
        ]);
    }

    private function venda(): Venda
    {
        return Venda::create([
            'venda_sessao_caixa_id' => $this->sessao->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => 0,
        ]);
    }

    private function pagamento(Venda $venda, OpcoesPagamento $opcao, float $valor): PagamentosVenda
    {
        return PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => $valor,
        ]);
    }

    private function planoReceita(string $nome): PlanoReceita
    {
        return PlanoReceita::create(['nome' => $nome]);
    }

    private function fechamentoConfirmado(): FechamentoCaixa
    {
        return FechamentoCaixa::create([
            'sessao_caixa_id' => $this->sessao->id,
            'user_id' => $this->sessao->sessaocaixa_user_id,
            'status' => StatusFechamentoCaixa::Confirmado,
            'confirmado_em' => now(),
        ]);
    }

    public function test_importa_gera_lancamento_receber_por_opcao_de_pagamento_ja_pago(): void
    {
        $plano = $this->planoReceita('Balcão / Retirada');
        $dinheiro = $this->opcao('cash', $plano->id);
        $pix = $this->opcao('InstantPayment', $plano->id);
        $venda = $this->venda();
        $this->pagamento($venda, $dinheiro, 100.0);
        $this->pagamento($venda, $pix, 50.0);
        $this->fechamentoConfirmado();

        $lancamentos = $this->service->importar($this->sessao);

        $this->assertCount(2, $lancamentos);
        foreach ($lancamentos as $lancamento) {
            $this->assertSame(TipoLancamento::Receber, $lancamento->tipo);
            $this->assertSame($this->sessao->id, $lancamento->sessao_caixa_id);
            $this->assertSame($plano->id, $lancamento->plano_receita_id);
            $this->assertSame(StatusLancamento::Pago, $lancamento->status);
            $this->assertSame(1, $lancamento->pagamentos()->count());
        }
        $this->assertEqualsWithDelta(150.0, (float) $lancamentos->sum('valor'), 0.01);
    }

    public function test_nao_importa_fechamento_em_rascunho(): void
    {
        $dinheiro = $this->opcao('cash');
        $this->pagamento($this->venda(), $dinheiro, 100.0);
        FechamentoCaixa::create([
            'sessao_caixa_id' => $this->sessao->id,
            'user_id' => $this->sessao->sessaocaixa_user_id,
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->importar($this->sessao);
    }

    public function test_nao_importa_sessao_sem_receita_de_vendas(): void
    {
        $this->fechamentoConfirmado();

        $this->expectException(ValidationException::class);

        $this->service->importar($this->sessao);
    }

    public function test_segunda_chamada_de_importar_lanca_validation_idempotencia(): void
    {
        $dinheiro = $this->opcao('cash');
        $this->pagamento($this->venda(), $dinheiro, 100.0);
        $this->fechamentoConfirmado();

        $this->service->importar($this->sessao);

        $this->expectException(ValidationException::class);
        $this->service->importar($this->sessao);
    }

    public function test_importar_pendentes_ignora_ja_importadas_e_nao_confirmadas(): void
    {
        $dinheiro = $this->opcao('cash');
        $this->pagamento($this->venda(), $dinheiro, 100.0);
        $this->fechamentoConfirmado();
        $this->service->importar($this->sessao);

        // Segunda sessão, ainda sem fechamento confirmado.
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 2']);
        $outraSessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(4),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $this->sessao->sessaocaixa_user_id,
        ]);
        $this->pagamento(Venda::create([
            'venda_sessao_caixa_id' => $outraSessao->id,
            'venda_status' => 'FINALIZADA',
            'venda_valor_total' => 0,
        ]), $dinheiro, 999.0);

        $importados = $this->service->importarPendentes();

        $this->assertCount(0, $importados);
    }

    public function test_estornar_importacao_apaga_lancamentos_sem_pagamento_extra(): void
    {
        $dinheiro = $this->opcao('cash');
        $this->pagamento($this->venda(), $dinheiro, 100.0);
        $this->fechamentoConfirmado();
        $this->service->importar($this->sessao);

        $apagados = $this->service->estornarImportacao($this->sessao);

        $this->assertSame(1, $apagados);
        $this->assertSame(0, Lancamento::where('sessao_caixa_id', $this->sessao->id)->count());
    }

    public function test_estornar_bloqueado_quando_ha_pagamento_alem_do_automatico(): void
    {
        $dinheiro = $this->opcao('cash');
        $this->pagamento($this->venda(), $dinheiro, 100.0);
        $this->fechamentoConfirmado();
        $lancamento = $this->service->importar($this->sessao)->first();
        $lancamento->update(['valor' => 200.0]);
        $lancamento->registrarPagamento(100.0);

        $this->expectException(ValidationException::class);

        $this->service->estornarImportacao($this->sessao);
    }

    public function test_valor_vem_da_receita_de_vendas_nao_do_snapshot_esperado(): void
    {
        $dinheiro = $this->opcao('cash');
        $this->pagamento($this->venda(), $dinheiro, 100.0);

        // Saldo inicial de abertura (mov_motivo nulo) — não é receita.
        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $this->sessao->id,
            'mov_descricao' => 'Saldo inicial de abertura',
            'mov_tipo' => 'ENTRADA',
            'mov_forma_pagamento' => FormaPagamento::Dinheiro,
            'mov_valor' => 500.0,
        ]);

        // Recebimento de fiado nesta sessão — já é pagamento de um Lancamento próprio.
        $fiado = Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'descricao' => 'Fiado',
            'valor' => 1000,
            'vencimento' => now()->addDays(7),
            'status' => StatusLancamento::Pendente,
        ]);
        $fiado->registrarPagamento(60, forma: FormaPagamento::Dinheiro, sessaoCaixaId: $this->sessao->id);

        $this->fechamentoConfirmado();

        $lancamentos = $this->service->importar($this->sessao);

        $this->assertEqualsWithDelta(100.0, (float) $lancamentos->sum('valor'), 0.01);
    }

    public function test_usa_plano_receita_padrao_quando_opcao_sem_plano_configurado(): void
    {
        $padrao = $this->planoReceita('Outras Receitas');
        $dinheiro = $this->opcao('cash'); // sem plano_receita_id
        $this->pagamento($this->venda(), $dinheiro, 100.0);
        $this->fechamentoConfirmado();

        $lancamento = $this->service->importar($this->sessao)->first();

        $this->assertSame($padrao->id, $lancamento->plano_receita_id);
    }
}
