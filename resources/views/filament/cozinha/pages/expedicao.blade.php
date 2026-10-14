{{-- Expedição (spec §6.6): um card por pedido, verde quando tudo ficou pronto. --}}
@php use App\Enums\Cozinha\StatusItemProducao; @endphp

<x-filament-panels::page>
    <div wire:poll.{{ $this->intervaloPolling() }}="verificarVersao" class="space-y-3">
        <div class="flex flex-wrap items-center gap-2 rounded-xl bg-gray-900 px-3 py-2 ring-1 ring-white/10">
            <span class="text-lg font-extrabold text-white">Expedição</span>
            <div class="flex overflow-hidden rounded-lg ring-1 ring-white/10">
                <button type="button" wire:click="$set('aba', 'ativos')" @class(['px-4 py-2 text-sm font-bold', 'bg-primary-600 text-white' => $aba === 'ativos', 'bg-gray-800 text-gray-300' => $aba !== 'ativos'])>Na cozinha ({{ $this->pedidos->count() }})</button>
                <button type="button" wire:click="$set('aba', 'despachados')" @class(['px-4 py-2 text-sm font-bold', 'bg-primary-600 text-white' => $aba === 'despachados', 'bg-gray-800 text-gray-300' => $aba !== 'despachados'])>Despachados (30 min)</button>
            </div>
            <div class="ml-auto">@include('filament.cozinha.partials.operador')</div>
        </div>

        <div class="grid content-start gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @forelse ($aba === 'ativos' ? $this->pedidos : $this->despachados as $pedido)
                @php $pronto = $this->estaPronto($pedido); @endphp
                <article wire:key="expedicao-{{ $pedido->id }}" @class([
                    'flex flex-col overflow-hidden rounded-xl ring-1',
                    'bg-success-950/60 ring-success-500' => $pronto,
                    'bg-gray-900 ring-white/10' => ! $pronto,
                ])>
                    <header class="flex items-center gap-2 px-3 py-2">
                        <span class="text-xl font-extrabold text-white">#{{ $pedido->id }}</span>
                        <span class="truncate text-base font-bold text-gray-200">{{ $pedido->producaoItens->first()?->referencia }}</span>
                        <span class="ml-auto text-sm font-semibold text-gray-400 tabular-nums">{{ $pedido->pedido_producao_iniciada_em?->format('H:i') }}</span>
                    </header>

                    <div class="flex flex-wrap gap-1.5 px-3">
                        @foreach ($this->progresso($pedido) as $setor)
                            <span class="rounded-full px-2 py-0.5 text-xs font-bold text-white" style="background-color: {{ $setor['cor'] }}">{{ $setor['setor'] }} {{ $setor['prontos'] }}/{{ $setor['total'] }}</span>
                        @endforeach
                    </div>

                    <ul class="mt-2 flex-1 divide-y divide-white/5 px-3">
                        @foreach ($pedido->producaoItens->filter(fn ($i) => $i->ehUltimaEtapa()) as $item)
                            <li class="flex items-center gap-2 py-1.5">
                                <span class="min-w-0 flex-1 text-base font-semibold text-gray-100">
                                    {{ \App\Support\FormatoQuantidade::item($item->quantidade) }}× {{ $item->snapshot['produto'] ?? 'Item' }}
                                    @if (($item->snapshot['sabores'] ?? []) !== [])
                                        <span class="text-sm text-gray-400">{{ collect($item->snapshot['sabores'])->pluck('nome')->implode(' / ') }}</span>
                                    @endif
                                </span>
                                <x-cozinha.status-chip :rotulo="$item->status->getLabel()" :cor="$item->status->getColor()" />
                                @if (in_array($item->status, [StatusItemProducao::Pronto, StatusItemProducao::Expedido], true))
                                    <button type="button" wire:click="mountAction('refazer', { item: {{ $item->id }} })" class="rounded-md px-2 py-1 text-xs font-bold text-danger-400 ring-1 ring-danger-500/40">Refazer</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if (($semProducao = $this->itensSemProducao($pedido))->isNotEmpty())
                        <div class="px-3 pb-1 text-sm font-bold text-info-300">Separar: + {{ $semProducao->implode(' · + ') }}</div>
                    @endif

                    @if ($pedido->item_pedido_pedido_id->pluck('item_pedido_observacao')->filter()->isNotEmpty())
                        <div class="mx-3 mb-1 rounded-md bg-amber-300 px-2 py-1 text-xs font-bold text-gray-950">
                            {{ $pedido->item_pedido_pedido_id->pluck('item_pedido_observacao')->filter()->implode(' · ') }}
                        </div>
                    @endif

                    @if ($aba === 'ativos')
                        <footer class="flex gap-2 p-3">
                            <button type="button" wire:click="mountAction('priorizar', { pedido: {{ $pedido->id }} })" class="min-h-14 rounded-xl bg-gray-800 px-4 text-sm font-bold text-gray-200 ring-1 ring-white/10">Priorizar</button>
                            <button
                                type="button"
                                wire:click="despachar({{ $pedido->id }})"
                                @disabled(! $pronto)
                                class="flex min-h-14 flex-1 items-center justify-center rounded-xl bg-success-600 px-3 text-base font-extrabold text-white disabled:bg-gray-700 disabled:text-gray-400"
                            >{{ $pronto ? $this->rotuloDespacho($pedido) : 'AGUARDANDO A COZINHA' }}</button>
                        </footer>
                    @endif
                </article>
            @empty
                <p class="col-span-full py-16 text-center text-xl font-semibold text-gray-500">
                    {{ $aba === 'ativos' ? 'Nenhum pedido na cozinha.' : 'Nada despachado nos últimos 30 minutos.' }}
                </p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
