<?php

namespace Tests\Feature;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\StatusFechamentoCaixa;
use App\Exceptions\FinanceiroException;
use App\Filament\Resources\FechamentosCaixa\Pages\EditFechamentoCaixa;
use App\Models\Caixa;
use App\Models\ContaFinanceira;
use App\Models\FechamentoCaixa;
use App\Models\MovimentacaoFinanceira;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\NotaMoeda;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Services\RelatorioFechamentoCaixaService;
use App\Services\SessaoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Troco inicial de implantação na abertura, contagem de notas do troco no
 * fechamento e o bloco de troco/recolhimento do relatório.
 */
class TrocoInicialEContagemTrocoTest extends TestCase
{
    use RefreshDatabase;

    private ContaFinanceira $caixaPdv;

    private ContaFinanceira $cofre;

    private Caixa $caixa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->caixaPdv = ContaFinanceira::factory()->caixaPdv()->create();
        $this->cofre = ContaFinanceira::factory()->cofre()->comSaldo(500)->create();
        $this->caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
    }

    private function sessao(float $saldoInicial, string $status = 'ABERTA'): SessaoCaixa
    {
        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $this->caixa->id,
            'sessaocaixa_status' => $status,
            'sessaocaixa_data_hora_abertura' => now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => $status === 'FECHADA' ? now() : null,
            'sessaocaixa_saldo_inicial' => $saldoInicial,
            'sessaocaixa_saldo_final' => $saldoInicial,
            'sessaocaixa_user_id' => User::factory()->create(['name_first' => 'Operador'])->id,
        ]);
    }

    public function test_troco_inicial_sem_fechamento_anterior_entra_por_transferencia_da_conta_escolhida(): void
    {
        $sessao = $this->sessao(100);

        app(SessaoCaixaService::class)->lancarTrocoInicial($sessao, $this->cofre->id);

        $this->assertSame(100.0, (float) $this->caixaPdv->fresh()->saldo_atual);
        $this->assertSame(400.0, (float) $this->cofre->fresh()->saldo_atual);
        $this->assertSame(0, MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessao->id)->count());
    }

    public function test_ajuste_de_implantacao_entra_sem_origem_e_so_para_admin(): void
    {
        $sessao = $this->sessao(80);

        app(SessaoCaixaService::class)->lancarTrocoInicial($sessao, null, ajusteImplantacao: true);

        $this->assertSame(80.0, (float) $this->caixaPdv->fresh()->saldo_atual);
        $this->assertSame(NaturezaMovimentacao::AjusteSaldo, MovimentacaoFinanceira::firstOrFail()->natureza);

        $this->actingAs(User::factory()->create(['name_first' => 'Operador']));
        $this->expectException(FinanceiroException::class);

        app(SessaoCaixaService::class)->lancarTrocoInicial($this->sessao(50), null, ajusteImplantacao: true);
    }

    public function test_caixa_com_fechamento_anterior_nao_lanca_troco_inicial(): void
    {
        $anterior = $this->sessao(0, 'FECHADA');
        FechamentoCaixa::create([
            'sessao_caixa_id' => $anterior->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Confirmado,
            'confirmado_em' => now()->subDay(),
            'valor_troco_gaveta' => 100,
        ]);

        app(SessaoCaixaService::class)->lancarTrocoInicial($this->sessao(100), $this->cofre->id);

        $this->assertSame(0, MovimentacaoFinanceira::count());
    }

    public function test_acao_contar_notas_define_o_troco_do_fechamento(): void
    {
        $nota = NotaMoeda::create(['descricao' => 'R$ 50,00', 'valor' => 50, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);
        $moeda = NotaMoeda::create(['descricao' => 'R$ 1,00', 'valor' => 1, 'tipo' => 'moeda', 'ordem_exibicao' => 2]);
        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $this->sessao(0, 'FECHADA')->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);

        Livewire::test(EditFechamentoCaixa::class, ['record' => $fechamento->getKey()])
            ->callFormComponentAction('valor_troco_gaveta', 'contarTroco', data: [
                'notas' => [
                    ['nota_moeda_id' => $nota->id, 'descricao' => $nota->descricao, 'quantidade' => 2],
                    ['nota_moeda_id' => $moeda->id, 'descricao' => $moeda->descricao, 'quantidade' => 7],
                ],
            ])
            ->assertHasNoFormComponentActionErrors()
            ->assertSchemaStateSet(['valor_troco_gaveta' => '107,00']);
    }

    public function test_relatorio_lista_troco_e_recolhimento_por_sessao(): void
    {
        $sessao = $this->sessao(0, 'FECHADA');
        FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Confirmado,
            'confirmado_em' => now(),
            'valor_troco_gaveta' => 100,
            'valor_recolhido' => 250,
            'recolhimento_conta_id' => $this->cofre->id,
        ]);

        $linhas = (new RelatorioFechamentoCaixaService(['sessoes' => [$sessao->id]]))->trocoERecolhimento();

        $this->assertCount(1, $linhas);
        $this->assertSame(['sessao_id' => $sessao->id, 'troco' => 100.0, 'recolhido' => 250.0, 'conta' => $this->cofre->nome], $linhas->first());
    }
}
