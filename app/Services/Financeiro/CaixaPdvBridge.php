<?php

namespace App\Services\Financeiro;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\FormaPagamento;
use App\Enums\MotivoSaidaCaixa;
use App\Enums\StatusFechamentoCaixa;
use App\Exceptions\FinanceiroException;
use App\Models\MovimentacaoFinanceira;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\SessaoCaixa;
use App\Services\FechamentoCaixaService;
use App\Services\MovimentacaoCaixaService;
use Illuminate\Support\Collection;

/**
 * Único ponto em que uma movimentação da conta Caixa PDV toca a sessão de
 * caixa. A retirada/suprimento gravada na sessão fica ligada à movimentação
 * (movimentacoes_sessao_caixas.movimentacao_financeira_id): a despesa já
 * existe no financeiro, então nada gera lançamento a partir dessa linha.
 */
class CaixaPdvBridge
{
    public function __construct(
        private readonly MovimentacaoCaixaService $movimentosCaixa,
        private readonly FechamentoCaixaService $fechamentos,
    ) {}

    /** @return Collection<int, SessaoCaixa> */
    public function sessoesAbertas(): Collection
    {
        return SessaoCaixa::query()->where('sessaocaixa_status', 'ABERTA')->orderBy('id')->get();
    }

    /**
     * A sessão de onde o dinheiro sai (ou para onde entra): a informada, que
     * tem de estar aberta, ou a única aberta.
     *
     * @throws FinanceiroException
     */
    public function sessaoParaMovimentar(?SessaoCaixa $sessao = null): SessaoCaixa
    {
        if ($sessao !== null) {
            $sessao = SessaoCaixa::query()->find($sessao->getKey());

            if ($sessao?->sessaocaixa_status !== 'ABERTA') {
                throw new FinanceiroException('A sessão de caixa escolhida não está aberta. Abra o caixa ou escolha outra conta.');
            }

            return $sessao;
        }

        $abertas = $this->sessoesAbertas();

        return match ($abertas->count()) {
            0 => throw new FinanceiroException('Não há sessão de caixa aberta. Abra o caixa ou escolha outra conta.'),
            1 => $abertas->first(),
            default => throw new FinanceiroException('Há mais de uma sessão de caixa aberta: escolha de qual delas sai o dinheiro.'),
        };
    }

    /** Dinheiro esperado na gaveta agora (mesmo cálculo da conferência do fechamento). */
    public function dinheiroDisponivel(SessaoCaixa $sessao): float
    {
        return round($this->fechamentos->calcularEsperado($sessao)['dinheiro'], 2);
    }

    /** Sangria (transferência) ou pagamento de despesa saindo da gaveta. */
    public function registrarRetirada(SessaoCaixa $sessao, MovimentacaoFinanceira $movimentacao): MovimentacoesSessaoCaixa
    {
        $motivo = $movimentacao->natureza === NaturezaMovimentacao::Transferencia
            ? MotivoSaidaCaixa::Sangria
            : MotivoSaidaCaixa::PagamentoDespesa;

        $retirada = $this->movimentosCaixa->registrarSaida(
            $sessao,
            (float) $movimentacao->valor,
            FormaPagamento::Dinheiro,
            $motivo,
            $movimentacao->descricao,
            "Movimentação financeira #{$movimentacao->id}",
        );

        $retirada->update(['movimentacao_financeira_id' => $movimentacao->id]);

        return $retirada;
    }

    /** Reforço de troco vindo de outra conta (transferência para o Caixa PDV). */
    public function registrarSuprimento(SessaoCaixa $sessao, MovimentacaoFinanceira $movimentacao): MovimentacoesSessaoCaixa
    {
        $suprimento = $this->movimentosCaixa->registrarSuprimento(
            $sessao,
            (float) $movimentacao->valor,
            FormaPagamento::Dinheiro,
            $movimentacao->descricao,
            "Movimentação financeira #{$movimentacao->id}",
        );

        $suprimento->update(['movimentacao_financeira_id' => $movimentacao->id]);

        return $suprimento;
    }

    /**
     * Estorno: tira da sessão a retirada/suprimento ligada à movimentação.
     * Só com a sessão ainda aberta; depois do fechamento o dinheiro já foi
     * conferido e a devolução tem de ser lançada na sessão atual.
     *
     * @throws FinanceiroException
     */
    public function desfazer(MovimentacaoFinanceira $movimentacao): void
    {
        $linha = MovimentacoesSessaoCaixa::query()->where('movimentacao_financeira_id', $movimentacao->id)->first();

        if ($linha === null) {
            return;
        }

        $sessao = SessaoCaixa::query()->lockForUpdate()->find($linha->mov_sessaocaixa_id);

        if ($sessao?->sessaocaixa_status !== 'ABERTA') {
            throw new FinanceiroException("A sessão de caixa #{$linha->mov_sessaocaixa_id} já foi fechada, então o estorno não pode devolver o dinheiro a ela. Registre a devolução como suprimento na sessão aberta.");
        }

        $linha->delete();
        $this->movimentosCaixa->recalcularSaldoFinal($sessao);

        $fechamento = $sessao->fechamentoCaixa()->first();

        if ($fechamento && $fechamento->status === StatusFechamentoCaixa::Rascunho) {
            $this->fechamentos->criarOuAtualizarRascunho($sessao, $fechamento);
        }
    }
}
