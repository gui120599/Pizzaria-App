<?php

namespace App\Services;

use App\Enums\StatusFechamentoCaixa;
use App\Models\FechamentoCaixa;
use App\Models\SessaoCaixa;
use Illuminate\Support\Facades\DB;

class FechamentoCaixaService
{
    /**
     * Agrega os pagamentos das vendas FINALIZADA da sessão em 4 categorias
     * (dinheiro/débito/crédito/Pix) + "outros", usando o enum fiscal fixo
     * opcoes_pagamentos.opcaopag_desc_nfe como ponte — evita ter que mapear
     * manualmente cada forma de pagamento cadastrada. Substitui o loop O(n×m)
     * hoje feito em Blade (ver resources/views/sessaoCaixaPDF.blade.php) por
     * uma query agregada. Soma também os recebimentos de títulos fiado feitos
     * nesta sessão (ver Lancamento::registrarPagamento() $sessaoCaixaId) — a
     * venda que originou o título pode ter sido finalizada em outra sessão/dia,
     * então esse valor não aparece na query de pagamentos_vendas acima.
     *
     * @return array{dinheiro: float, debito: float, credito: float, pix: float, outros: float}
     */
    public function calcularEsperado(SessaoCaixa $sessao): array
    {
        $linhas = DB::table('pagamentos_vendas')
            ->join('vendas', 'vendas.id', '=', 'pagamentos_vendas.pg_venda_venda_id')
            ->leftJoin('opcoes_pagamentos', 'opcoes_pagamentos.id', '=', 'pagamentos_vendas.pg_venda_opcaopagamento_id')
            ->where('vendas.venda_sessao_caixa_id', $sessao->id)
            ->where('vendas.venda_status', 'FINALIZADA')
            ->selectRaw('opcoes_pagamentos.opcaopag_desc_nfe as categoria_nfe, SUM(pagamentos_vendas.pg_venda_valor_pagamento) as total')
            ->groupBy('categoria_nfe')
            ->get();

        $totais = ['dinheiro' => 0.0, 'debito' => 0.0, 'credito' => 0.0, 'pix' => 0.0, 'outros' => 0.0];

        foreach ($linhas as $linha) {
            $categoria = match ($linha->categoria_nfe) {
                'cash' => 'dinheiro',
                'debitCard' => 'debito',
                'creditCard' => 'credito',
                'InstantPayment' => 'pix',
                default => 'outros',
            };

            $totais[$categoria] += (float) $linha->total;
        }

        $recebimentosFiado = DB::table('lancamento_pagamentos')
            ->join('lancamentos', 'lancamentos.id', '=', 'lancamento_pagamentos.lancamento_id')
            ->where('lancamento_pagamentos.sessao_caixa_id', $sessao->id)
            ->where('lancamentos.tipo', 'receber')
            ->selectRaw('lancamento_pagamentos.forma_pagamento as forma, SUM(lancamento_pagamentos.valor) as total')
            ->groupBy('forma')
            ->get();

        foreach ($recebimentosFiado as $linha) {
            $categoria = match ($linha->forma) {
                'dinheiro' => 'dinheiro',
                'cartao_debito' => 'debito',
                'cartao_credito' => 'credito',
                'pix' => 'pix',
                default => 'outros',
            };

            $totais[$categoria] += (float) $linha->total;
        }

        return $totais;
    }

    /**
     * Cria (ou reaproveita) o fechamento em rascunho da sessão e (re)grava o
     * snapshot do esperado. Não faz nada se o fechamento já estiver confirmado
     * (snapshot travado — ver FechamentoCaixaResource::canEdit).
     */
    public function criarOuAtualizarRascunho(SessaoCaixa $sessao, ?FechamentoCaixa $fechamento = null): FechamentoCaixa
    {
        $fechamento ??= FechamentoCaixa::firstOrNew(
            ['sessao_caixa_id' => $sessao->id],
            ['user_id' => auth()->id(), 'status' => StatusFechamentoCaixa::Rascunho],
        );

        if ($fechamento->status === StatusFechamentoCaixa::Confirmado) {
            return $fechamento;
        }

        $esperado = $this->calcularEsperado($sessao);

        $fechamento->fill([
            'total_esperado_dinheiro' => $esperado['dinheiro'],
            'total_esperado_debito' => $esperado['debito'],
            'total_esperado_credito' => $esperado['credito'],
            'total_esperado_pix' => $esperado['pix'],
            'total_esperado_outros' => $esperado['outros'],
        ])->save();

        return $fechamento;
    }

    public function confirmar(FechamentoCaixa $fechamento): void
    {
        $fechamento->update([
            'status' => StatusFechamentoCaixa::Confirmado,
            'confirmado_em' => now(),
        ]);
    }

    public function reabrir(FechamentoCaixa $fechamento): void
    {
        $fechamento->update([
            'status' => StatusFechamentoCaixa::Rascunho,
            'confirmado_em' => null,
        ]);
    }
}
