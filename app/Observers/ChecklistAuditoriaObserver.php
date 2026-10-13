<?php

namespace App\Observers;

use App\Models\ChecklistExecucaoItem;
use App\Models\ChecklistHistorico;
use Illuminate\Database\Eloquent\Model;

/**
 * Trilha de auditoria do módulo de checklists (checklist_historicos).
 * Registrado via #[ObservedBy] em ChecklistModelo, ChecklistExecucao,
 * ChecklistExecucaoItem e ChecklistNaoConformidade.
 *
 * Contadores da execução (recalculados a cada resposta) ficam de fora para
 * não gerar uma linha de histórico por toque na tela; a criação dos itens de
 * execução também (são o snapshot, criados junto com a execução).
 */
class ChecklistAuditoriaObserver
{
    private const CAMPOS_IGNORADOS = [
        'created_at',
        'updated_at',
        'deleted_at',
        'total_itens',
        'itens_respondidos',
        'itens_nao_conformes',
        'percentual_conformidade',
    ];

    public function created(Model $model): void
    {
        if ($model instanceof ChecklistExecucaoItem) {
            return;
        }

        $status = $model->getAttribute('status');

        ChecklistHistorico::registrar($model, 'criado', $status === null ? null : ['status' => $model->getRawOriginal('status')]);
    }

    public function updated(Model $model): void
    {
        $campos = array_diff(array_keys($model->getChanges()), self::CAMPOS_IGNORADOS);

        if ($campos === []) {
            return;
        }

        $dados = [];

        foreach ($campos as $campo) {
            $dados[$campo] = [
                'de' => $model->getRawOriginal($campo),
                'para' => $model->getAttributes()[$campo] ?? null,
            ];
        }

        $evento = match (true) {
            in_array('status', $campos, true) => 'status_alterado',
            $model instanceof ChecklistExecucaoItem => 'respondido',
            default => 'atualizado',
        };

        ChecklistHistorico::registrar($model, $evento, $dados);
    }

    public function deleted(Model $model): void
    {
        if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
            return;
        }

        ChecklistHistorico::registrar($model, 'excluido');
    }

    public function restored(Model $model): void
    {
        ChecklistHistorico::registrar($model, 'restaurado');
    }
}
