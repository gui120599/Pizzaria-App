<?php

namespace App\Filament\Widgets;

use App\Enums\Checklist\RespostaProducao;
use App\Enums\Checklist\TipoResposta;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistExecucaoItem;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Widget;

/**
 * Top 10 itens "fazer se precisar" mais respondidos com "Tinha estoque" nos
 * últimos 30 dias. Item dispensado quase todo dia pede frequência menor
 * (dias da semana) ou critério revisto.
 */
class ChecklistItensMaisDispensadosWidget extends Widget
{
    use HasWidgetShield;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.checklist-itens-mais-dispensados';

    /** @return array{itens: list<object{titulo: string, modelo_nome: string, dispensadas: int, execucoes: int}>} */
    protected function getViewData(): array
    {
        $desde = ChecklistExecucao::dataOperacional()->subDays(29)->format('Y-m-d');

        $itens = ChecklistExecucaoItem::query()
            ->join('checklist_execucoes', 'checklist_execucoes.id', '=', 'checklist_execucao_itens.checklist_execucao_id')
            ->whereNull('checklist_execucoes.deleted_at')
            ->where('checklist_execucao_itens.tipo_resposta', TipoResposta::Producao)
            ->whereNotNull('checklist_execucao_itens.respondido_em')
            ->whereDate('checklist_execucoes.data_referencia', '>=', $desde)
            ->groupBy('checklist_execucao_itens.titulo', 'checklist_execucoes.modelo_nome')
            ->selectRaw('checklist_execucao_itens.titulo, checklist_execucoes.modelo_nome, SUM(checklist_execucao_itens.valor_opcao = ?) as dispensadas, COUNT(*) as execucoes', [RespostaProducao::TinhaEstoque->value])
            ->havingRaw('SUM(checklist_execucao_itens.valor_opcao = ?) > 0', [RespostaProducao::TinhaEstoque->value])
            ->orderByDesc('dispensadas')
            ->orderBy('checklist_execucao_itens.titulo')
            ->limit(10)
            ->toBase()
            ->get()
            ->all();

        return ['itens' => $itens];
    }
}
