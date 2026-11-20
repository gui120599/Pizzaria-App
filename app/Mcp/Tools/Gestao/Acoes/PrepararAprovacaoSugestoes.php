<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('preparar_aprovacao_sugestoes')]
#[Description('PRÉVIA (nada é gravado) para aprovar sugestões do agente de compras (vincular produto da nota, cadastrar produto, escolher lançamento) — o mesmo "Aprovar" da tela Pendências de compras. Use sugestoes_agente para ver a lista. Grave com confirmar_acao após o "sim" do usuário.')]
class PrepararAprovacaoSugestoes extends FerramentaAcao
{
    protected function tipo(): TipoAcaoClaude
    {
        return TipoAcaoClaude::AprovarSugestoes;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'acao_uuid' => $schema->string()->description('Prévia a corrigir (opcional).'),
            'pendencia_ids' => $schema->array()->items($schema->integer())->description('Pendências a aprovar. Informe isto OU todas.'),
            'todas' => $schema->boolean()->description('Todas as sugestões aguardando aprovação (até 100).'),
        ];
    }
}
