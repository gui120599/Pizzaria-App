<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use App\Mcp\Tools\Gestao\FerramentaGestao;
use App\Models\ClaudeAcao;
use App\Models\User;
use App\Services\Gestao\Acoes\AcoesClaudeService;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Base das tools "preparar_*" (G3): montam a prévia de uma ação — nada é
 * gravado. Só aparecem para quem tem executar_acoes:claude_gestao e a
 * permissão da tela da ação. A gravação é sempre confirmar_acao.
 */
abstract class FerramentaAcao extends FerramentaGestao
{
    abstract protected function tipo(): TipoAcaoClaude;

    protected function permitido(User $usuario): bool
    {
        return app(AcoesClaudeService::class)->permitido($this->tipo(), $usuario);
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
        $entrada = $request->all();
        $uuid = $entrada['acao_uuid'] ?? null;
        unset($entrada['acao_uuid']);

        return self::previa(app(AcoesClaudeService::class)->preparar($this->tipo(), $entrada, $request->user(), $uuid));
    }

    public static function previa(ClaudeAcao $acao): Response
    {
        return Response::json([
            'acao_uuid' => $acao->uuid,
            'tipo' => $acao->tipo->value,
            'expira_em' => $acao->expira_em->toIso8601String(),
            'pode_confirmar' => $acao->previa['pode_confirmar'],
            'resumo_texto' => $acao->previa['resumo_texto'] ?? null,
            'previa' => collect($acao->previa)->except(['pode_confirmar', 'resumo_texto'])->all(),
            'proximo_passo' => $acao->previa['pode_confirmar']
                ? 'Mostre o resumo_texto ao usuário e só chame confirmar_acao depois de um "sim" explícito dele.'
                : 'Há alertas que bloqueiam: explique ao usuário e prepare de novo (com o mesmo acao_uuid) depois de corrigir.',
        ]);
    }
}
