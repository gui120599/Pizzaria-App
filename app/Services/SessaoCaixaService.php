<?php

namespace App\Services;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\FormaPagamento;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Exceptions\FinanceiroException;
use App\Models\Caixa;
use App\Models\ContaFinanceira;
use App\Models\Empresa;
use App\Models\FechamentoCaixa;
use App\Models\Lancamento;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\SessaoCaixa;
use App\Models\Venda;
use App\Services\Financeiro\MovimentacaoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SessaoCaixaService
{
    public function __construct(private readonly MovimentacaoCaixaService $movimentacaoCaixa) {}

    /**
     * Um Caixa só pode ser reaberto depois que o fechamento (Filament, camada de
     * auditoria) da sua última sessão FECHADA for confirmado — evita pular a
     * conferência de um turno pra abrir o próximo. Usado tanto pelo fluxo legado
     * (SessaoCaixaController::store) quanto pelo Resource Filament (SessaoCaixaForm).
     * Caixa com `caixa_fechamento_obrigatorio` desligado reabre sem a conferência.
     */
    public function motivoBloqueioAbertura(int $caixaId): ?string
    {
        if (Caixa::whereKey($caixaId)->value('caixa_fechamento_obrigatorio') === false) {
            return null;
        }

        $ultimaFechada = SessaoCaixa::query()
            ->where('sessaocaixa_caixa_id', $caixaId)
            ->where('sessaocaixa_status', 'FECHADA')
            ->orderByDesc('sessaocaixa_data_hora_fechamento')
            ->first();

        if (! $ultimaFechada) {
            return null;
        }

        $fechamento = $ultimaFechada->fechamentoCaixa;

        if (! $fechamento || $fechamento->status !== StatusFechamentoCaixa::Confirmado) {
            return 'A última sessão deste caixa ainda não teve o fechamento confirmado. Confirme o fechamento antes de abrir uma nova sessão.';
        }

        return null;
    }

    /**
     * Registra o saldo de abertura como movimento(s) ENTRADA por forma de
     * pagamento (chaves de App\Enums\FormaPagamento), pra
     * FechamentoCaixaService::calcularEsperado() somar junto com vendas e
     * recebimentos de fiado — sem isso o troco inicial nunca entrava no
     * "esperado" e a conferência sempre acusava sobra de dinheiro.
     *
     * @param  array<string, float|string>  $valoresPorFormaPagamento
     */
    public function registrarMovimentoAbertura(SessaoCaixa $sessao, array $valoresPorFormaPagamento): void
    {
        foreach ($valoresPorFormaPagamento as $formaPagamento => $valor) {
            $valor = round((float) $valor, 2);

            if ($valor <= 0) {
                continue;
            }

            MovimentacoesSessaoCaixa::create([
                'mov_sessaocaixa_id' => $sessao->id,
                'mov_descricao' => 'Saldo inicial de abertura',
                'mov_tipo' => 'ENTRADA',
                'mov_forma_pagamento' => $formaPagamento,
                'mov_valor' => $valor,
            ]);
        }
    }

    /**
     * Fecha o ciclo da abertura depois que o Repeater 'notas' já persistiu as
     * linhas relacionadas: soma o dinheiro contado, grava saldo_inicial/final
     * e registra o movimento (só dinheiro — débito/crédito/Pix da abertura
     * ficam como carryover por maquininha, abatido no fechamento, não
     * somado ao esperado, ver FechamentoCaixa::totalPorCategoria).
     */
    public function finalizarAbertura(SessaoCaixa $sessao): void
    {
        $totalDinheiro = round((float) $sessao->notas()->sum('valor_total'), 2);

        $sessao->update([
            'sessaocaixa_saldo_inicial' => $totalDinheiro,
            'sessaocaixa_saldo_final' => $totalDinheiro,
        ]);

        $this->registrarMovimentoAbertura($sessao, [FormaPagamento::Dinheiro->value => $totalDinheiro]);
    }

    /**
     * Troco que o último fechamento confirmado do MESMO caixa físico deixou na
     * gaveta (nulo se o caixa nunca teve fechamento com troco). Comparar por
     * caixa funciona com vários caixas abertos em paralelo.
     */
    public function trocoEsperadoNaAbertura(int $caixaId, ?int $ignorarSessaoId = null): ?float
    {
        $fechamento = FechamentoCaixa::query()
            ->where('status', StatusFechamentoCaixa::Confirmado)
            ->whereNotNull('valor_troco_gaveta')
            ->whereHas('sessaoCaixa', fn ($query) => $query
                ->where('sessaocaixa_caixa_id', $caixaId)
                ->when($ignorarSessaoId, fn ($q) => $q->whereKeyNot($ignorarSessaoId)))
            ->orderByDesc('confirmado_em')
            ->orderByDesc('id')
            ->first();

        return $fechamento ? round((float) $fechamento->valor_troco_gaveta, 2) : null;
    }

    /**
     * Compara o dinheiro contado na abertura com o troco que ficou do último
     * fechamento do caixa. Igual: é o mesmo dinheiro, que já está no Caixa PDV,
     * e nada é lançado. Diferente: quebra/sobra "Diferença na abertura" paga
     * pelo Caixa PDV, fora da sessão (a sessão nova ainda não existia). Caixa
     * sem fechamento anterior com troco (implantação) não lança nada.
     */
    public function conciliarAbertura(SessaoCaixa $sessao): ?Lancamento
    {
        $esperado = $this->trocoEsperadoNaAbertura((int) $sessao->sessaocaixa_caixa_id, $sessao->id);
        $caixaPdv = ContaFinanceira::caixaPdv();
        $empresa = Empresa::query()->first();

        if ($esperado === null || $caixaPdv === null || $empresa === null) {
            return null;
        }

        $diferenca = round((float) $sessao->sessaocaixa_saldo_inicial - $esperado, 2);

        if (abs($diferenca) < 0.01) {
            return null;
        }

        $falta = $diferenca < 0;
        $planoId = $falta ? $empresa->empresa_plano_despesa_quebra_caixa_id : $empresa->empresa_plano_receita_sobra_caixa_id;

        if ($planoId === null) {
            Log::warning('Diferença na abertura sem plano de quebra/sobra configurado.', ['sessao_caixa_id' => $sessao->id, 'diferenca' => $diferenca]);

            return null;
        }

        try {
            return DB::transaction(function () use ($sessao, $diferenca, $falta, $planoId, $caixaPdv): Lancamento {
                $lancamento = Lancamento::create([
                    'tipo' => $falta ? TipoLancamento::Pagar : TipoLancamento::Receber,
                    $falta ? 'plano_despesa_id' : 'plano_receita_id' => $planoId,
                    'descricao' => 'Diferença na abertura — '.($sessao->caixa?->caixa_nome ?? 'Caixa')." (sessão #{$sessao->id})",
                    'valor' => abs($diferenca),
                    'vencimento' => today(),
                    'status' => StatusLancamento::Pendente,
                ]);

                $lancamento->registrarPagamento(
                    valor: abs($diferenca),
                    data: today(),
                    forma: FormaPagamento::Dinheiro,
                    observacoes: 'Baixa automática — troco contado na abertura diferente do deixado no fechamento anterior.',
                    contaFinanceiraId: $caixaPdv->id,
                    foraDaSessao: true,
                );

                return $lancamento;
            });
        } catch (FinanceiroException $e) {
            Log::error('Falha ao lançar a diferença na abertura do caixa.', ['sessao_caixa_id' => $sessao->id, 'erro' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Caixa sem fechamento anterior com troco (implantação): o dinheiro contado
     * na abertura ainda não está no Caixa PDV. Entra por transferência da conta
     * de onde o troco saiu (suprimento) ou, só para Admin, como ajuste de
     * implantação (sem origem, fora do DRE). Não grava linha na sessão: a
     * abertura já conta esse dinheiro no esperado.
     *
     * @throws FinanceiroException
     */
    public function lancarTrocoInicial(SessaoCaixa $sessao, ?int $contaOrigemId, bool $ajusteImplantacao = false): void
    {
        $valor = round((float) $sessao->sessaocaixa_saldo_inicial, 2);
        $caixaPdv = ContaFinanceira::caixaPdv();

        if ($valor <= 0 || $caixaPdv === null || $this->trocoEsperadoNaAbertura((int) $sessao->sessaocaixa_caixa_id, $sessao->id) !== null) {
            return;
        }

        $movimentacoes = app(MovimentacaoService::class);
        $descricao = "Troco inicial — abertura da sessão #{$sessao->id}";

        if ($contaOrigemId !== null) {
            $movimentacoes->transferir(ContaFinanceira::findOrFail($contaOrigemId), $caixaPdv, $valor, today(), $descricao, auth()->user(), tocarSessao: false);
        } elseif ($ajusteImplantacao) {
            if (! auth()->user()?->hasRole('Admin')) {
                throw new FinanceiroException('Só o Admin pode lançar o ajuste de implantação.');
            }

            $movimentacoes->entrada($caixaPdv, $valor, NaturezaMovimentacao::AjusteSaldo, today(), "Ajuste de implantação — {$descricao}", [], auth()->user(), registrarNaSessao: false);
        }
    }

    /**
     * Vincula vendas FINALIZADA sem sessão de caixa (ex.: recebidas pela
     * maquininha Stone antes de alguém abrir o caixa — ver
     * StoneVendaAutomaticaService::resolverOuCriarVenda) à sessão informada,
     * registrando o movimento de ENTRADA retroativo de cada uma e recomputando
     * o saldo ao final. `whereNull('venda_sessao_caixa_id')` protege contra
     * vincular duas vezes (ex.: dois usuários reivindicando a mesma venda) e
     * contra vincular uma venda cancelada.
     *
     * @param  array<int, int>  $vendaIds
     *
     * @throws ValidationException sessão não está ABERTA
     */
    public function vincularVendasOrfas(SessaoCaixa $sessao, array $vendaIds): int
    {
        if ($sessao->sessaocaixa_status !== 'ABERTA') {
            throw ValidationException::withMessages([
                'sessao' => 'Só é possível vincular vendas a uma sessão de caixa ABERTA.',
            ]);
        }

        if ($vendaIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($sessao, $vendaIds) {
            $vendas = Venda::whereIn('id', $vendaIds)
                ->whereNull('venda_sessao_caixa_id')
                ->where('venda_status', 'FINALIZADA')
                ->get();

            foreach ($vendas as $venda) {
                $venda->update(['venda_sessao_caixa_id' => $sessao->id]);

                MovimentacoesSessaoCaixa::create([
                    'mov_sessaocaixa_id' => $sessao->id,
                    'mov_venda_id' => $venda->id,
                    'mov_descricao' => 'VENDA: '.$venda->id,
                    'mov_tipo' => 'ENTRADA',
                    'mov_valor' => $venda->venda_valor_pago,
                ]);
            }

            if ($vendas->isNotEmpty()) {
                $this->movimentacaoCaixa->recalcularSaldoFinal($sessao->fresh());
            }

            return $vendas->count();
        });
    }
}
