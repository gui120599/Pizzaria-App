<?php

namespace App\Mcp\Prompts;

use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;

/**
 * Base dos prompts prontos do menu do Claude (roteiro claude-mcp-gestao.md,
 * G4): o prompt só aparece para quem pode usar as ferramentas que ele chama,
 * e devolve o roteiro que o Claude segue (não lê nem grava nada sozinho).
 */
abstract class PromptPorPermissao extends Prompt
{
    abstract protected function permitido(User $usuario): bool;

    /** Texto da mensagem que entra na conversa. */
    abstract protected function roteiro(Request $request): string;

    public function shouldRegister(): bool
    {
        $usuario = auth()->user();

        return $usuario instanceof User && ! $usuario->hasRole('Agente') && $this->permitido($usuario);
    }

    public function handle(Request $request): Response
    {
        $usuario = $request->user();

        if (! $usuario instanceof User || $usuario->hasRole('Agente') || ! $this->permitido($usuario)) {
            return Response::error('Seu perfil não tem acesso às ferramentas deste atalho no painel.');
        }

        return Response::text($this->roteiro($request));
    }

    /** Argumento opcional, sem espaços sobrando. */
    protected function argumento(Request $request, string $nome): ?string
    {
        $valor = trim((string) $request->get($nome, ''));

        return $valor === '' ? null : $valor;
    }
}
