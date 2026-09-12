<?php

namespace App\Services;

use App\Enums\StatusFechamentoCaixa;
use App\Models\FechamentoCaixa;
use App\Models\NotaMoeda;
use App\Models\SessaoCaixa;
use Illuminate\Support\Collection;
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

        // Saldo inicial da abertura (ver SessaoCaixaService::registrarMovimentoAbertura)
        // entra no esperado por forma de pagamento — sem isso o troco inicial em
        // dinheiro nunca batia com o esperado e a conferência sempre dava "sobra".
        // Também soma aqui os SUPRIMENTOS manuais (MovimentacaoCaixaService::
        // registrarSuprimento), que usam a mesma combinação ENTRADA +
        // mov_forma_pagamento preenchido — correto: dinheiro/pix/cartão que o
        // operador reforça no turno deve mesmo inflar o esperado daquela forma.
        $movimentosAbertura = DB::table('movimentacoes_sessao_caixas')
            ->where('mov_sessaocaixa_id', $sessao->id)
            ->where('mov_tipo', 'ENTRADA')
            ->whereNotNull('mov_forma_pagamento')
            ->whereNull('deleted_at')
            ->selectRaw('mov_forma_pagamento as forma, SUM(mov_valor) as total')
            ->groupBy('forma')
            ->get();

        foreach ($movimentosAbertura as $linha) {
            $categoria = match ($linha->forma) {
                'dinheiro' => 'dinheiro',
                'cartao_debito' => 'debito',
                'cartao_credito' => 'credito',
                'pix' => 'pix',
                default => 'outros',
            };

            $totais[$categoria] += (float) $linha->total;
        }

        // Sangrias/saídas manuais reduzem o esperado por forma de pagamento — sem
        // isso a conferência nunca descontava uma retirada em dinheiro do turno.
        // Escopo: só movimentos MANUAIS (mov_motivo preenchido, ver
        // MovimentacaoCaixaService) — exclui a SAIDA compensatória da Stone
        // (mov_venda_id preenchido, mov_motivo nulo), cujo efeito já vem da queda
        // de venda_valor_pago na 1ª query acima. Saídas legadas antigas (sem
        // mov_motivo, registradas antes desta coluna existir) também ficam de
        // fora — não muda retroativamente conferências já confirmadas.
        // mov_forma_pagamento nulo (não deveria acontecer nos movimentos manuais
        // novos, mas cobre o caso) é tratado como dinheiro.
        $saidasManuais = DB::table('movimentacoes_sessao_caixas')
            ->where('mov_sessaocaixa_id', $sessao->id)
            ->where('mov_tipo', 'SAIDA')
            ->whereNotNull('mov_motivo')
            ->whereNull('deleted_at')
            ->selectRaw("COALESCE(mov_forma_pagamento, 'dinheiro') as forma, SUM(mov_valor) as total")
            ->groupBy('forma')
            ->get();

        foreach ($saidasManuais as $linha) {
            $categoria = match ($linha->forma) {
                'dinheiro' => 'dinheiro',
                'cartao_debito' => 'debito',
                'cartao_credito' => 'credito',
                'pix' => 'pix',
                default => 'outros',
            };

            $totais[$categoria] -= (float) $linha->total;
        }

        return $totais;
    }

    /**
     * Receita de vendas FINALIZADA da sessão por OPÇÃO DE PAGAMENTO (não por
     * categoria dinheiro/débito/crédito/Pix) — subconjunto estável da 1ª query
     * de calcularEsperado(), sem troco de abertura e sem recebimento de fiado.
     * Fonte usada por App\Services\ImportacaoCaixaReceberService: importar o
     * "esperado" bruto duplicaria o fiado (que já virou Lancamento próprio
     * quando a venda foi finalizada) e trataria troco de abertura como receita.
     *
     * @return Collection<int, object{opcaopagamento_id:int, nome:string, desc_nfe:?string, plano_receita_id:?int, total:float}>
     */
    public function calcularReceitaVendas(SessaoCaixa $sessao): Collection
    {
        return DB::table('pagamentos_vendas')
            ->join('vendas', 'vendas.id', '=', 'pagamentos_vendas.pg_venda_venda_id')
            ->join('opcoes_pagamentos', 'opcoes_pagamentos.id', '=', 'pagamentos_vendas.pg_venda_opcaopagamento_id')
            ->where('vendas.venda_sessao_caixa_id', $sessao->id)
            ->where('vendas.venda_status', 'FINALIZADA')
            ->selectRaw(
                'opcoes_pagamentos.id as opcaopagamento_id, '.
                'opcoes_pagamentos.opcaopag_nome as nome, '.
                'opcoes_pagamentos.opcaopag_desc_nfe as desc_nfe, '.
                'opcoes_pagamentos.plano_receita_id as plano_receita_id, '.
                'SUM(pagamentos_vendas.pg_venda_valor_pagamento) as total'
            )
            ->groupBy('opcoes_pagamentos.id', 'opcoes_pagamentos.opcaopag_nome', 'opcoes_pagamentos.opcaopag_desc_nfe', 'opcoes_pagamentos.plano_receita_id')
            ->get();
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

        $this->sincronizarCatalogoNotas($fechamento);

        return $fechamento;
    }

    /**
     * Garante uma linha por NotaMoeda do catálogo em fechamento_caixa_notas.
     * Cobre fechamentos criados antes do catálogo existir (o Repeater só
     * pré-popula na criação do registro, não ao editar um já existente) e
     * cédulas/moedas cadastradas depois do fechamento já estar em rascunho.
     */
    private function sincronizarCatalogoNotas(FechamentoCaixa $fechamento): void
    {
        $jaCadastradas = $fechamento->notas()->pluck('nota_moeda_id')->all();

        foreach (NotaMoeda::ordenadas()->get() as $notaMoeda) {
            if (in_array($notaMoeda->id, $jaCadastradas, true)) {
                continue;
            }

            $fechamento->notas()->create([
                'nota_moeda_id' => $notaMoeda->id,
                'quantidade' => 0,
            ]);
        }
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
