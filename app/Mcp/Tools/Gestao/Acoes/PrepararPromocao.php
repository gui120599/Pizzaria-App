<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('preparar_promocao')]
#[Description('PRÉVIA (nada é gravado) para ligar uma promoção relâmpago já cadastrada numa janela (ex.: hoje das 18h às 20h) ou encerrá-la agora. Recorrente liga na própria agenda. Sem promocao_id nem busca, lista as promoções com id. Produtos e preços continuam no painel. Grave com confirmar_acao após o "sim" do usuário.')]
class PrepararPromocao extends FerramentaAcao
{
    protected function tipo(): TipoAcaoClaude
    {
        return TipoAcaoClaude::Promocao;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'acao_uuid' => $schema->string()->description('Prévia a corrigir (opcional).'),
            'acao' => $schema->string()->enum(['ativar', 'encerrar'])->required(),
            'promocao_id' => $schema->integer(),
            'busca' => $schema->string()->description('Parte do nome da promoção.'),
            'inicio' => $schema->string()->description('AAAA-MM-DD HH:MM. Padrão: agora.'),
            'fim' => $schema->string()->description('AAAA-MM-DD HH:MM. Obrigatório para ligar promoção não recorrente.'),
            'zerar_vendidos' => $schema->boolean()->description('Nova rodada: zera os vendidos do teto. Padrão: não.'),
        ];
    }
}
