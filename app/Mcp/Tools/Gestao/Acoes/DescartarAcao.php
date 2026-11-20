<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Mcp\Tools\Gestao\FerramentaGestao;
use App\Models\User;
use App\Services\Gestao\Acoes\AcoesClaudeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('descartar_acao')]
#[Description('Descarta uma prévia preparada que o usuário não quer mais. Nada foi gravado, então nada é desfeito.')]
class DescartarAcao extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return ['acao_uuid' => $schema->string()->required()];
    }

    protected function permitido(User $usuario): bool
    {
        return ! $usuario->hasRole('Agente') && Gate::forUser($usuario)->allows(AcoesClaudeService::PERMISSAO);
    }

    public function handle(Request $request): Response
    {
        try {
            return parent::handle($request);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }
    }

    protected function executar(Request $request): Response
    {
        $uuid = (string) $request->validate(['acao_uuid' => ['required', 'string', 'size:36']])['acao_uuid'];
        $acao = app(AcoesClaudeService::class)->descartar($uuid, $request->user());

        return Response::json(['acao_uuid' => $acao->uuid, 'situacao' => $acao->status->getLabel()]);
    }
}
