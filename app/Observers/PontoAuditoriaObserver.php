<?php

namespace App\Observers;

use App\Models\PontoHistorico;
use Illuminate\Database\Eloquent\Model;

/**
 * Trilha de auditoria do módulo de ponto (ponto_historicos). Registrado via
 * #[ObservedBy] em Colaborador, ColaboradorRemuneracao, ColaboradorEscala,
 * Escala, PontoPeriodo, Repasse e Adiantamento.
 *
 * Campos sensíveis (CPF, PIX, PIN) entram só como "alterado", sem valor.
 * Marcações e ajustes não passam por aqui: eles já são a própria trilha.
 */
class PontoAuditoriaObserver
{
    private const CAMPOS_IGNORADOS = ['created_at', 'updated_at', 'deleted_at'];

    private const CAMPOS_SENSIVEIS = ['cpf', 'cpf_hash', 'chave_pix', 'pin_quiosque', 'pin_lookup'];

    public function created(Model $model): void
    {
        PontoHistorico::registrar($model, 'criado');
    }

    public function updated(Model $model): void
    {
        $campos = array_diff(array_keys($model->getChanges()), self::CAMPOS_IGNORADOS);

        if ($campos === []) {
            return;
        }

        $dados = [];

        foreach ($campos as $campo) {
            $dados[$campo] = in_array($campo, self::CAMPOS_SENSIVEIS, true)
                ? 'alterado'
                : ['de' => $model->getRawOriginal($campo), 'para' => $model->getAttributes()[$campo] ?? null];
        }

        PontoHistorico::registrar($model, in_array('status', $campos, true) ? 'status_alterado' : 'atualizado', $dados);
    }

    public function deleted(Model $model): void
    {
        if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
            return;
        }

        PontoHistorico::registrar($model, 'excluido');
    }

    public function restored(Model $model): void
    {
        PontoHistorico::registrar($model, 'restaurado');
    }
}
