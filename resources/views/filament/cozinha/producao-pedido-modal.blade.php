{{--
    "Ver produção" do Painel de Pedidos: cada item de produção (unidade,
    etapa, refação) com o status atual e a linha do tempo dos eventos.

    @var \App\Models\Pedido $pedido
    @var \Illuminate\Support\Collection<int, \App\Models\ProducaoItem> $itens
--}}
@php
    use App\Enums\Cozinha\StatusItemProducao;
@endphp

@if ($itens->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">Este pedido não gerou itens para a cozinha.</p>
@else
    <div class="space-y-3">
        @foreach ($itens as $item)
            @php
                $snapshot = (array) $item->snapshot;
                $sabores = collect($snapshot['sabores'] ?? [])->map(fn ($s) => trim(($s['rotulo'] ?? '').' '.($s['nome'] ?? '')))->implode(' / ');
            @endphp
            <div class="rounded-lg ring-1 ring-gray-200 dark:ring-white/10" wire:key="producao-item-{{ $item->id }}">
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-3 py-2 dark:border-white/5">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $item->setor?->cor ?? '#9ca3af' }}"></span>
                    <span class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ \App\Support\FormatoQuantidade::item($item->quantidade) }}× {{ $snapshot['produto'] ?? 'Item' }}
                    </span>
                    @if ($sabores !== '')
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $sabores }}</span>
                    @endif
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        &middot; {{ $item->setor?->nome }}
                        @if ($item->etapa_total > 1)
                            (etapa {{ $item->etapa }}/{{ $item->etapa_total }})
                        @endif
                        @if ($item->geracao > 1)
                            &middot; refação {{ $item->geracao - 1 }}
                        @endif
                    </span>
                    <x-cozinha.status-chip class="ml-auto" :rotulo="$item->status->getLabel()" :cor="$item->status->getColor()" />
                </div>

                <ol class="space-y-1 px-3 py-2">
                    @foreach ($item->eventos as $evento)
                        <li class="flex gap-2 text-xs">
                            <span class="w-12 shrink-0 text-gray-400 tabular-nums">{{ $evento->created_at?->format('H:i:s') }}</span>
                            <span class="font-semibold text-gray-800 dark:text-gray-100">{{ $evento->acao->getLabel() }}</span>
                            <span class="min-w-0 text-gray-500 dark:text-gray-400">
                                {{ $evento->operador?->name_first ?? $evento->usuario?->name_first }}
                                @if ($evento->motivo)
                                    &middot; {{ $evento->motivo }}
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ol>

                @if ($item->status === StatusItemProducao::EmPreparo || $item->status === StatusItemProducao::NaFila)
                    <div class="border-t border-gray-100 px-3 py-1.5 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                        Na cozinha há {{ \App\Support\FormatoDuracao::minutos(intdiv($item->idadeSegundos(), 60)) }}
                        @if ($item->operador_nome)
                            &middot; com {{ $item->operador_nome }}
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
