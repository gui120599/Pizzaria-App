<?php

namespace App\Observers;

use App\Models\Influenciador;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class InfluenciadorObserver
{
    public function created(Influenciador $influenciador): void
    {
        Log::info('Influenciador criado', [
            'id' => $influenciador->id,
            'email' => $influenciador->email,
        ]);
    }

    public function updated(Influenciador $influenciador): void
    {
        Log::info('Influenciador atualizado', [
            'id' => $influenciador->id,
            'alteracoes' => Arr::except($influenciador->getChanges(), ['password', 'remember_token']),
        ]);
    }

    public function deleted(Influenciador $influenciador): void
    {
        Log::info('Influenciador removido', ['id' => $influenciador->id]);
    }
}
