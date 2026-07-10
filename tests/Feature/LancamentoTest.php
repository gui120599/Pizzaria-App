<?php

namespace Tests\Feature;

use App\Enums\Comportamento;
use App\Enums\FormaPagamento;
use App\Enums\Periodicidade;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Http\Requests\LancamentoRequest;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\PlanoReceita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class LancamentoTest extends TestCase
{
    use RefreshDatabase;

    private function planoDespesa(Comportamento $comportamento = Comportamento::Fixo, string $nome = 'Aluguel'): PlanoDespesa
    {
        return PlanoDespesa::create([
            'nome' => $nome,
            'comportamento' => $comportamento,
            'periodicidade' => Periodicidade::Mensal,
        ]);
    }

    public function test_lancamento_a_pagar_copia_snapshot_do_comportamento_do_plano(): void
    {
        $plano = $this->planoDespesa(Comportamento::Fixo);

        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'plano_despesa_id' => $plano->id,
            'descricao' => 'Aluguel de julho',
            'valor' => 2500.00,
            'vencimento' => '2026-07-10',
            'status' => StatusLancamento::Pendente,
        ]);

        // O comportamento não foi informado no lançamento; deve ter sido copiado do plano.
        $this->assertSame(Comportamento::Fixo, $lancamento->refresh()->comportamento);
    }

    public function test_snapshot_do_comportamento_nao_e_sobrescrito_ao_reclassificar_o_plano(): void
    {
        $plano = $this->planoDespesa(Comportamento::Variavel, 'Gás');

        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'plano_despesa_id' => $plano->id,
            'descricao' => 'Compra de gás',
            'valor' => 400,
            'vencimento' => '2026-07-05',
        ]);
        $this->assertSame(Comportamento::Variavel, $lancamento->comportamento);

        // Reclassifica a conta e edita o lançamento: o snapshot histórico deve permanecer.
        $plano->update(['comportamento' => Comportamento::Fixo]);
        $lancamento->update(['descricao' => 'Compra de gás (editado)']);

        $this->assertSame(Comportamento::Variavel, $lancamento->refresh()->comportamento);
    }

    public function test_lancamento_a_receber_nao_recebe_comportamento(): void
    {
        $plano = PlanoReceita::create(['nome' => 'iFood']);

        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Receber,
            'plano_receita_id' => $plano->id,
            'descricao' => 'Repasse iFood',
            'valor' => 1200,
            'vencimento' => '2026-07-15',
        ]);

        $this->assertNull($lancamento->refresh()->comportamento);
    }

    public function test_marcar_como_pago_atualiza_status_data_e_forma(): void
    {
        $plano = $this->planoDespesa(Comportamento::Fixo, 'Energia elétrica');

        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Pagar,
            'plano_despesa_id' => $plano->id,
            'descricao' => 'Energia elétrica',
            'valor' => 800,
            'vencimento' => '2026-07-08',
        ]);

        $lancamento->marcarComoPago(Carbon::parse('2026-07-08'), FormaPagamento::Pix);

        $lancamento->refresh();
        $this->assertSame(StatusLancamento::Pago, $lancamento->status);
        $this->assertSame('2026-07-08', $lancamento->data_pagamento->toDateString());
        $this->assertSame(FormaPagamento::Pix, $lancamento->forma_pagamento);
    }

    public function test_scope_vencidos_retorna_apenas_pendentes_com_vencimento_passado(): void
    {
        $plano = $this->planoDespesa(Comportamento::Variavel, 'Diversos');

        $cria = fn (array $extra): Lancamento => Lancamento::create(array_merge([
            'tipo' => TipoLancamento::Pagar,
            'plano_despesa_id' => $plano->id,
            'descricao' => 'Lançamento',
            'valor' => 10,
        ], $extra));

        $vencido = $cria(['vencimento' => now()->subDays(3)->toDateString(), 'status' => StatusLancamento::Pendente]);
        $futuro = $cria(['vencimento' => now()->addDays(3)->toDateString(), 'status' => StatusLancamento::Pendente]);
        $pagoVencido = $cria(['vencimento' => now()->subDays(3)->toDateString(), 'status' => StatusLancamento::Pago]);

        $ids = Lancamento::vencidos()->pluck('id');

        $this->assertTrue($ids->contains($vencido->id));
        $this->assertFalse($ids->contains($futuro->id));       // vence no futuro
        $this->assertFalse($ids->contains($pagoVencido->id));  // já pago, não é "vencido"

        // Accessor derivado coerente com o scope.
        $this->assertTrue($vencido->estaVencido);
        $this->assertFalse($futuro->estaVencido);
    }

    public function test_regra_tipo_plano_pagar_exige_despesa_e_proibe_receita(): void
    {
        $despesa = $this->planoDespesa(Comportamento::Fixo, 'Conta de despesa');
        $receita = PlanoReceita::create(['nome' => 'Venda balcão']);

        $rules = (new LancamentoRequest)->rules();
        $messages = (new LancamentoRequest)->messages();

        // (1) pagar SEM plano de despesa -> inválido
        $semPlano = Validator::make([
            'tipo' => 'pagar',
            'descricao' => 'x', 'valor' => 10, 'vencimento' => '2026-07-10', 'status' => 'pendente',
        ], $rules, $messages);
        $this->assertTrue($semPlano->fails());
        $this->assertArrayHasKey('plano_despesa_id', $semPlano->errors()->messages());

        // (2) pagar COM plano de receita preenchido -> proibido
        $comReceita = Validator::make([
            'tipo' => 'pagar',
            'plano_despesa_id' => $despesa->id,
            'plano_receita_id' => $receita->id,
            'descricao' => 'x', 'valor' => 10, 'vencimento' => '2026-07-10', 'status' => 'pendente',
        ], $rules, $messages);
        $this->assertTrue($comReceita->fails());
        $this->assertArrayHasKey('plano_receita_id', $comReceita->errors()->messages());

        // (3) pagar correto -> válido
        $valido = Validator::make([
            'tipo' => 'pagar',
            'plano_despesa_id' => $despesa->id,
            'descricao' => 'x', 'valor' => 10, 'vencimento' => '2026-07-10', 'status' => 'pendente',
        ], $rules, $messages);
        $this->assertFalse($valido->fails());
    }
}
