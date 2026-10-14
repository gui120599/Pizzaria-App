<?php

namespace App\Observers;

use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\FichaTecnicaPasso;
use App\Models\ProducaoItem;
use Illuminate\Database\Eloquent\Model;

/**
 * Sobe a versão da ficha quando ela muda DEPOIS de já ter sido usada na
 * produção (o item guarda ficha_versao). Mudança em ingrediente
 * (ficha_tecnica_itens) ou passo conta como mudança da ficha do produto.
 * Registrado via #[ObservedBy] em FichaTecnica, FichaTecnicaItem e
 * FichaTecnicaPasso.
 */
class FichaTecnicaVersaoObserver
{
    private const CAMPOS_IGNORADOS = ['versao', 'updated_at', 'created_at'];

    public function updating(Model $model): void
    {
        if (! $model instanceof FichaTecnica) {
            return;
        }

        $campos = array_diff(array_keys($model->getDirty()), self::CAMPOS_IGNORADOS);

        if ($campos !== [] && $model->isDirty('versao') === false && $this->foiUsada($model)) {
            $model->versao = $model->versao + 1;
        }
    }

    public function saved(Model $model): void
    {
        $this->bumpDaFicha($model);
    }

    public function deleted(Model $model): void
    {
        $this->bumpDaFicha($model);
    }

    private function bumpDaFicha(Model $model): void
    {
        $ficha = match (true) {
            $model instanceof FichaTecnicaItem => FichaTecnica::where('produto_id', $model->fti_produto_id)->first(),
            $model instanceof FichaTecnicaPasso => FichaTecnica::find($model->ficha_tecnica_id),
            default => null,
        };

        if ($ficha !== null && $this->foiUsada($ficha)) {
            FichaTecnica::whereKey($ficha->id)->where('versao', $ficha->versao)->increment('versao');
        }
    }

    private function foiUsada(FichaTecnica $ficha): bool
    {
        return ProducaoItem::where('ficha_tecnica_id', $ficha->id)->where('ficha_versao', $ficha->versao)->exists();
    }
}
