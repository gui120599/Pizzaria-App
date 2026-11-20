<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('preparar_pausa_produto')]
#[Description('PRÉVIA (nada é gravado) para pausar produtos que acabaram — tira do cardápio online e/ou do atendimento/garçom e religa sozinho no horário "ate" — ou para retomar antes da hora. Ex.: "acabou a calabresa, pausa até amanhã 18h". Devolve acao_uuid e resumo_texto; grave com confirmar_acao após o "sim" do usuário.')]
class PrepararPausaProduto extends FerramentaAcao
{
    protected function tipo(): TipoAcaoClaude
    {
        return TipoAcaoClaude::PausaProduto;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'acao_uuid' => $schema->string()->description('Prévia a corrigir (opcional).'),
            'acao' => $schema->string()->enum(['pausar', 'retomar'])->required(),
            'produto_ids' => $schema->array()->items($schema->integer())->description('Produtos (ex.: todos os tamanhos da calabresa). Informe isto OU busca.'),
            'busca' => $schema->string()->description('Nome do produto, se for um só. Nome ambíguo devolve os candidatos com id.'),
            'canais' => $schema->array()->items($schema->string()->enum(['online', 'salao']))->description('online = cardápio online; salao = atendimento/garçom. Padrão: os dois.'),
            'ate' => $schema->string()->description('Quando volta, AAAA-MM-DD HH:MM (horário de Brasília). Sem isto, fica pausado até alguém retomar.'),
            'motivo' => $schema->string(),
        ];
    }
}
