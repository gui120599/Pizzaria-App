<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use App\Mcp\Tools\Gestao\FerramentaGestao;
use App\Models\User;
use App\Services\Gestao\Acoes\AcoesClaudeService;
use App\Services\Gestao\Acoes\AprovarSugestoesAcao;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('sugestoes_agente')]
#[Description('Sugestões do agente de compras aguardando aprovação: item da nota, o que o agente sugere, confiança e justificativa. Para aprovar, use preparar_aprovacao_sugestoes.')]
#[IsReadOnly]
class SugestoesAgente extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return ['limite' => $schema->integer()->min(1)->max(100)->description('Padrão 50.')];
    }

    protected function permitido(User $usuario): bool
    {
        return app(AcoesClaudeService::class)->permitido(TipoAcaoClaude::AprovarSugestoes, $usuario);
    }

    protected function executar(Request $request): Response
    {
        $limite = (int) ($request->validate(['limite' => ['nullable', 'integer', 'min:1', 'max:100']])['limite'] ?? 50);
        $sugestoes = app(AprovarSugestoesAcao::class)->sugestoes(limite: $limite);

        return Response::json(['total' => count($sugestoes), 'sugestoes' => $sugestoes]);
    }
}
