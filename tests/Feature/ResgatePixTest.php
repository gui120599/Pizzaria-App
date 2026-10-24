<?php

namespace Tests\Feature;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\PrestadorCategoriaEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Enums\TipoMovimentoCarteiraEnum;
use App\Exceptions\CarteiraInfluenciadorException;
use App\Exceptions\FinanceiroException;
use App\Filament\Resources\Influenciadores\Pages\EditInfluenciador;
use App\Filament\Resources\Influenciadores\RelationManagers\CarteiraRelationManager;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\Influenciador;
use App\Models\Lancamento;
use App\Models\MovimentacaoFinanceira;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use App\Models\ResgateCarteira;
use App\Models\User;
use App\Services\CarteiraInfluenciadorService;
use App\Services\ResgatePixService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** F2.3: resgate do saldo em Pix → despesa de marketing paga + débito da carteira. */
class ResgatePixTest extends TestCase
{
    use RefreshDatabase;

    private Influenciador $influenciador;

    private ContaFinanceira $contaPix;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $this->actingAs($this->gerente);

        $this->contaPix = ContaFinanceira::factory()->banco()->comSaldo(1000)->create(['nome' => 'Banco Pix']);

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_influ_plano_despesa_id' => PlanoDespesa::create(['nome' => 'Comissões de influenciadores', 'comportamento' => 'variavel', 'periodicidade' => 'mensal'])->id,
            'empresa_influ_conta_pix_id' => $this->contaPix->id,
        ]);

        $this->influenciador = Influenciador::create([
            'influenciador_nome' => 'Maria Souza',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
            'influenciador_cpf' => '529.982.247-25',
            'influenciador_chave_pix' => 'maria@teste.com',
        ]);
        $this->influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        app(CarteiraInfluenciadorService::class)->lancar($this->influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 100);
    }

    public function test_resgate_gera_despesa_paga_movimentacao_e_debito_uma_unica_vez(): void
    {
        $resgate = app(ResgatePixService::class)->pagar($this->influenciador, 60, $this->gerente, 'comprovantes_resgate/pix.pdf');

        $titulo = Lancamento::findOrFail($resgate->rc_lancamento_id);
        $this->assertSame(TipoLancamento::Pagar, $titulo->tipo);
        $this->assertSame(StatusLancamento::Pago, $titulo->status);
        $this->assertSame('60.00', $titulo->valor);

        $movimentacoes = MovimentacaoFinanceira::where('lancamento_id', $titulo->id)->get();
        $this->assertCount(1, $movimentacoes);
        $this->assertSame(NaturezaMovimentacao::PagamentoLancamento, $movimentacoes->first()->natureza);
        $this->assertSame(940.0, (float) $this->contaPix->fresh()->saldo_atual);

        $this->assertSame(40.0, $this->influenciador->fresh()->saldoCarteira());
        $this->assertSame(1, $this->influenciador->movimentosCarteira()->where('cm_tipo', TipoMovimentoCarteiraEnum::RESGATE_PIX)->count());
        $this->assertSame($this->gerente->id, $resgate->rc_user_id);
    }

    public function test_cria_o_prestador_pf_autonomo_com_cpf_e_pix_uma_unica_vez(): void
    {
        app(ResgatePixService::class)->pagar($this->influenciador, 10, $this->gerente);
        app(ResgatePixService::class)->pagar($this->influenciador, 10, $this->gerente);

        $prestador = Prestador::sole();
        $this->assertSame(PrestadorCategoriaEnum::AUTONOMO, $prestador->categoria);
        $this->assertSame('Maria Souza', $prestador->nome);
        $this->assertSame('52998224725', $prestador->cpf_cnpj);
        $this->assertSame($prestador->id, $this->influenciador->fresh()->influenciador_prestador_id);
        $this->assertSame(2, Lancamento::where('favorecido_id', $prestador->id)->count());
    }

    public function test_valor_acima_do_saldo_nao_grava_nada(): void
    {
        try {
            app(ResgatePixService::class)->pagar($this->influenciador, 150, $this->gerente);
            $this->fail('Deveria recusar valor acima do saldo.');
        } catch (CarteiraInfluenciadorException) {
        }

        $this->assertSame(100.0, $this->influenciador->fresh()->saldoCarteira());
        $this->assertSame(0, ResgateCarteira::count());
        $this->assertSame(0, Lancamento::count());
        $this->assertSame(1000.0, (float) $this->contaPix->fresh()->saldo_atual);
    }

    public function test_sem_cpf_ou_pix_ou_configuracao_da_erro_claro(): void
    {
        $semCpf = Influenciador::create(['influenciador_nome' => 'Sem CPF', 'email' => uniqid().'@teste.com', 'password' => 'senha-segura']);
        app(CarteiraInfluenciadorService::class)->lancar($semCpf, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 50);

        try {
            app(ResgatePixService::class)->pagar($semCpf, 10, $this->gerente);
            $this->fail('Deveria exigir CPF e chave Pix.');
        } catch (CarteiraInfluenciadorException $e) {
            $this->assertStringContainsString('CPF e chave Pix', $e->getMessage());
        }
        $this->assertSame(50.0, $semCpf->fresh()->saldoCarteira());

        Empresa::query()->update(['empresa_influ_conta_pix_id' => null]);
        $this->expectException(CarteiraInfluenciadorException::class);
        $this->expectExceptionMessage('Configure o plano de despesa');
        app(ResgatePixService::class)->pagar($this->influenciador, 10, $this->gerente);
    }

    public function test_conta_de_saida_sem_saldo_desfaz_o_resgate_inteiro(): void
    {
        $this->contaPix->update(['saldo_atual' => 5, 'saldo_inicial' => 5]);

        try {
            app(ResgatePixService::class)->pagar($this->influenciador, 60, $this->gerente);
            $this->fail('Deveria recusar: a conta de saída não tem saldo.');
        } catch (FinanceiroException) {
        }

        // A transação desfez o débito e o registro do resgate.
        $this->assertSame(100.0, $this->influenciador->fresh()->saldoCarteira());
        $this->assertSame(0, ResgateCarteira::count());
        $this->assertSame(0, Lancamento::count());
    }

    public function test_comprovante_fica_no_disco_privado_e_recibo_abre_para_quem_tem_permissao(): void
    {
        $resgate = app(ResgatePixService::class)->pagar($this->influenciador, 30, $this->gerente, 'comprovantes_resgate/pix.pdf');

        $this->assertStringNotContainsString('public', (string) $resgate->rc_comprovante_path);
        $this->assertSame('local', config('filesystems.default'));

        $this->get(route('resgates-carteira.recibo', $resgate))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->actingAs(User::factory()->caixa()->create(['name_first' => 'Caixa']));
        $this->get(route('resgates-carteira.recibo', $resgate))->assertForbidden();
    }

    public function test_acao_pagar_saldo_em_pix_no_cadastro_do_influenciador(): void
    {
        Livewire::test(CarteiraRelationManager::class, ['ownerRecord' => $this->influenciador, 'pageClass' => EditInfluenciador::class])
            ->callTableAction('pagarPix', data: ['valor' => 25]);

        $this->assertSame(75.0, $this->influenciador->fresh()->saldoCarteira());
        $this->assertSame(1, ResgateCarteira::count());
    }

    public function test_acao_ajustar_exige_permissao(): void
    {
        $caixa = User::factory()->caixa()->create(['name_first' => 'Caixa']);
        $this->actingAs($caixa);

        Livewire::test(CarteiraRelationManager::class, ['ownerRecord' => $this->influenciador, 'pageClass' => EditInfluenciador::class])
            ->assertTableActionHidden('ajustar')
            ->assertTableActionHidden('pagarPix');
    }
}
