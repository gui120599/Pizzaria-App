<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Até aqui o PIX CNPJ era lançado como uma maquininha "PIX CNPJ". Passa o
     * Pix dela para fechamentos_caixa.valor_pix_cnpj, separa o esperado Pix
     * entre maquininhas e PIX CNPJ (o total geral não muda, nem nos
     * confirmados) e desativa a maquininha. Os pagamentos das formas PIX CNPJ
     * perdem o retrato de taxa de maquininha e passam a usar a tarifa da forma.
     */
    public function up(): void
    {
        $formasPixCnpj = DB::table('opcoes_pagamentos')->where('opcaopag_pix_cnpj', true)->pluck('id');

        DB::table('pagamentos_vendas')
            ->whereIn('pg_venda_opcaopagamento_id', $formasPixCnpj)
            ->update([
                'pg_venda_maquininha_id' => null,
                'pg_venda_taxa_maquininha_percentual' => null,
                'pg_venda_taxa_maquininha_valor' => null,
            ]);

        $falsas = DB::table('maquininhas')
            ->whereNull('deleted_at')
            ->where('nome', 'like', '%PIX%')
            ->where('nome', 'like', '%CNPJ%')
            ->pluck('id');

        foreach (DB::table('fechamento_caixa_maquininhas')->whereIn('maquininha_id', $falsas)->get() as $linha) {
            $fechamento = DB::table('fechamentos_caixa')->find($linha->fechamento_caixa_id);
            $abertura = DB::table('sessao_caixa_maquininhas')
                ->where('sessao_caixa_id', $fechamento?->sessao_caixa_id)
                ->where('maquininha_id', $linha->maquininha_id)
                ->first();

            DB::table('fechamentos_caixa')->where('id', $linha->fechamento_caixa_id)->update([
                'valor_pix_cnpj' => round((float) $fechamento->valor_pix_cnpj + (float) $linha->valor_pix - (float) ($abertura->valor_pix ?? 0), 2),
            ]);

            $semCartao = (float) $linha->valor_debito == 0.0 && (float) $linha->valor_credito == 0.0
                && (float) ($abertura->valor_debito ?? 0) == 0.0 && (float) ($abertura->valor_credito ?? 0) == 0.0;

            $semCartao
                ? DB::table('fechamento_caixa_maquininhas')->where('id', $linha->id)->delete()
                : DB::table('fechamento_caixa_maquininhas')->where('id', $linha->id)->update(['valor_pix' => 0]);
        }

        foreach (DB::table('sessao_caixa_maquininhas')->whereIn('maquininha_id', $falsas)->get() as $linha) {
            $aindaUsada = DB::table('fechamento_caixa_maquininhas')
                ->join('fechamentos_caixa', 'fechamentos_caixa.id', '=', 'fechamento_caixa_maquininhas.fechamento_caixa_id')
                ->where('fechamentos_caixa.sessao_caixa_id', $linha->sessao_caixa_id)
                ->where('fechamento_caixa_maquininhas.maquininha_id', $linha->maquininha_id)
                ->exists();

            $aindaUsada
                ? DB::table('sessao_caixa_maquininhas')->where('id', $linha->id)->update(['valor_pix' => 0])
                : DB::table('sessao_caixa_maquininhas')->where('id', $linha->id)->delete();
        }

        $esperadoPorSessao = DB::table('pagamentos_vendas')
            ->join('vendas', 'vendas.id', '=', 'pagamentos_vendas.pg_venda_venda_id')
            ->whereIn('pagamentos_vendas.pg_venda_opcaopagamento_id', $formasPixCnpj)
            ->where('vendas.venda_status', 'FINALIZADA')
            ->whereNotNull('vendas.venda_sessao_caixa_id')
            ->groupBy('vendas.venda_sessao_caixa_id')
            ->selectRaw('vendas.venda_sessao_caixa_id AS sessao_id, SUM(pagamentos_vendas.pg_venda_valor_pagamento) AS total')
            ->pluck('total', 'sessao_id');

        foreach (DB::table('fechamentos_caixa')->where('total_esperado_pix_cnpj', 0)->get() as $fechamento) {
            $pixCnpj = round((float) ($esperadoPorSessao[$fechamento->sessao_caixa_id] ?? 0), 2);

            if ($pixCnpj == 0.0) {
                continue;
            }

            DB::table('fechamentos_caixa')->where('id', $fechamento->id)->update([
                'total_esperado_pix_cnpj' => $pixCnpj,
                'total_esperado_pix' => round((float) $fechamento->total_esperado_pix - $pixCnpj, 2),
            ]);
        }

        DB::table('maquininhas')->whereIn('id', $falsas)->update(['maquininha_padrao' => false, 'deleted_at' => now()]);
    }

    /** Migração de dados: não há como separar de volta com segurança. */
    public function down(): void {}
};
