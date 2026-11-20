<?php

namespace App\Mcp\Tools\Gestao;

use App\Enums\TipoRelatorioClaude;
use App\Filament\Resources\ClaudeRelatorios\ClaudeRelatorioResource;
use App\Models\ClaudeRelatorio;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('registrar_relatorio')]
#[Description('Grava no PizzariaAPP (Indicadores › Relatórios do Claude) o texto final de uma rotina — resumo do dia, sugestão de compras ou anomalias — em Markdown, para o dono e os gerentes lerem no painel. Não altera nenhum outro dado. Chame uma vez, no fim da rotina.')]
class RegistrarRelatorio extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'tipo' => $schema->string()->enum(array_column(TipoRelatorioClaude::cases(), 'value'))->required(),
            'titulo' => $schema->string()->description('Ex.: "Resumo de sexta, 21/11".')->required(),
            'conteudo' => $schema->string()->description('Texto em Markdown, curto e objetivo.')->required(),
            'referencia' => $schema->string()->description('Dia a que o relatório se refere, AAAA-MM-DD.'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return true;
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'tipo' => ['required', Rule::enum(TipoRelatorioClaude::class)],
            'titulo' => ['required', 'string', 'max:150'],
            'conteudo' => ['required', 'string', 'max:20000'],
            'referencia' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $relatorio = ClaudeRelatorio::query()->create([...$dados, 'user_id' => $request->user()?->getAuthIdentifier()]);

        return Response::json([
            'relatorio_id' => $relatorio->id,
            'link' => ClaudeRelatorioResource::getUrl('view', ['record' => $relatorio]),
        ]);
    }
}
