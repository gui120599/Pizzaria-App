<?php

namespace Tests\Feature;

use App\Models\Caixa;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\NotaMoeda;
use App\Models\SessaoCaixa;
use App\Models\SessaoCaixaNota;
use App\Models\User;
use App\Services\SessaoCaixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testa SessaoCaixaService::finalizarAbertura() diretamente (mesma lógica que
 * CreateSessaoCaixa::afterCreate() roda depois que o Repeater 'notas' já
 * persistiu as linhas) — evita depender do Livewire para simular a digitação
 * no repeater de notas, que não tem um jeito confiável de testar via
 * fillForm/set em página de criação com valores padrão pré-carregados.
 */
class CreateSessaoCaixaTest extends TestCase
{
    use RefreshDatabase;

    private function sessaoAberta(): SessaoCaixa
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);

        return SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_saldo_final' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);
    }

    public function test_finalizar_abertura_soma_notas_para_saldo_inicial(): void
    {
        $sessao = $this->sessaoAberta();
        $notaCem = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);
        $notaCinquenta = NotaMoeda::create(['descricao' => 'R$ 50,00', 'valor' => 50, 'tipo' => 'cedula', 'ordem_exibicao' => 2]);

        SessaoCaixaNota::create(['sessao_caixa_id' => $sessao->id, 'nota_moeda_id' => $notaCem->id, 'quantidade' => 2]);
        SessaoCaixaNota::create(['sessao_caixa_id' => $sessao->id, 'nota_moeda_id' => $notaCinquenta->id, 'quantidade' => 1]);

        app(SessaoCaixaService::class)->finalizarAbertura($sessao);
        $sessao->refresh();

        // 2x100 + 1x50 = 250
        $this->assertEqualsWithDelta(250.0, (float) $sessao->sessaocaixa_saldo_inicial, 0.01);
        $this->assertEqualsWithDelta(250.0, (float) $sessao->sessaocaixa_saldo_final, 0.01);
    }

    /**
     * Débito/crédito/Pix da abertura ficam só como carryover por maquininha
     * (ver FechamentoCaixa::totalPorCategoria) — não viram movimento/esperado.
     * Só o dinheiro (fungível, sem como distinguir troco de venda) entra como
     * movimento de sessão.
     */
    public function test_finalizar_abertura_so_registra_movimento_de_dinheiro(): void
    {
        $sessao = $this->sessaoAberta();
        $nota = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);
        SessaoCaixaNota::create(['sessao_caixa_id' => $sessao->id, 'nota_moeda_id' => $nota->id, 'quantidade' => 1]);

        app(SessaoCaixaService::class)->finalizarAbertura($sessao);

        $movimentos = MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessao->id)->get();

        $this->assertCount(1, $movimentos);
        $this->assertSame('dinheiro', $movimentos->first()->mov_forma_pagamento->value);
        $this->assertEqualsWithDelta(100.0, (float) $movimentos->first()->mov_valor, 0.01);
    }

    public function test_finalizar_abertura_nao_registra_movimento_quando_dinheiro_zerado(): void
    {
        $sessao = $this->sessaoAberta();
        $nota = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);
        SessaoCaixaNota::create(['sessao_caixa_id' => $sessao->id, 'nota_moeda_id' => $nota->id, 'quantidade' => 0]);

        app(SessaoCaixaService::class)->finalizarAbertura($sessao);

        $this->assertSame(0, MovimentacoesSessaoCaixa::where('mov_sessaocaixa_id', $sessao->id)->count());
    }
}
