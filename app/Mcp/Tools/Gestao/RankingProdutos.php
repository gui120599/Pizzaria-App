<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use App\Support\IndicadoresVendas;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('ranking_produtos')]
#[Description('Produtos mais vendidos num período (cada sabor de pizza conta pela sua fração), com quantidade, receita, custo e margem — a tabela "Produtos mais vendidos" do Painel Financeiro.')]
#[IsReadOnly]
class RankingProdutos extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'inicio' => $schema->string()->description('Data inicial AAAA-MM-DD (padrão: hoje).'),
            'fim' => $schema->string()->description('Data final AAAA-MM-DD (padrão: igual ao início).'),
            'limite' => $schema->integer()->min(1)->max(50)->description('Quantos produtos (padrão 10).'),
            'ordenar_por' => $schema->string()->enum(['receita', 'quantidade'])->description('Padrão: receita.'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:financeiro_dashboard');
    }

    protected function executar(Request $request): Response
    {
        [$inicio, $fim] = $this->periodo($request);
        $dados = $request->validate([
            'limite' => ['nullable', 'integer', 'min:1', 'max:50'],
            'ordenar_por' => ['nullable', 'in:receita,quantidade'],
        ]);

        return Response::json([
            'periodo' => self::rotuloPeriodo($inicio, $fim),
            'ordenado_por' => $dados['ordenar_por'] ?? 'receita',
            'produtos' => (new IndicadoresVendas)->rankingProdutos($inicio, $fim, (int) ($dados['limite'] ?? 10), $dados['ordenar_por'] ?? 'receita')->all(),
        ]);
    }
}
