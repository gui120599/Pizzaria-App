<?php

namespace App\Observers;

use App\Models\Influenciador;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InfluenciadorObserver
{
    /** Slug do link rastreável /r/{slug}: derivado do nome, único. */
    public function creating(Influenciador $influenciador): void
    {
        if (filled($influenciador->influenciador_slug)) {
            return;
        }

        $base = Str::slug((string) $influenciador->influenciador_nome) ?: 'influenciador';
        $slug = $base;
        $i = 2;

        while (Influenciador::withTrashed()->where('influenciador_slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        $influenciador->influenciador_slug = $slug;
    }

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
