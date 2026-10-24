<?php

namespace App\Observers;

use App\Models\Cupom;
use Illuminate\Support\Facades\Log;

class CupomObserver
{
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
