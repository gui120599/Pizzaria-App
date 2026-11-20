<?php

namespace App\Mcp\Tools\Compras\Avulsa;

use App\Enums\PrestadorCategoriaEnum;
use App\Models\Prestador;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('buscar_fornecedores')]
#[Description('Busca fornecedores por nome, apelido, telefone ou CNPJ. Devolve os parâmetros de compra (forma e conta de pagamento padrão, plano de despesa) e as observações do fornecedor.')]
#[IsReadOnly]
class BuscarFornecedores extends FerramentaCompraAvulsa
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'termo' => $schema->string()->description('Parte do nome, telefone ou CNPJ (mínimo 2 caracteres).')->required(),
        ];
    }

    protected function executar(Request $request): Response
    {
        $termo = trim((string) $request->validate(['termo' => ['required', 'string', 'min:2', 'max:100']])['termo']);
        $digitos = preg_replace('/\D/', '', $termo);

        $fornecedores = Prestador::query()
            ->with('condicaoCompra.contaFinanceira')
            ->where('categoria', PrestadorCategoriaEnum::FORNECEDOR)
            ->where(fn (Builder $query) => $query
                ->where('nome', 'like', "%{$termo}%")
                ->orWhere('razao_social', 'like', "%{$termo}%")
                ->orWhere('nome_fantasia', 'like', "%{$termo}%")
                ->when(strlen((string) $digitos) >= 4, fn (Builder $q) => $q
                    ->orWhere('cpf_cnpj', 'like', "%{$digitos}%")
                    ->orWhere('telefone', 'like', "%{$digitos}%")
                    ->orWhere('celular', 'like', "%{$digitos}%")))
            ->orderBy('nome')
            ->limit(20)
            ->get();

        return Response::json([
            'total' => $fornecedores->count(),
            'fornecedores' => $fornecedores->map(fn (Prestador $f): array => [
                'fornecedor_id' => $f->id,
                'nome' => $f->nome_exibicao,
                'cnpj' => $f->cpf_cnpj,
                'avulso' => (bool) $f->avulso,
                'forma_pagamento_padrao' => $f->condicaoCompra?->fcc_forma_pagamento?->value ?? null,
                'conta_financeira_padrao_id' => $f->condicaoCompra?->fcc_conta_financeira_id,
                'conta_financeira_padrao' => $f->condicaoCompra?->contaFinanceira?->nome,
                'plano_despesa_id' => $f->condicaoCompra?->fcc_plano_despesa_id,
                'observacoes' => $f->condicaoCompra?->fcc_observacoes,
            ])->all(),
        ]);
    }
}
