<?php

namespace App\Observers;

use App\Models\Cupom;
use App\Services\CupomService;
use Illuminate\Support\Facades\Log;

class CupomObserver
{
    public function saving(Cupom $cupom): void
    {
        $cupom->cupom_codigo = Cupom::normalizarCodigo((string) $cupom->cupom_codigo);

        $service = app(CupomService::class);
        $service->validarTeto($cupom);
        $service->validarImutabilidade($cupom);
    }

    public function created(Cupom $cupom): void
    {
        Log::info('Cupom criado', [
            'id' => $cupom->id,
            'codigo' => $cupom->cupom_codigo,
            'influenciador_id' => $cupom->cupom_influenciador_id,
        ]);
    }

    public function updated(Cupom $cupom): void
    {
        Log::info('Cupom atualizado', [
            'id' => $cupom->id,
            'alteracoes' => $cupom->getChanges(),
        ]);
    }

    public function deleted(Cupom $cupom): void
    {
        Log::info('Cupom removido', ['id' => $cupom->id]);
    }
}
