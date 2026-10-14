<?php

namespace App\Observers;

use App\Models\CozinhaHistorico;
use App\Models\DispositivoCozinha;
use Illuminate\Database\Eloquent\Model;

/**
 * Trilha de auditoria dos cadastros do módulo de cozinha
 * (cozinha_historicos). Registrado via #[ObservedBy] em SetorProducao,
 * RotaProducao, FichaTecnica e DispositivoCozinha. O ping do tablet não
 * entra (seria uma linha a cada poll) e o hash do token nunca é gravado.
 */
class CozinhaAuditoriaObserver
{
    private const CAMPOS_IGNORADOS = ['created_at', 'updated_at', 'deleted_at', 'ultimo_ping_em', 'ultimo_ip'];

    private const CAMPOS_SIGILOSOS = ['token_hash'];

    public function created(Model $model): void
    {
        CozinhaHistorico::registrar($model, 'criado');
    }

    public function updated(Model $model): void
    {
        $campos = array_diff(array_keys($model->getChanges()), self::CAMPOS_IGNORADOS);

        if ($campos === []) {
            return;
        }

        $dados = [];

        foreach ($campos as $campo) {
            $dados[$campo] = in_array($campo, self::CAMPOS_SIGILOSOS, true)
                ? ['alterado' => true]
                : ['de' => $model->getRawOriginal($campo), 'para' => $model->getAttributes()[$campo] ?? null];
        }

        $evento = $model instanceof DispositivoCozinha && in_array('token_hash', $campos, true)
            ? ($model->token_hash === null ? 'pareado' : 'pareamento_gerado')
            : 'atualizado';

        CozinhaHistorico::registrar($model, $evento, $dados);
    }

    public function deleted(Model $model): void
    {
        if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
            return;
        }

        CozinhaHistorico::registrar($model, 'excluido');
    }

    public function restored(Model $model): void
    {
        CozinhaHistorico::registrar($model, 'restaurado');
    }
}
