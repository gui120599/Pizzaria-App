<?php

namespace App\Observers;

use App\Models\Comissao;
use Illuminate\Support\Facades\Log;

class ComissaoObserver
{
    public function created(Comissao $comissao): void
    {
        Log::info('Comissão criada', [
            'id' => $comissao->id,
            'influenciador_id' => $comissao->comissao_influenciador_id,
            'pedido_id' => $comissao->comissao_pedido_id,
            'valor' => (string) $comissao->comissao_valor,
        ]);
    }

    public function updated(Comissao $comissao): void
    {
        Log::info('Comissão atualizada', [
            'id' => $comissao->id,
            'alteracoes' => $comissao->getChanges(),
        ]);
    }

    public function deleted(Comissao $comissao): void
    {
        Log::info('Comissão removida', ['id' => $comissao->id]);
    }
}
