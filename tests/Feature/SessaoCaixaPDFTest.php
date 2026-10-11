<?php

namespace Tests\Feature;

use App\Enums\FormaPagamento;
use App\Enums\FormaTrocoEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Caixa;
use App\Models\Lancamento;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessaoCaixaPDFTest extends TestCase
{
    use RefreshDatabase;

    public function test_saldo_final_e_o_esperado_do_fechamento_sem_fiado_e_sem_troco_em_dobro(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $sessao = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => Caixa::create(['caixa_nome' => 'Caixa 1'])->id,
            'sessaocaixa_status' => 'FECHADA',
            'sessaocaixa_data_hora_abertura' => now()->subHours(6),
            'sessaocaixa_saldo_inicial' => 100,
            'sessaocaixa_user_id' => auth()->id(),
        ]);
        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $sessao->id,
            'mov_descricao' => 'Abertura',
            'mov_tipo' => 'ENTRADA',
            'mov_forma_pagamento' => FormaPagamento::Dinheiro,
            'mov_valor' => 100,
        ]);
        $dinheiro = OpcoesPagamento::create(['opcaopag_nome' => 'Dinheiro', 'opcaopag_desc_nfe' => 'cash']);
        $paga = Venda::create(['venda_sessao_caixa_id' => $sessao->id, 'venda_status' => 'FINALIZADA', 'venda_valor_total' => 69.90, 'venda_valor_pago' => 69.90, 'venda_valor_troco' => 30.10]);
        PagamentosVenda::create([
            'pg_venda_venda_id' => $paga->id,
            'pg_venda_opcaopagamento_id' => $dinheiro->id,
            'pg_venda_valor_pagamento' => 69.90,
            'pg_venda_valor_troco' => 30.10,
            'pg_venda_forma_troco' => FormaTrocoEnum::Dinheiro,
        ]);
        $aPrazo = Venda::create(['venda_sessao_caixa_id' => $sessao->id, 'venda_status' => 'FINALIZADA', 'venda_valor_total' => 500, 'venda_valor_pago' => 0]);
        Lancamento::create(['tipo' => TipoLancamento::Receber, 'descricao' => 'Fiado', 'venda_id' => $aPrazo->id, 'valor' => 500, 'vencimento' => now()->addDays(30), 'status' => StatusLancamento::Pendente]);
        Lancamento::create(['tipo' => TipoLancamento::Receber, 'descricao' => 'Fiado antigo', 'valor' => 40, 'vencimento' => now(), 'status' => StatusLancamento::Pendente])
            ->registrarPagamento(40, forma: FormaPagamento::Dinheiro, sessaoCaixaId: $sessao->id);

        $this->get(route('sessaoCaixa.imprimir', ['id' => $sessao->id]))
            ->assertOk()
            ->assertSeeTextInOrder(['(-) A prazo / fiado', 'R$ 500,00'])
            ->assertSeeTextInOrder(['Saldo Final Esperado', 'R$ 100,00', 'R$ 30,10', 'R$ 40,00', 'R$ 100,00', 'R$ 0,00', 'R$ 0,00', 'R$ 209,90']);
    }
}
