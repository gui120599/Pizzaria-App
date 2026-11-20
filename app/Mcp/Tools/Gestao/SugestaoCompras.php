<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use App\Services\Gestao\RotinasGestaoService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('sugestao_compras')]
#[Description('Lista de compras sugerida, agrupada pelo fornecedor da última compra: insumos cujo saldo não cobre N dias de consumo (média diária das saídas de estoque), com quantidade na unidade de compra e custo estimado pelo último custo. Não cria pedido nenhum — é só a sugestão.')]
#[IsReadOnly]
class SugestaoCompras extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'cobertura_dias' => $schema->integer()->min(1)->max(60)->description('Comprar para quantos dias (padrão 7).'),
            'janela_dias' => $schema->integer()->min(7)->max(120)->description('Dias de consumo para a média (padrão 28).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view_any:compra');
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'cobertura_dias' => ['nullable', 'integer', 'min:1', 'max:60'],
            'janela_dias' => ['nullable', 'integer', 'min:7', 'max:120'],
        ]);

        return Response::json(app(RotinasGestaoService::class)->sugestaoCompras(
            (int) ($dados['cobertura_dias'] ?? config('gestao.sugestao_compras.cobertura_dias', 7)),
            (int) ($dados['janela_dias'] ?? config('gestao.sugestao_compras.janela_dias', 28)),
        ));
    }
}
