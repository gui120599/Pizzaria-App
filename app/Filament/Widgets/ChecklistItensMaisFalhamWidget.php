<?php

namespace App\Filament\Widgets;

use App\Models\ChecklistExecucao;
use App\Models\ChecklistExecucaoItem;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Widget;

/** Top 10 itens com mais respostas não conformes nos últimos 30 dias. */
class ChecklistItensMaisFalhamWidget extends Widget
{
    use HasWidgetShield;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.checklist-itens-mais-falham';

    /** @return array{itens: list<object{titulo: string, modelo_nome: string, falhas: int}>} */
    protected function getViewData(): array
    {
        $desde = ChecklistExecucao::dataOperacional()->subDays(29)->format('Y-m-d');

        $itens = ChecklistExecucaoItem::query()
            ->join('checklist_execucoes', 'checklist_execucoes.id', '=', 'checklist_execucao_itens.checklist_execucao_id')
            ->whereNull('checklist_execucoes.deleted_at')
            ->where('checklist_execucao_itens.conforme', false)
            ->whereDate('checklist_execucoes.data_referencia', '>=', $desde)
            ->groupBy('checklist_execucao_itens.titulo', 'checklist_execucoes.modelo_nome')
            ->selectRaw('checklist_execucao_itens.titulo, checklist_execucoes.modelo_nome, COUNT(*) as falhas')
            ->orderByDesc('falhas')
            ->orderBy('checklist_execucao_itens.titulo')
            ->limit(10)
            ->toBase()
            ->get()
            ->all();

        return ['itens' => $itens];
    }
}
