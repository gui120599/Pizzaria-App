<?php

namespace App\Mcp\Prompts\Gestao;

use App\Enums\Gestao\TipoAcaoClaude;
use App\Mcp\Prompts\PromptPorPermissao;
use App\Models\User;
use App\Services\Gestao\Acoes\AcoesClaudeService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('revisar_sugestoes_do_agente')]
#[Title('Revisar sugestões do agente')]
#[Description('Mostra as sugestões do agente de compras aguardando aprovação e aprova as escolhidas.')]
class AprovarSugestoes extends PromptPorPermissao
{
    protected function permitido(User $usuario): bool
    {
        return app(AcoesClaudeService::class)->permitido(TipoAcaoClaude::AprovarSugestoes, $usuario);
    }

    protected function roteiro(Request $request): string
    {
        return <<<'TXT'
        Revise comigo as sugestões do agente de compras pelo conector "PizzariaAPP Gestão".
        1. Chame sugestoes_agente e mostre uma tabela: nº da pendência, fornecedor, item da nota, sugestão e confiança. Marque com ⚠️ as de confiança abaixo de 90% ou em que a unidade/fator pareça estranho.
        2. Pergunte quais aprovar (todas, só as sem ⚠️, ou uma lista).
        3. Chame preparar_aprovacao_sugestoes com as escolhidas, mostre o resumo_texto e só chame confirmar_acao depois do meu "sim".
        TXT;
    }
}
