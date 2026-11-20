<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\ChecklistNaoConformidade;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('checklists_hoje')]
#[Description('Checklists do dia operacional (como "Checklists de hoje"): status, progresso e quem iniciou, mais as não conformidades ainda pendentes.')]
#[IsReadOnly]
class ChecklistsHoje extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:checklists_dashboard');
    }

    protected function executar(Request $request): Response
    {
        $dia = ChecklistExecucao::dataOperacional();

        $modelos = ChecklistModelo::query()
            ->ativos()
            ->where(fn (Builder $query): Builder => $query
                ->agendadosPara($dia)
                ->orWhereHas('execucoes', fn (Builder $execucoes): Builder => $execucoes->doDia($dia)))
            ->with(['execucoes' => fn ($execucoes) => $execucoes->doDia($dia)->with('iniciadoPor')->latest('id')])
            ->orderBy('janela_inicio')
            ->get();

        $pendentes = ChecklistNaoConformidade::query()->pendentes()->latest('id')->limit(20)->get();

        return Response::json([
            'dia_operacional' => $dia->toDateString(),
            'checklists' => $modelos->map(function (ChecklistModelo $modelo): array {
                $execucao = $modelo->execucoes->first();

                return [
                    'checklist' => $modelo->nome,
                    'setor' => $modelo->setor?->getLabel(),
                    'janela' => $modelo->janelaDescricao(),
                    'status' => $execucao?->status->getLabel() ?? 'Não gerado',
                    'progresso' => $execucao ? "{$execucao->itens_respondidos}/{$execucao->total_itens}" : null,
                    'iniciado_por' => $execucao?->iniciadoPor?->name,
                ];
            })->all(),
            'nao_conformidades_pendentes' => ChecklistNaoConformidade::query()->pendentes()->count(),
            'ultimas_nao_conformidades' => $pendentes->map(fn (ChecklistNaoConformidade $nc): array => [
                'id' => $nc->id,
                'descricao' => $nc->descricao,
                'status' => $nc->status?->getLabel(),
                'aberta_em' => $nc->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
