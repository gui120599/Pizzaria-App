{{--
    Estação do tablet (spec §6.4). Topo fixo com setores, contadores, modo,
    treinamento e operador; faixa de checklist pendente; colunas Fila / Em
    preparo / Prontos (ou tickets por pedido) e o all-day recolhível.
--}}
@php
    $colunas = $this->colunas;
    $contadores = $this->contadores;
@endphp

<x-filament-panels::page>
    <div wire:poll.{{ $this->intervaloPolling() }}="verificarVersao" class="space-y-3">
        {{-- Topo --}}
        <div class="sticky top-0 z-20 -mx-2 flex flex-wrap items-center gap-2 rounded-xl bg-gray-900/95 px-3 py-2 ring-1 ring-white/10 backdrop-blur">
            @if (count($this->setorIds) > 1)
                <div class="flex flex-wrap gap-1">
                    <button type="button" wire:click="$set('setorAtivo', null)" @class(['rounded-full px-3 py-2 text-sm font-bold', 'bg-primary-600 text-white' => $setorAtivo === null, 'bg-gray-800 text-gray-300' => $setorAtivo !== null])>Todos</button>
                    @foreach ($this->setores as $setor)
                        <button type="button" wire:click="$set('setorAtivo', {{ $setor->id }})" @class(['rounded-full px-3 py-2 text-sm font-bold', 'text-white' => $setorAtivo === $setor->id, 'bg-gray-800 text-gray-300' => $setorAtivo !== $setor->id]) @style(["background-color: {$setor->cor}" => $setorAtivo === $setor->id])>{{ $setor->nome }}</button>
                    @endforeach
                </div>
            @else
                <span class="text-lg font-extrabold text-white">{{ $this->setores->first()?->nome ?? 'Nenhum setor' }}</span>
            @endif

            <div class="flex items-center gap-3 text-sm font-bold">
                <span class="text-gray-300">Fila <span class="text-lg text-white tabular-nums">{{ $contadores['fila'] }}</span></span>
                <span class="text-gray-300">Preparo <span class="text-lg text-warning-400 tabular-nums">{{ $contadores['preparo'] }}</span></span>
                <span class="text-gray-300">Atrasados <span @class(['text-lg tabular-nums', 'text-danger-400' => $contadores['atrasados'] > 0, 'text-gray-500' => $contadores['atrasados'] === 0])>{{ $contadores['atrasados'] }}</span></span>
            </div>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <div class="flex overflow-hidden rounded-lg ring-1 ring-white/10">
                    @foreach (['itens' => 'Itens', 'pedidos' => 'Pedidos'] as $valor => $rotulo)
                        <button type="button" wire:click="$set('modo', '{{ $valor }}')" @class(['px-3 py-2 text-sm font-bold', 'bg-primary-600 text-white' => $modo === $valor, 'bg-gray-800 text-gray-300' => $modo !== $valor])>{{ $rotulo }}</button>
                    @endforeach
                </div>
                <button type="button" wire:click="$toggle('treinamento')" @class(['rounded-lg px-3 py-2 text-sm font-bold ring-1 ring-white/10', 'bg-info-600 text-white' => $treinamento, 'bg-gray-800 text-gray-300' => ! $treinamento])>Treinamento</button>
                <button type="button" wire:click="$toggle('allDayAberto')" class="rounded-lg bg-gray-800 px-3 py-2 text-sm font-bold text-gray-300 ring-1 ring-white/10">All-day</button>
                @include('filament.cozinha.partials.operador')
                <span x-data class="font-mono text-lg font-bold text-gray-300 tabular-nums" x-text="new Date($store.relogio.agora).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })"></span>
            </div>
        </div>

        @if ($this->precisaEscolherOperador && $this->operador === null)
            <button type="button" wire:click="mountAction('trocarOperador')" class="w-full rounded-xl bg-warning-500 px-4 py-3 text-left text-base font-bold text-gray-950">
                Toque aqui e escolha quem está produzindo — sem operador, os setores que exigem ficam travados.
            </button>
        @endif

        {{-- Checklist pendente (spec §5.10.7) --}}
        @foreach ($this->pendenciasChecklist as $pendencia)
            <a
                href="{{ $pendencia['execucao_id'] ? \App\Filament\Cozinha\Pages\ExecutarChecklistCozinha::getUrl(['execucao' => $pendencia['execucao_id']]) : \App\Filament\Cozinha\Pages\ChecklistsCozinha::getUrl() }}"
                wire:navigate
                @class([
                    'flex items-center gap-3 rounded-xl px-4 py-2.5 text-base font-bold',
                    'bg-warning-500 text-gray-950' => $pendencia['cor'] === 'warning',
                    'bg-danger-600 text-white' => $pendencia['cor'] === 'danger',
                ])
            >
                <x-filament::icon icon="heroicon-o-clipboard-document-check" class="h-6 w-6 shrink-0" />
                <span>Checklist: {{ $pendencia['nome'] }}</span>
                <span class="font-semibold opacity-80">— {{ $pendencia['texto'] }}</span>
                <span class="ml-auto">Abrir →</span>
            </a>
        @endforeach

        <div @class(['grid gap-3', 'xl:grid-cols-[minmax(0,1fr)_16rem]' => $allDayAberto])>
            @if ($modo === 'pedidos')
                <div class="grid content-start gap-3 md:grid-cols-2 2xl:grid-cols-3">
                    @forelse ($this->tickets as $pedidoId => $itens)
                        <section wire:key="ticket-{{ $pedidoId }}" class="space-y-2 rounded-xl bg-gray-950/60 p-2 ring-1 ring-white/10">
                            <div class="flex items-center justify-between px-1">
                                <span class="text-lg font-extrabold text-white">#{{ $pedidoId }} · {{ $itens->first()->referencia }}</span>
                                @if ($itens->contains('status', \App\Enums\Cozinha\StatusItemProducao::NaFila))
                                    <button type="button" wire:click="iniciarPedido({{ $pedidoId }})" class="rounded-lg bg-primary-600 px-4 py-3 text-base font-extrabold text-white">INICIAR PEDIDO</button>
                                @endif
                            </div>
                            @foreach ($itens as $item)
                                @include('filament.cozinha.pages.estacao.card', ['item' => $item, 'compacto' => false])
                            @endforeach
                        </section>
                    @empty
                        <p class="col-span-full py-16 text-center text-xl font-semibold text-gray-500">Nenhum pedido na cozinha.</p>
                    @endforelse
                </div>
            @else
                <div class="grid content-start gap-3 md:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_16rem]">
                    @foreach ([['fila', 'Fila', $colunas['fila']], ['preparo', 'Em preparo', $colunas['preparo']]] as [$chave, $titulo, $itens])
                        <section class="space-y-2">
                            <h2 class="px-1 text-sm font-extrabold uppercase tracking-wider text-gray-400">{{ $titulo }} ({{ $itens->count() }})</h2>
                            @forelse ($itens as $item)
                                @include('filament.cozinha.pages.estacao.card', ['item' => $item, 'compacto' => false])
                            @empty
                                <p class="rounded-xl border border-dashed border-white/10 py-10 text-center text-base text-gray-500">Nada aqui.</p>
                            @endforelse
                        </section>
                    @endforeach

                    <section class="space-y-2 md:col-span-2 lg:col-span-1">
                        <h2 class="px-1 text-sm font-extrabold uppercase tracking-wider text-gray-400">Prontos ({{ config('cozinha.janela_prontos_min') }} min)</h2>
                        @forelse ($colunas['prontos'] as $item)
                            @include('filament.cozinha.pages.estacao.card', ['item' => $item, 'compacto' => true])
                        @empty
                            <p class="rounded-xl border border-dashed border-white/10 py-10 text-center text-sm text-gray-500">Nada pronto agora.</p>
                        @endforelse
                    </section>
                </div>
            @endif

            @if ($allDayAberto)
                <aside class="content-start space-y-1 self-start rounded-xl bg-gray-900 p-3 ring-1 ring-white/10 xl:sticky xl:top-20">
                    <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-400">All-day</h2>
                    @forelse ($this->allDay as $linha)
                        <div @class(['flex items-baseline justify-between gap-2 text-base', 'border-b border-white/10 pb-1 font-extrabold text-primary-300' => $linha['base'], 'text-gray-100' => ! $linha['base']])>
                            <span class="min-w-0 truncate">{{ $linha['rotulo'] }}</span>
                            <span class="shrink-0 text-lg font-extrabold tabular-nums">{{ $linha['quantidade'] }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Nada na fila.</p>
                    @endforelse
                </aside>
            @endif
        </div>
    </div>

    {{-- Toast "Desfazer" (10 s) depois de iniciar / marcar pronto --}}
    <div
        x-data="{ toast: null, timer: null }"
        x-on:cozinha-feito.window="
            clearTimeout(timer);
            toast = $event.detail;
            timer = setTimeout(() => toast = null, (toast.segundos ?? 10) * 1000);
        "
        class="pointer-events-none fixed inset-x-0 bottom-4 z-40 flex justify-center"
    >
        <template x-if="toast">
            <div class="pointer-events-auto flex items-center gap-4 rounded-xl bg-gray-800 px-5 py-3 text-base font-semibold text-white shadow-2xl ring-1 ring-white/20">
                <span x-text="toast.rotulo"></span>
                <button type="button" x-on:click="$wire.call(toast.desfazer, toast.id); toast = null" class="rounded-lg bg-white px-4 py-2 font-extrabold text-gray-950">DESFAZER</button>
            </div>
        </template>
    </div>
</x-filament-panels::page>
