<?php

namespace App\Services\Gestao\Acoes;

use App\Enums\FormaPagamento;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Services\Financeiro\CaixaPdvBridge;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Regras comuns às ações financeiras do chat (despesa, receita e baixa de
 * títulos): formas aceitas, parcelamento e a conferência da conta — as
 * mesmas da baixa do painel (Caixa PDV só hoje e com sessão aberta; saída
 * não deixa conta sem "aceita negativo" no vermelho).
 */
trait ConfereTitulosFinanceiros
{
    /** Formas que o chat aceita (compensação fica no painel: não move dinheiro). */
    protected static function formasAceitas(): array
    {
        return collect(FormaPagamento::cases())->reject(fn (FormaPagamento $f): bool => $f === FormaPagamento::Compensacao)->map->value->all();
    }

    /** @return array<string, list<mixed>> */
    protected static function regrasParcelas(): array
    {
        return [
            'numero_parcelas' => ['nullable', 'integer', 'min:1', 'max:36'],
            'parcelas' => ['nullable', 'array', 'min:1', 'max:36'],
            'parcelas.*.vencimento' => ['required_with:parcelas', 'date_format:Y-m-d'],
            'parcelas.*.valor' => ['required_with:parcelas', 'numeric', 'gt:0'],
        ];
    }

    /**
     * Parcelas do título: a lista informada (que precisa somar o total) ou
     * `numero_parcelas` iguais e mensais a partir do primeiro vencimento — os
     * centavos que sobram da divisão vão na primeira.
     *
     * @param  array<string, mixed>  $dados
     * @return list<array{numero: int, vencimento: string, valor: float}>
     *
     * @throws ValidationException
     */
    protected function montarParcelas(array $dados, float $total, Carbon $primeiroVencimento): array
    {
        if (filled($dados['parcelas'] ?? null)) {
            $parcelas = collect($dados['parcelas'])
                ->sortBy('vencimento')->values()
                ->map(fn (array $p, int $i): array => ['numero' => $i + 1, 'vencimento' => $p['vencimento'], 'valor' => round((float) $p['valor'], 2)]);

            if (abs($parcelas->sum('valor') - $total) > 0.009) {
                throw ValidationException::withMessages(['parcelas' => 'As parcelas somam '.$this->moeda($parcelas->sum('valor')).', diferente do valor total ('.$this->moeda($total).').']);
            }

            return $parcelas->all();
        }

        $quantidade = (int) ($dados['numero_parcelas'] ?? 1);
        $base = floor($total / $quantidade * 100) / 100;
        $primeira = round($total - $base * ($quantidade - 1), 2);

        return collect(range(1, $quantidade))->map(fn (int $n): array => [
            'numero' => $n,
            'vencimento' => $primeiroVencimento->copy()->addMonthsNoOverflow($n - 1)->toDateString(),
            'valor' => $n === 1 ? $primeira : $base,
        ])->all();
    }

    /**
     * Confere a conta da baixa. Devolve a sessão do Caixa PDV usada (ou null).
     *
     * @param  list<array<string, mixed>>  $alertas
     */
    protected function conferirConta(?ContaFinanceira $conta, float $valorSaida, Carbon $data, array &$alertas): ?int
    {
        if ($conta === null) {
            $alertas[] = $this->alerta('conta_nao_encontrada', 'Conta financeira não encontrada: veja as contas em opcoes_despesa/opcoes_receita.', bloqueia: true);

            return null;
        }

        if (! $conta->ehCaixaPdv()) {
            if ($valorSaida > 0 && (float) $conta->saldo_atual < $valorSaida - 0.001 && ! $conta->permite_saldo_negativo) {
                $alertas[] = $this->alerta('saldo_insuficiente', "A conta {$conta->nome} tem ".$this->moeda((float) $conta->saldo_atual).', menos que o valor a pagar.', bloqueia: true);
            }

            return null;
        }

        if (! $data->isToday()) {
            $alertas[] = $this->alerta('caixa_pdv_fechado', 'Caixa PDV só para movimento de hoje, com o caixa aberto. Escolha outra conta (ex.: Cofre).', bloqueia: true);

            return null;
        }

        $caixa = app(CaixaPdvBridge::class);
        try {
            $sessao = $caixa->sessaoParaMovimentar();
        } catch (FinanceiroException $e) {
            $alertas[] = $this->alerta('caixa_pdv_fechado', $e->getMessage(), bloqueia: true);

            return null;
        }

        if ($valorSaida > 0 && $caixa->dinheiroDisponivel($sessao) < $valorSaida - 0.001) {
            $alertas[] = $this->alerta('saldo_insuficiente', 'A gaveta do caixa tem '.$this->moeda($caixa->dinheiroDisponivel($sessao)).', menos que o valor a pagar.', bloqueia: true);
        }

        return $sessao->id;
    }

    protected function rotuloForma(?string $forma): string
    {
        return $forma ? ' ('.(FormaPagamento::tryFrom($forma)?->getLabel() ?? $forma).')' : '';
    }

    /** @param  list<array{numero: int, vencimento: string, valor: float}>  $parcelas */
    protected function linhasParcelas(array $parcelas): array
    {
        if (count($parcelas) < 2) {
            return [];
        }

        return array_map(fn (array $p): string => "- Parcela {$p['numero']}/".count($parcelas).': '.$this->moeda($p['valor']).' vence '.Carbon::parse($p['vencimento'])->format('d/m/Y'), $parcelas);
    }

    /** "Descrição — Parcela 2/3", como as parcelas das compras. */
    protected static function descricaoParcela(string $descricao, int $numero, int $total): string
    {
        return $total > 1 ? "{$descricao} — Parcela {$numero}/{$total}" : $descricao;
    }
}
