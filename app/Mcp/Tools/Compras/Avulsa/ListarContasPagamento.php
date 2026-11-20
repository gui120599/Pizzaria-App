<?php

namespace App\Mcp\Tools\Compras\Avulsa;

use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Services\Financeiro\CaixaPdvBridge;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('listar_contas_pagamento')]
#[Description('Contas de onde pode sair o dinheiro de uma compra (Caixa PDV, Cofre, bancos e contas digitais), com saldo. O Caixa PDV indica se há sessão de caixa aberta e quanto há na gaveta.')]
#[IsReadOnly]
class ListarContasPagamento extends FerramentaCompraAvulsa
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function executar(Request $request): Response
    {
        $caixa = app(CaixaPdvBridge::class);

        return Response::json([
            'contas' => ContaFinanceira::query()->ordenadas()->get()->map(function (ContaFinanceira $conta) use ($caixa): array {
                $linha = [
                    'conta_financeira_id' => $conta->id,
                    'nome' => $conta->nome,
                    'tipo' => $conta->tipo?->value,
                    'saldo' => (float) $conta->saldo_atual,
                    'permite_saldo_negativo' => (bool) $conta->permite_saldo_negativo,
                ];

                if ($conta->ehCaixaPdv()) {
                    try {
                        $sessao = $caixa->sessaoParaMovimentar();
                        $linha += ['sessao_aberta' => true, 'dinheiro_na_gaveta' => $caixa->dinheiroDisponivel($sessao)];
                    } catch (FinanceiroException $e) {
                        $linha += ['sessao_aberta' => false, 'observacao' => $e->getMessage()];
                    }
                }

                return $linha;
            })->all(),
        ]);
    }
}
