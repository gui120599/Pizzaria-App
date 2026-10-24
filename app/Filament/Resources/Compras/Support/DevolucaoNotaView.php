<?php

namespace App\Filament\Resources\Compras\Support;

use Illuminate\Support\HtmlString;

/**
 * Prévias do modal "Registrar devolução da nota" e "Converter em devolução".
 * Só estilo inline: não introduz classes Tailwind novas (evita rebuild do tema).
 */
class DevolucaoNotaView
{
    private const ERRO = 'color:#dc2626;';

    private const TABELA = 'width:100%;font-size:.875rem;border-collapse:collapse;';

    private const CELULA = 'padding:.25rem .5rem;text-align:left;';

    /** @param  array<int, array{descricao: string, quantidade: float, unidade: string, disponivel: float, valor: float, problema: ?string}>  $linhas */
    public static function itens(array $linhas): HtmlString
    {
        if ($linhas === []) {
            return new HtmlString('—');
        }

        $total = 0.0;
        $html = '<table style="'.self::TABELA.'"><thead><tr>'
            .'<th style="'.self::CELULA.'">Item da nota</th><th style="'.self::CELULA.'">Devolver</th>'
            .'<th style="'.self::CELULA.'">Disponível na compra</th><th style="'.self::CELULA.'">Crédito</th></tr></thead><tbody>';

        foreach ($linhas as $linha) {
            $total += $linha['valor'];
            $problema = $linha['problema'] !== null
                ? '<div style="'.self::ERRO.'">'.e($linha['problema']).'</div>'
                : '';

            $html .= '<tr><td style="'.self::CELULA.'">'.e($linha['descricao']).$problema.'</td>'
                .'<td style="'.self::CELULA.'">'.e(self::numero($linha['quantidade'])).' '.e($linha['unidade']).'</td>'
                .'<td style="'.self::CELULA.'">'.($linha['problema'] === 'Sem item correspondente na compra original.' ? '—' : e(self::numero($linha['disponivel']))).'</td>'
                .'<td style="'.self::CELULA.'">R$ '.number_format($linha['valor'], 2, ',', '.').'</td></tr>';
        }

        $html .= '</tbody></table>'
            .'<p style="margin-top:.5rem;font-size:.875rem;">Saída de estoque dos itens acima e crédito de <strong>R$ '
            .number_format($total, 2, ',', '.').'</strong> com o fornecedor, abatido na próxima compra dele. Não gera conta a pagar.</p>';

        return new HtmlString($html);
    }

    /**
     * @param  array{produtos: array<int, array{produto: string, saldo_antes: float, saldo_depois: float, custo_antes: float, custo_depois: float}>, titulos: array<int, array{id: int, parcela: string, valor: float}>, credito_liberado: float, bloqueios: string[], avisos: string[]}  $relatorio
     */
    public static function estorno(array $relatorio): HtmlString
    {
        $html = '';

        foreach ($relatorio['bloqueios'] as $bloqueio) {
            $html .= '<p style="'.self::ERRO.'font-weight:600;">⛔ '.e($bloqueio).'</p>';
        }

        if ($relatorio['produtos'] !== []) {
            $html .= '<p style="font-size:.875rem;font-weight:600;margin-top:.25rem;">Estoque que será desfeito (entrada indevida da nota):</p>'
                .'<table style="'.self::TABELA.'"><thead><tr><th style="'.self::CELULA.'">Produto</th>'
                .'<th style="'.self::CELULA.'">Saldo</th><th style="'.self::CELULA.'">Custo médio</th></tr></thead><tbody>';
            foreach ($relatorio['produtos'] as $p) {
                $html .= '<tr><td style="'.self::CELULA.'">'.e($p['produto']).'</td>'
                    .'<td style="'.self::CELULA.'">'.e(self::numero($p['saldo_antes'])).' → '.e(self::numero($p['saldo_depois'])).'</td>'
                    .'<td style="'.self::CELULA.'">R$ '.number_format($p['custo_antes'], 4, ',', '.').' → R$ '.number_format($p['custo_depois'], 4, ',', '.').'</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        if ($relatorio['titulos'] !== []) {
            $html .= '<p style="font-size:.875rem;margin-top:.5rem;">Títulos a pagar que serão cancelados: '
                .e(collect($relatorio['titulos'])->map(fn (array $t): string => "parcela {$t['parcela']} (R$ ".number_format($t['valor'], 2, ',', '.').')')->implode('; ')).'.</p>';
        }

        foreach ($relatorio['avisos'] as $aviso) {
            $html .= '<p style="font-size:.875rem;margin-top:.25rem;">⚠️ '.e($aviso).'</p>';
        }

        return new HtmlString($html === '' ? '—' : $html);
    }

    private static function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 4, ',', '.'), '0'), ',');
    }
}
