<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\Produto;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('saldo_estoque')]
#[Description('Saldo em estoque dos produtos que controlam estoque (insumos e revenda), com custo médio e valor em estoque. Ordena do menor saldo para o maior; filtra por nome ou tipo.')]
#[IsReadOnly]
class SaldoEstoque extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'busca' => $schema->string()->description('Parte do nome do produto.'),
            'tipo' => $schema->string()->enum(['insumo', 'revenda', 'produzido', 'consumo_interno'])->description('Tipo do produto.'),
            'somente_zerados' => $schema->boolean()->description('Só produtos com saldo zero ou negativo.'),
            'limite' => $schema->integer()->min(1)->max(100)->description('Padrão 30.'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view_any:produto');
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'busca' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', 'string', 'max:30'],
            'somente_zerados' => ['nullable', 'boolean'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $produtos = Produto::query()
            ->where('produto_controla_estoque', true)
            ->when($dados['busca'] ?? null, fn ($q, string $busca) => $q->where('produto_descricao', 'like', "%{$busca}%"))
            ->when($dados['tipo'] ?? null, fn ($q, string $tipo) => $q->where('produto_tipo', $tipo))
            ->when($dados['somente_zerados'] ?? false, fn ($q) => $q->where('produto_saldo_estoque', '<=', 0))
            ->orderBy('produto_saldo_estoque')
            ->orderBy('produto_descricao')
            ->limit((int) ($dados['limite'] ?? 30))
            ->get();

        return Response::json([
            'produtos' => $produtos->map(function (Produto $p): array {
                $custo = (float) $p->produto_custo_medio > 0 ? (float) $p->produto_custo_medio : (float) $p->produto_preco_custo;

                return [
                    'produto_id' => $p->id,
                    'produto' => $p->produto_descricao,
                    'tipo' => $p->produto_tipo,
                    'unidade' => $p->produto_unidade_estoque,
                    'saldo' => round((float) $p->produto_saldo_estoque, 3),
                    'custo_medio' => round($custo, 4),
                    'valor_em_estoque' => round(max(0, (float) $p->produto_saldo_estoque) * $custo, 2),
                ];
            })->all(),
        ]);
    }
}
