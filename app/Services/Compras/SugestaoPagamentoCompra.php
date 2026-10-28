<?php

namespace App\Services\Compras;

use App\Enums\BaseVencimentoEnum;
use App\Enums\FormaPagamento;
use App\Enums\VencimentoOrigemEnum;
use App\Models\Compra;
use App\Models\ContaFinanceira;
use App\Models\FornecedorCondicaoCompra;
use App\Models\PrazoPagamento;
use App\Models\PrazoPagamentoParcela;
use App\Services\Compras\Dto\SugestaoPagamento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Monta a sugestão de parcelas do modal "Confirmar compra", na ordem:
 * duplicatas da NF-e, prazo padrão do fornecedor, parcela única na entrada.
 *
 * Forma e conta de origem são só sugestão: "ja_pago" é sempre false e
 * "sessao_caixa_id" sempre null. Toda linha traz as 6 chaves dos campos do
 * Repeater, senão o $entangle dos Selects não nativos quebra no navegador.
 */
class SugestaoPagamentoCompra
{
    private const TOLERANCIA = 0.05;

    /** tPag da NF-e → forma de pagamento. 90, 99 e os demais não têm equivalente. */
    private const FORMA_POR_TPAG = [
        '01' => FormaPagamento::Dinheiro,
        '03' => FormaPagamento::CartaoCredito,
        '04' => FormaPagamento::CartaoDebito,
        '15' => FormaPagamento::Boleto,
        '16' => FormaPagamento::Transferencia,
        '18' => FormaPagamento::Transferencia,
        '17' => FormaPagamento::Pix,
        '20' => FormaPagamento::Pix,
    ];

    public function para(Compra $compra): SugestaoPagamento
    {
        $compra->loadMissing('prestador.condicaoCompra');

        $condicao = $compra->prestador?->condicaoCompra;
        $dataEntrada = $compra->dataEntradaSugerida();
        $total = (float) $compra->compra_valor_total;
        $formaDaNota = $this->formaDaNota($compra);
        $emissao = $compra->compra_data_emissao;

        $base = $condicao?->fcc_prazo_base === BaseVencimentoEnum::Emissao && $emissao !== null
            ? BaseVencimentoEnum::Emissao
            : BaseVencimentoEnum::Entrada;
        $dataBase = $base === BaseVencimentoEnum::Emissao ? $emissao->copy()->startOfDay() : $dataEntrada;

        $prazo = $this->prazoValido($condicao);
        $avisos = [];
        $fontes = [];

        $duplicatas = $this->duplicatasValidas($compra, $avisos);

        if ($duplicatas !== []) {
            $parcelas = $this->parcelasDasDuplicatas($compra, $duplicatas, $total, $condicao, $formaDaNota, $dataEntrada, $avisos);
            $fontes[] = 'duplicatas da NF-e';

            if ($prazo !== null) {
                $this->avisarDivergenciaComPrazo($prazo, $dataBase, $duplicatas, $avisos);
            }

            $origem = VencimentoOrigemEnum::DuplicatasNf;
            $prazoId = null;
        } elseif ($prazo !== null) {
            $parcelas = $this->parcelasDoPrazo($prazo, $dataBase, $total, $condicao, $formaDaNota);
            $fontes[] = 'prazo "'.$prazo->prazo_pagamento_nome.'" do fornecedor (contado da '.($base === BaseVencimentoEnum::Emissao ? 'emissão' : 'entrada').')';
            $origem = $base === BaseVencimentoEnum::Emissao ? VencimentoOrigemEnum::PrazoEmissao : VencimentoOrigemEnum::PrazoEntrada;
            $prazoId = $prazo->id;
        } else {
            $parcelas = [(string) Str::uuid() => $this->linha(
                $dataEntrada->toDateString(),
                $total,
                $this->formaPadrao($condicao, null, $formaDaNota),
                $this->contaSugerida($condicao),
            )];
            $origem = VencimentoOrigemEnum::PrazoEntrada;
            $prazoId = null;
        }

        if ($condicao?->fcc_forma_pagamento !== null || $this->contaSugerida($condicao) !== null) {
            $fontes[] = 'forma e conta do cadastro do fornecedor';
        }

        return new SugestaoPagamento(
            dataEntrada: $dataEntrada,
            dataBase: $dataBase,
            baseVencimento: $base,
            prazoPagamentoId: $prazoId,
            origem: $origem,
            parcelas: $parcelas,
            avisos: $avisos,
            fontes: $fontes,
        );
    }

    /**
     * Vencimento + valor de cada parcela do Prazo (dias + percentual),
     * reconciliando o resíduo de arredondamento na maior parcela — mesmo
     * padrão de CompraService::gravarRateio, pra soma bater com o esperado
     * (percentuais somando 100%) mesmo com valores quebrados (ex.: R$ 100,01
     * em 3 parcelas de 33,33%).
     *
     * Quando os percentuais somam mais de 100% de propósito (juros embutido),
     * a reconciliação preserva a diferença: só corrige o arredondamento por
     * parcela. Prazo com percentuais zerados produz parcelas de R$ 0,00 (0%
     * de qualquer valor é 0), então confira o cadastro do prazo se isso ocorrer.
     *
     * As linhas voltam com chaves UUID novas a cada chamada, não índices 0,1,2,
     * pra forçar o Livewire a recriar cada item em vez de reciclar um nó DOM.
     *
     * @return array<string, array{vencimento: string, valor: string, forma_pagamento: ?string, ja_pago: false, conta_financeira_id: ?int, sessao_caixa_id: null}>
     */
    public function parcelasDoPrazo(PrazoPagamento $prazo, Carbon $dataBase, float $total, ?FornecedorCondicaoCompra $condicao, ?FormaPagamento $formaDaNota = null): array
    {
        $linhas = $prazo->parcelas->map(fn (PrazoPagamentoParcela $parcela): array => [
            'vencimento' => $dataBase->copy()->addDays($parcela->parcela_dias)->toDateString(),
            'valor_bruto' => $total * ((float) $parcela->parcela_percentual / 100),
            'forma' => $parcela->parcela_forma_pagamento,
        ])->values()->all();

        if ($linhas === []) {
            return [];
        }

        $somaBruta = round(array_sum(array_column($linhas, 'valor_bruto')), 2);
        foreach ($linhas as &$linha) {
            $linha['valor'] = round($linha['valor_bruto'], 2);
        }
        unset($linha);

        $diferenca = round($somaBruta - array_sum(array_column($linhas, 'valor')), 2);
        if (abs($diferenca) >= 0.01) {
            $maiorIndice = collect($linhas)->sortByDesc('valor')->keys()->first();
            $linhas[$maiorIndice]['valor'] = round($linhas[$maiorIndice]['valor'] + $diferenca, 2);
        }

        $conta = $this->contaSugerida($condicao);
        $resultado = [];
        foreach ($linhas as $linha) {
            $resultado[(string) Str::uuid()] = $this->linha(
                $linha['vencimento'],
                $linha['valor'],
                $this->formaPadrao($condicao, $linha['forma'], $formaDaNota),
                $conta,
            );
        }

        return $resultado;
    }

    /**
     * Linha do Repeater de parcelas com todas as chaves dos campos filhos.
     * Linha sem a chave quebra o $entangle dos Selects não nativos (a escolha
     * nunca chega ao servidor): o $set('parcelas', ...) não hidrata defaults.
     *
     * @return array{vencimento: string, valor: string, forma_pagamento: ?string, ja_pago: false, conta_financeira_id: ?int, sessao_caixa_id: null}
     */
    public static function linhaParcela(string $vencimento, float|string $valor, ?string $forma = null, ?int $contaId = null): array
    {
        return [
            'vencimento' => $vencimento,
            'valor' => number_format((float) $valor, 2, '.', ''),
            'forma_pagamento' => $forma,
            'ja_pago' => false,
            'conta_financeira_id' => $contaId,
            'sessao_caixa_id' => null,
        ];
    }

    /** Forma sugerida pela nota quando há um único tPag mapeável. */
    public function formaDaNota(Compra $compra): ?FormaPagamento
    {
        $formas = collect($compra->compra_pagamentos_nf ?? [])
            ->map(fn (array $pagamento): ?FormaPagamento => self::FORMA_POR_TPAG[(string) ($pagamento['tPag'] ?? '')] ?? null)
            ->filter()
            ->unique();

        return $formas->count() === 1 ? $formas->first() : null;
    }

    private function linha(string $vencimento, float $valor, ?FormaPagamento $forma, ?int $contaId): array
    {
        return self::linhaParcela($vencimento, $valor, $forma?->value, $contaId);
    }

    /** Parcela do prazo > padrão do fornecedor > tPag da nota > nenhuma. */
    private function formaPadrao(?FornecedorCondicaoCompra $condicao, FormaPagamento|string|null $daParcela, ?FormaPagamento $formaDaNota): ?FormaPagamento
    {
        if (is_string($daParcela)) {
            $daParcela = FormaPagamento::tryFrom($daParcela);
        }

        return $daParcela ?? $condicao?->fcc_forma_pagamento ?? $formaDaNota;
    }

    /** Conta sugerida só vale se ainda existe (ex.: não foi excluída depois do cadastro). */
    private function contaSugerida(?FornecedorCondicaoCompra $condicao): ?int
    {
        $id = $condicao?->fcc_conta_financeira_id;

        return $id !== null && ContaFinanceira::query()->whereKey($id)->exists() ? (int) $id : null;
    }

    /** Prazo padrão que ainda pode ser usado: existe, está ativo e tem parcelas. */
    private function prazoValido(?FornecedorCondicaoCompra $condicao): ?PrazoPagamento
    {
        $prazo = $condicao?->prazoPagamento()->with('parcelas')->first();

        return $prazo !== null && $prazo->prazo_pagamento_ativo && $prazo->parcelas->isNotEmpty() ? $prazo : null;
    }

    /**
     * @param  list<string>  $avisos
     * @return list<array{vencimento: string, valor: float}>
     */
    private function duplicatasValidas(Compra $compra, array &$avisos): array
    {
        $todas = collect($compra->compra_duplicatas_nf ?? []);

        $validas = $todas
            ->filter(fn (array $dup): bool => filled($dup['dVenc'] ?? null) && (float) ($dup['vDup'] ?? 0) > 0)
            ->map(fn (array $dup): array => ['vencimento' => Carbon::parse($dup['dVenc'])->toDateString(), 'valor' => round((float) $dup['vDup'], 2)])
            ->values();

        $descartadas = $todas->count() - $validas->count();
        if ($descartadas > 0) {
            $avisos[] = $descartadas === 1
                ? 'Uma duplicata da nota foi ignorada por não ter vencimento ou valor.'
                : "{$descartadas} duplicatas da nota foram ignoradas por não terem vencimento ou valor.";
        }

        return $validas->all();
    }

    /**
     * @param  list<array{vencimento: string, valor: float}>  $duplicatas
     * @param  list<string>  $avisos
     * @return array<string, array<string, mixed>>
     */
    private function parcelasDasDuplicatas(Compra $compra, array $duplicatas, float $total, ?FornecedorCondicaoCompra $condicao, ?FormaPagamento $formaDaNota, Carbon $dataEntrada, array &$avisos): array
    {
        $forma = $this->formaPadrao($condicao, null, $formaDaNota);
        $conta = $this->contaSugerida($condicao);

        $parcelas = [];
        foreach ($duplicatas as $duplicata) {
            $parcelas[(string) Str::uuid()] = $this->linha($duplicata['vencimento'], $duplicata['valor'], $forma, $conta);
        }

        $soma = round(array_sum(array_column($duplicatas, 'valor')), 2);
        $diferenca = round($total - $soma, 2);

        if ($diferenca > self::TOLERANCIA) {
            $vencimentoExtra = ($compra->compra_data_emissao ?? $dataEntrada)->copy()->startOfDay()->toDateString();
            $parcelas[(string) Str::uuid()] = $this->linha($vencimentoExtra, $diferenca, $this->formaPadrao($condicao, null, $formaDaNota), $conta);
            $avisos[] = 'As duplicatas somam R$ '.number_format($soma, 2, ',', '.').', menos que o total da compra (R$ '.number_format($total, 2, ',', '.').'). Foi acrescentada uma parcela com a diferença, na data de emissão: confira.';
        } elseif ($diferenca < -self::TOLERANCIA) {
            $avisos[] = 'As duplicatas somam R$ '.number_format($soma, 2, ',', '.').', mais que o total da compra (R$ '.number_format($total, 2, ',', '.').'). Confira os valores.';
        }

        return $parcelas;
    }

    /**
     * @param  list<array{vencimento: string, valor: float}>  $duplicatas
     * @param  list<string>  $avisos
     */
    private function avisarDivergenciaComPrazo(PrazoPagamento $prazo, Carbon $dataBase, array $duplicatas, array &$avisos): void
    {
        $doPrazo = $prazo->parcelas
            ->map(fn (PrazoPagamentoParcela $parcela): string => $dataBase->copy()->addDays($parcela->parcela_dias)->toDateString())
            ->sort()->values()->all();
        $daNota = collect($duplicatas)->pluck('vencimento')->sort()->values()->all();

        if ($doPrazo === $daNota) {
            return;
        }

        $dias = $prazo->parcelas->pluck('parcela_dias')->implode('/');
        $vencimentos = collect($daNota)->map(fn (string $data): string => Carbon::parse($data)->format('d/m'))->implode(', ');
        $quantidade = count($daNota);

        $avisos[] = "O prazo do fornecedor é {$dias}, mas a nota traz {$quantidade} duplicata(s) para {$vencimentos}. Os vencimentos da nota foram mantidos.";
    }
}
