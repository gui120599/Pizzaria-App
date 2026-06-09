<?php

namespace App\Livewire;

use App\Models\AvaliacaoLink;
use App\Models\Pedido;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class AcompanhamentoPedido extends Component
{
    public int $pedidoId;

    private static array $steps = [
        ['status' => 'INICIADO',      'label' => 'Pedido recebido',   'icon' => 'bx-receipt',       'desc' => 'Aguardando confirmação da loja'],
        ['status' => 'ABERTO',        'label' => 'Confirmado',        'icon' => 'bx-check-double',  'desc' => 'Pedido confirmado, será preparado em breve'],
        ['status' => 'PREPARANDO',    'label' => 'Sendo preparado',   'icon' => 'bxs-bowl-hot',     'desc' => 'Nossa equipe está preparando seu pedido'],
        ['status' => 'PRONTO',        'label' => 'Pronto',            'icon' => 'bx-package',       'desc' => 'Pronto, aguardando saída'],
        ['status' => 'EM TRANSPORTE', 'label' => 'Saiu para entrega', 'icon' => 'bx-cycling',       'desc' => 'Seu pedido está a caminho!'],
        ['status' => 'ENTREGUE',      'label' => 'Entregue',          'icon' => 'bxs-home-check',   'desc' => 'Pedido entregue! Bom apetite! 🍕'],
    ];

    public function render()
    {
        $pedido = Pedido::with([
            'cliente',
            'opcaoEntrega',
            'item_pedido_pedido_id' => fn ($q) => $q
                ->where('item_pedido_status', 'INSERIDO')
                ->with('produto.categoria'),
        ])->find($this->pedidoId);

        $steps      = static::$steps;
        $stepIndex  = null;
        $cancelado  = false;
        $finalizado = false;

        if ($pedido) {
            if ($pedido->pedido_status === 'CANCELADO') {
                $cancelado = true;
            } elseif ($pedido->pedido_status === 'FINALIZADO') {
                $finalizado = true;
                $stepIndex  = count($steps) - 1;
            } else {
                foreach ($steps as $i => $step) {
                    if ($step['status'] === $pedido->pedido_status) {
                        $stepIndex = $i;
                        break;
                    }
                }
            }
        }

        $avaliacaoLinks = in_array($pedido?->pedido_status, ['ENTREGUE', 'FINALIZADO'])
            ? AvaliacaoLink::where('avaliacao_link_ativo', true)
                ->orderBy('avaliacao_link_ordem')
                ->get()
                ->map(fn ($l) => [
                    'nome'     => $l->avaliacao_link_nome,
                    'url'      => $l->avaliacao_link_url,
                    'logo_url' => Storage::disk('public')->url($l->avaliacao_link_logo_url),
                ])
                ->all()
            : [];

        return view('livewire.acompanhamento-pedido', compact('pedido', 'steps', 'stepIndex', 'cancelado', 'finalizado', 'avaliacaoLinks'));
    }
}
