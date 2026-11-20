<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Mcp\Tools\Gestao\FerramentaGestao;
use App\Models\ProdutoPausa;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('produtos_pausados')]
#[Description('Produtos pausados agora (acabou o insumo): de quais canais saíram, desde quando, até quando e o motivo.')]
#[IsReadOnly]
class ProdutosPausados extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view_any:produto');
    }

    protected function executar(Request $request): Response
    {
        $pausas = ProdutoPausa::query()->emAberto()->with(['produto', 'user'])->orderBy('created_at')->get();

        return Response::json([
            'total' => $pausas->count(),
            'pausas' => $pausas->map(fn (ProdutoPausa $p): array => [
                'produto_id' => $p->produto_id,
                'produto' => $p->produto?->produto_descricao,
                'fora_de' => array_map(ProdutoPausa::rotuloCanal(...), $p->canais),
                'desde' => $p->created_at?->format('Y-m-d H:i'),
                'volta_em' => $p->pausado_ate?->format('Y-m-d H:i'),
                'motivo' => $p->motivo,
                'pausado_por' => $p->user?->name,
            ])->all(),
        ]);
    }
}
