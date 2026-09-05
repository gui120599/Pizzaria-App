<?php

namespace Tests\Feature;

use App\Enums\StatusFechamentoCaixa;
use App\Filament\Resources\FechamentosCaixa\FechamentoCaixaResource;
use App\Filament\Resources\FechamentosCaixa\Pages\CreateFechamentoCaixa;
use App\Filament\Resources\FechamentosCaixa\Pages\EditFechamentoCaixa;
use App\Models\Caixa;
use App\Models\FechamentoCaixa;
use App\Models\NotaMoeda;
use App\Models\SessaoCaixa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FechamentoCaixaFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    private function sessaoFechada(): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(8),
            'sessaocaixa_data_hora_fechamento' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    public function test_cria_fechamento_em_rascunho_para_sessao_fechada(): void
    {
        $sessao = $this->sessaoFechada();

        Livewire::test(CreateFechamentoCaixa::class)
            ->fillForm(['sessao_caixa_id' => $sessao->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $fechamento = FechamentoCaixa::where('sessao_caixa_id', $sessao->id)->firstOrFail();
        $this->assertSame(StatusFechamentoCaixa::Rascunho, $fechamento->status);
    }

    /**
     * A criação já pré-carrega uma linha por cédula/moeda do catálogo (quantidade=0) —
     * o operador não escolhe/adiciona/remove linhas, só digita a quantidade contada.
     */
    public function test_criacao_pre_carrega_todas_as_cedulas_moedas_do_catalogo(): void
    {
        $sessao = $this->sessaoFechada();
        $notaCem = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 2]);
        $notaCinquenta = NotaMoeda::create(['descricao' => 'R$ 50,00', 'valor' => 50, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);

        Livewire::test(CreateFechamentoCaixa::class)
            ->fillForm(['sessao_caixa_id' => $sessao->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $fechamento = FechamentoCaixa::where('sessao_caixa_id', $sessao->id)->firstOrFail();

        $this->assertSame(2, $fechamento->notas()->count());
        $this->assertEqualsWithDelta(0.0, $fechamento->totalDinheiroContado, 0.01);
        $this->assertTrue($fechamento->notas()->where('nota_moeda_id', $notaCem->id)->exists());
        $this->assertTrue($fechamento->notas()->where('nota_moeda_id', $notaCinquenta->id)->exists());
    }

    public function test_repeater_de_notas_calcula_total_dinheiro_contado(): void
    {
        $sessao = $this->sessaoFechada();
        $notaCem = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 10]);
        $notaCinquenta = NotaMoeda::create(['descricao' => 'R$ 50,00', 'valor' => 50, 'tipo' => 'cedula', 'ordem_exibicao' => 9]);

        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);

        Livewire::test(EditFechamentoCaixa::class, ['record' => $fechamento->getKey()])
            ->fillForm([
                'notas' => [
                    ['nota_moeda_id' => $notaCem->id, 'quantidade' => 3],
                    ['nota_moeda_id' => $notaCinquenta->id, 'quantidade' => 2],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fechamento->refresh();
        // 3x100 + 2x50 = 400
        $this->assertEqualsWithDelta(400.0, $fechamento->totalDinheiroContado, 0.01);
    }

    /**
     * Modo "Valor total": o operador digita o valor total que já sabe que tem
     * daquela cédula, sem contar uma a uma — o form calcula a quantidade
     * implícita ao vivo (ContagemNotasSchema::sincroniza), e o hook saving()
     * do model corrige o valor_total final pro múltiplo exato da cédula,
     * mesmo que o valor digitado não seja (247 → 5 notas de R$50 → 250).
     */
    public function test_modo_valor_total_calcula_quantidade_e_corrige_valor_final(): void
    {
        $sessao = $this->sessaoFechada();
        $notaCinquenta = NotaMoeda::create(['descricao' => 'R$ 50,00', 'valor' => 50, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);

        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);

        $component = Livewire::test(EditFechamentoCaixa::class, ['record' => $fechamento->getKey()]);

        $component
            ->set('data.notas.0.nota_moeda_id', $notaCinquenta->id)
            ->set('data.notas.0.modo', 'valor_total')
            ->set('data.notas.0.valor_total', '247,00')
            ->assertSet('data.notas.0.quantidade', 5)
            ->call('save')
            ->assertHasNoFormErrors();

        $fechamento->refresh();
        $nota = $fechamento->notas()->where('nota_moeda_id', $notaCinquenta->id)->firstOrFail();

        $this->assertSame(5, $nota->quantidade);
        $this->assertEqualsWithDelta(250.0, (float) $nota->valor_total, 0.01);
    }

    /**
     * Regressão: o campo "Valor total" (Money, sempre visível ao lado da
     * Quantidade — ver nota em ContagemNotasSchema) ficava travado em
     * "0,00" quando o operador contava pela Quantidade (o modo padrão),
     * mesmo com o Subtotal calculando certo. sincronizarValorTotal() espelha
     * a mesma sincronização que já existia no sentido Valor total -> Quantidade.
     */
    public function test_editar_quantidade_sincroniza_o_campo_valor_total(): void
    {
        $sessao = $this->sessaoFechada();
        $notaCem = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);

        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Rascunho,
        ]);

        Livewire::test(EditFechamentoCaixa::class, ['record' => $fechamento->getKey()])
            ->set('data.notas.0.nota_moeda_id', $notaCem->id)
            ->set('data.notas.0.quantidade', 3)
            ->assertSet('data.notas.0.valor_total', '300,00');
    }

    /**
     * Confirmado bloqueia edição/exclusão direta (mesmo padrão de
     * LancamentoResource::canEdit/canDelete quando status=Pago) — a página de
     * edição fica inacessível (403), não só com campos desabilitados.
     */
    public function test_fechamento_confirmado_trava_edicao_e_exclusao(): void
    {
        $sessao = $this->sessaoFechada();

        $fechamento = FechamentoCaixa::create([
            'sessao_caixa_id' => $sessao->id,
            'user_id' => auth()->id(),
            'status' => StatusFechamentoCaixa::Confirmado,
            'confirmado_em' => now(),
        ]);

        $this->assertFalse(FechamentoCaixaResource::canEdit($fechamento));
        $this->assertFalse(FechamentoCaixaResource::canDelete($fechamento));
    }
}
