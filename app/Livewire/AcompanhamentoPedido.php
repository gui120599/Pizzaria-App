<?php

namespace App\Livewire;

use App\Enums\TipoAtendimentoEnum;
use App\Models\AvaliacaoLink;
use App\Models\Empresa;
use App\Models\HorarioFuncionamento;
use App\Models\Pedido;
use App\Support\Pedido\EtapasAcompanhamento;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AcompanhamentoPedido extends Component
{
    /** Mesmo número do botão de WhatsApp do cardápio (cardapio/script.blade.php). */
    private const WHATSAPP_LOJA = '5564981380071';

    public int $pedidoId;

    /**
     * Definido pela assinatura da URL no primeiro request. Trancado para o
     * navegador não liberar os dados pessoais nos requests do wire:poll.
     */
    #[Locked]
    public bool $completo = false;

    public function render()
    {
        $pedido = Pedido::query()
            ->when($this->completo, fn ($q) => $q->with([
                'cliente',
                'opcaoEntrega',
                'item_pedido_pedido_id' => fn ($itens) => $itens
                    ->where('item_pedido_status', 'INSERIDO')
                    ->with('produto.categoria'),
            ]))
            ->find($this->pedidoId);

        $etapas = $pedido ? new EtapasAcompanhamento($pedido) : null;
        $retirada = $etapas?->tipo() === TipoAtendimentoEnum::RETIRADA;

        $deveAtualizar = $etapas && ! $etapas->cancelado() && ! $etapas->concluido();

        $avaliacaoLinks = $etapas?->concluido()
            ? AvaliacaoLink::where('avaliacao_link_ativo', true)
                ->orderBy('avaliacao_link_ordem')
                ->get()
                ->map(fn ($l) => [
                    'nome' => $l->avaliacao_link_nome,
                    'url' => $l->avaliacao_link_url,
                    'logo_url' => Storage::disk('public')->url($l->avaliacao_link_logo_url),
                ])
                ->all()
            : [];

        $empresa = $retirada ? Empresa::first() : null;

        return view('livewire.acompanhamento-pedido', [
            'pedido' => $pedido,
            'etapas' => $etapas,
            'retirada' => $retirada,
            'deveAtualizar' => $deveAtualizar,
            'avaliacaoLinks' => $avaliacaoLinks,
            'enderecoRetirada' => $empresa?->enderecoRetirada(),
            'linkComoChegar' => $empresa?->linkComoChegar(),
            'horarioHoje' => $retirada ? $this->horarioDeHoje() : null,
            'linkWhatsapp' => $pedido ? $this->linkWhatsapp($pedido) : null,
        ]);
    }

    /** "18:00 às 23:00" (ou vários turnos) para hoje; null se a loja não abre hoje. */
    private function horarioDeHoje(): ?string
    {
        $turnos = HorarioFuncionamento::where('horario_dia_semana', now()->dayOfWeek)
            ->where('horario_ativo', true)
            ->orderBy('horario_abertura')
            ->get()
            ->map(fn ($h) => substr($h->horario_abertura, 0, 5).' às '.substr($h->horario_fechamento, 0, 5));

        return $turnos->isEmpty() ? null : $turnos->implode(' e ');
    }

    private function linkWhatsapp(Pedido $pedido): string
    {
        return 'https://wa.me/'.self::WHATSAPP_LOJA.'?text='.rawurlencode("Olá! Sobre o pedido #{$pedido->id}.");
    }
}
