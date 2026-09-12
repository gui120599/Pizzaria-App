<?php

namespace App\Services;

use App\Enums\FormaPagamento;
use App\Enums\StatusFechamentoCaixa;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Lancamento;
use App\Models\PlanoReceita;
use App\Models\SessaoCaixa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Gera, a partir de uma sessão de caixa com fechamento Confirmado, os títulos a
 * receber (App\Models\Lancamento, tipo=Receber) correspondentes à receita de
 * vendas da sessão — 1 lançamento por opção de pagamento usada, já Pago (o
 * dinheiro/cartão foi de fato recebido no turno; é reconhecimento de receita,
 * não uma pendência de cobrança). Rastreável via `lancamentos.sessao_caixa_id`.
 *
 * Fonte do valor: FechamentoCaixaService::calcularReceitaVendas(), NUNCA o
 * snapshot "esperado" do fechamento — aquele inclui saldo inicial de abertura
 * (não é receita) e recebimentos de fiado feitos na sessão (que já são
 * pagamentos de um Lancamento próprio, criado quando a venda fiado foi
 * finalizada — importar de novo duplicaria).
 *
 * Gatilho: automático em ConfirmarFechamentoAction (ao confirmar o fechamento)
 * e manual/bulk em ImportarReceberAction/ImportarReceberBulkAction — cobre o
 * backfill de fechamentos confirmados antes desta feature existir.
 */
class ImportacaoCaixaReceberService
{
    public function __construct(private readonly FechamentoCaixaService $fechamentos) {}

    /**
     * @return Collection<int, Lancamento>
     *
     * @throws ValidationException fechamento inexistente/não Confirmado, sessão já importada
     */
    public function importar(SessaoCaixa $sessao): Collection
    {
        return DB::transaction(function () use ($sessao) {
            $sessao->refresh();
            $fechamento = $sessao->fechamentoCaixa;

            if (! $fechamento || $fechamento->status !== StatusFechamentoCaixa::Confirmado) {
                throw ValidationException::withMessages([
                    'sessao' => 'Só é possível importar sessões com o fechamento de caixa Confirmado.',
                ]);
            }

            if ($sessao->lancamentos()->exists()) {
                throw ValidationException::withMessages([
                    'sessao' => 'Esta sessão já foi importada para o Contas a Receber.',
                ]);
            }

            $linhas = $this->fechamentos->calcularReceitaVendas($sessao);

            if ($linhas->isEmpty() || $linhas->sum('total') <= 0) {
                throw ValidationException::withMessages([
                    'sessao' => 'Esta sessão não tem receita de vendas para importar.',
                ]);
            }

            $dataFechamento = $fechamento->confirmado_em ?? now();
            $lancamentos = collect();

            foreach ($linhas as $linha) {
                $total = round((float) $linha->total, 2);

                if ($total <= 0) {
                    continue;
                }

                $planoReceitaId = $linha->plano_receita_id ?? $this->planoReceitaPadraoId();

                if ($linha->plano_receita_id === null) {
                    Log::warning('Importação de caixa para o Contas a Receber usou o plano de receita padrão — configure o plano de receita da forma de pagamento.', [
                        'sessao_caixa_id' => $sessao->id,
                        'opcaopagamento_id' => $linha->opcaopagamento_id,
                        'opcaopagamento_nome' => $linha->nome,
                    ]);
                }

                $lancamento = Lancamento::create([
                    'tipo' => TipoLancamento::Receber,
                    'sessao_caixa_id' => $sessao->id,
                    'plano_receita_id' => $planoReceitaId,
                    'descricao' => "Vendas {$linha->nome} — Sessão #{$sessao->id} ({$dataFechamento->format('d/m/Y')})",
                    'valor' => $total,
                    'vencimento' => $dataFechamento,
                    'status' => StatusLancamento::Pendente,
                ]);

                $lancamento->registrarPagamento(
                    valor: $total,
                    data: $dataFechamento,
                    forma: $this->formaPagamentoDe($linha->desc_nfe),
                    observacoes: 'Baixa automática — importação da sessão de caixa.',
                    // Crítico: NÃO passar a sessão aqui. FechamentoCaixaService::
                    // calcularEsperado() somaria este pagamento como "recebimento de
                    // fiado" da própria sessão e infla o esperado dela.
                    sessaoCaixaId: null,
                );

                $lancamentos->push($lancamento->refresh());
            }

            return $lancamentos;
        });
    }

    /**
     * Roda importar() pra toda sessão com fechamento Confirmado ainda não
     * importada — pula silenciosamente as que não se aplicam (mesmo padrão de
     * ContratoService::gerarLancamentoMensal retornando null), sem lançar.
     *
     * @return Collection<int, Lancamento>
     */
    public function importarPendentes(): Collection
    {
        return SessaoCaixa::query()
            ->whereHas('fechamentoCaixa', fn ($q) => $q->where('status', StatusFechamentoCaixa::Confirmado))
            ->get()
            ->filter(fn (SessaoCaixa $sessao): bool => $this->podeImportar($sessao))
            ->flatMap(fn (SessaoCaixa $sessao): Collection => $this->importar($sessao));
    }

    /**
     * Apaga os lançamentos importados desta sessão (e seus pagamentos, via
     * cascade da FK) — permite reimportar depois de reabrir/corrigir o
     * fechamento. Bloqueia se algum título tiver mais de 1 pagamento (indício
     * de que foi mexido manualmente depois da baixa automática da importação).
     *
     * @throws ValidationException
     */
    public function estornarImportacao(SessaoCaixa $sessao): int
    {
        return DB::transaction(function () use ($sessao) {
            $lancamentos = $sessao->lancamentos()->withCount('pagamentos')->get();

            if ($lancamentos->isEmpty()) {
                return 0;
            }

            $comPagamentoExtra = $lancamentos->filter(fn (Lancamento $l): bool => $l->pagamentos_count > 1);

            if ($comPagamentoExtra->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'sessao' => 'Existem títulos desta importação com pagamentos adicionais (além da baixa automática): '
                        .$comPagamentoExtra->pluck('descricao')->implode(', ')
                        .'. Estorne o pagamento pelo Contas a Receber antes de desfazer a importação.',
                ]);
            }

            $total = $lancamentos->count();

            foreach ($lancamentos as $lancamento) {
                $lancamento->delete();
            }

            return $total;
        });
    }

    public function podeImportar(SessaoCaixa $sessao): bool
    {
        $fechamento = $sessao->fechamentoCaixa;

        if (! $fechamento || $fechamento->status !== StatusFechamentoCaixa::Confirmado) {
            return false;
        }

        if ($sessao->lancamentos()->exists()) {
            return false;
        }

        return $this->fechamentos->calcularReceitaVendas($sessao)->sum('total') > 0;
    }

    /** "Outras Receitas" (ver PlanoReceitaSeeder) — fallback pra opção de pagamento sem plano configurado. */
    private function planoReceitaPadraoId(): ?int
    {
        return PlanoReceita::where('nome', 'Outras Receitas')->value('id');
    }

    /** Deriva a App\Enums\FormaPagamento (vocabulário do financeiro) a partir do código fiscal da opção de pagamento. */
    private function formaPagamentoDe(?string $descNfe): ?FormaPagamento
    {
        return match ($descNfe) {
            'cash' => FormaPagamento::Dinheiro,
            'debitCard' => FormaPagamento::CartaoDebito,
            'creditCard' => FormaPagamento::CartaoCredito,
            'InstantPayment' => FormaPagamento::Pix,
            default => null,
        };
    }
}
