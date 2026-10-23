<?php

namespace App\Filament\Resources\Compras\Support;

use App\Enums\ConfiancaOperacaoEnum;
use App\Enums\OperacaoEntradaEnum;
use App\Models\Compra;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Apresentação somente leitura da classificação fiscal da compra (seção
 * "Operação fiscal" do formulário e avisos do modal de confirmação).
 */
class OperacaoFiscalView
{
    /** Mesmas classes do avisoDataEntrada (já compiladas no tema); listas usam estilo inline para não exigir novo build. */
    private const CLASSE_AVISO = 'rounded-lg bg-warning-50 p-3 text-sm font-medium text-warning-700 dark:bg-warning-400/10 dark:text-warning-400';

    private const ESTILO_LISTA = 'list-style:disc;padding-left:1.25rem;';

    public static function finalidade(?int $codigo): string
    {
        return match ($codigo) {
            1 => '1 — Normal',
            2 => '2 — Complementar',
            3 => '3 — Ajuste',
            4 => '4 — Devolução',
            null => '—',
            default => (string) $codigo,
        };
    }

    public static function tipoNf(?int $codigo): string
    {
        return match ($codigo) {
            0 => '0 — Entrada (do emitente)',
            1 => '1 — Saída (do emitente)',
            null => '—',
            default => (string) $codigo,
        };
    }

    /** CFOPs distintos dos itens, separados por vírgula. */
    public static function cfops(Compra $compra): string
    {
        $cfops = $compra->itens()->whereNotNull('ci_cfop')->distinct()->orderBy('ci_cfop')->pluck('ci_cfop');

        return $cfops->isEmpty() ? '—' : $cfops->implode(', ');
    }

    public static function badgeOperacao(Compra $compra): HtmlString
    {
        return self::badge(OperacaoEntradaEnum::rotulo($compra->compra_operacao), OperacaoEntradaEnum::corDoValor($compra->compra_operacao));
    }

    public static function badgeConfianca(Compra $compra): HtmlString
    {
        $confianca = $compra->compra_operacao_confianca;

        return $confianca === null
            ? new HtmlString('—')
            : self::badge($confianca->label(), $confianca->cor());
    }

    /** @param  string[]  $linhas */
    public static function lista(array $linhas): HtmlString
    {
        if ($linhas === []) {
            return new HtmlString('—');
        }

        return new HtmlString('<ul style="'.self::ESTILO_LISTA.'">'
            .collect($linhas)->map(fn (string $linha): string => '<li>'.e($linha).'</li>')->implode('')
            .'</ul>');
    }

    /**
     * Aviso quando a operação sugerida não é COMPRA ou a confiança não é ALTA,
     * ou null quando não há nada a destacar (compra comum, manual ou legada).
     */
    public static function avisoOperacao(Compra $compra): ?HtmlString
    {
        $operacao = $compra->compra_operacao;
        $confianca = $compra->compra_operacao_confianca;

        if ($operacao === null || $confianca === null) {
            return null;
        }

        if ($operacao === OperacaoEntradaEnum::COMPRA->value && $confianca === ConfiancaOperacaoEnum::ALTA) {
            return null;
        }

        $mensagem = $operacao === OperacaoEntradaEnum::COMPRA->value
            ? 'A operação sugerida é <strong>Compra</strong>, mas com confiança <strong>'.e($confianca->label()).'</strong>.'
            : 'Esta nota parece ser <strong>'.e(mb_strtoupper(OperacaoEntradaEnum::rotulo($operacao))).'</strong> (confiança '.e(mb_strtolower($confianca->label())).').';

        $alertas = $compra->compra_operacao_criterios['alertas'] ?? [];
        $detalhes = $alertas === [] ? '' : '<ul style="margin-top:.5rem;font-weight:400;'.self::ESTILO_LISTA.'">'
            .collect($alertas)->map(fn (string $alerta): string => '<li>'.e($alerta).'</li>')->implode('').'</ul>';

        return new HtmlString('<div class="'.self::CLASSE_AVISO.'">⚠️ '.$mensagem
            .' Confira antes de gerar conta a pagar e entrada de estoque: por enquanto o sistema trata toda nota como compra.'
            .$detalhes.'</div>');
    }

    /** Aviso quando o total calculado da compra difere do vNF da nota. */
    public static function avisoDivergenciaValor(Compra $compra): ?HtmlString
    {
        $diferenca = $compra->divergenciaValorNf();

        if ($diferenca === null || abs($diferenca) < 0.01) {
            return null;
        }

        $detalhe = collect([
            'ICMS-ST' => (float) $compra->compra_valor_st,
            'IPI' => (float) $compra->compra_valor_ipi,
            'seguro' => (float) $compra->compra_valor_seguro,
            'FCP-ST' => (float) $compra->compra_valor_fcp_st,
        ])->filter(fn (float $valor): bool => $valor > 0)
            ->map(fn (float $valor, string $nome): string => $nome.' R$ '.number_format($valor, 2, ',', '.'))
            ->implode(', ');

        return new HtmlString('<div class="'.self::CLASSE_AVISO.'">⚠️ O total da compra (R$ '
            .number_format((float) $compra->compra_valor_total, 2, ',', '.').') difere do valor da nota (R$ '
            .number_format((float) $compra->compra_valor_nf, 2, ',', '.').') em R$ '
            .number_format(abs($diferenca), 2, ',', '.')
            .($detalhe !== '' ? ". Tributos destacados e não somados: {$detalhe}." : '.')
            .'</div>');
    }

    private static function badge(string $texto, string $cor): HtmlString
    {
        return new HtmlString(Blade::render(
            '<x-filament::badge :color="$cor">{{ $texto }}</x-filament::badge>',
            ['texto' => $texto, 'cor' => $cor],
        ));
    }
}
