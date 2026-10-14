{{--
    Card de um item de produção (spec §6.4). Faixa superior na cor do timer
    (Alpine, relógio único da tela), produto grande, sabores lado a lado no
    meio a meio, SEM em vermelho, + em verde, observação em amarelo e os
    selos. Botões grandes (≥ 64 px): INICIAR / PRONTO / CIENTE.

    @var \App\Models\ProducaoItem $item
    @var bool $compacto  coluna "Prontos"
--}}
@php
    use App\Enums\Cozinha\PrioridadeProducao;
    use App\Enums\Cozinha\StatusItemProducao;
    use App\Support\FormatoQuantidade;

    $s = (array) $item->snapshot;
    $setor = $item->setor;
    $sabores = (array) ($s['sabores'] ?? []);
    $ciencia = $this->esperaCiencia($item);
    $bloqueado = $this->bloqueadoSemOperador($item);
    $cancelado = $item->status === StatusItemProducao::Cancelado;
    $selos = array_filter([
        $item->prioridade === PrioridadeProducao::Urgente ? ['URGENTE', 'bg-danger-600'] : null,
        $item->prioridade === PrioridadeProducao::Alta ? ['ALTA', 'bg-warning-600'] : null,
        ($s['adicional'] ?? false) ? ['ADICIONAL', 'bg-info-600'] : null,
        $item->alterado_apos_inicio_em ? ['ALTERADO', 'bg-warning-600'] : null,
        $cancelado ? ['CANCELADO', 'bg-danger-700'] : null,
        $item->geracao > 1 ? ['REFAZER', 'bg-fuchsia-700'] : null,
    ]);
    $botao = 'flex min-h-16 flex-1 items-center justify-center rounded-xl px-3 text-lg font-extrabold tracking-wide text-white transition active:scale-[.98] disabled:cursor-not-allowed disabled:opacity-40';
@endphp

<article
    wire:key="producao-{{ $item->id }}-{{ $item->versao }}"
    x-data="cozinhaTimer({{ $item->liberado_em?->getTimestampMs() ?? 'null' }}, {{ (int) ($setor?->tempo_alerta_min ?? 10) }}, {{ (int) ($item->tempo_previsto_min ?? $setor?->tempo_alvo_min ?? 15) }}, {{ (int) config('cozinha.alerta_atraso_multiplicador', 2) }})"
    @class([
        'overflow-hidden rounded-xl bg-gray-900 ring-1 ring-white/10',
        'opacity-70' => $cancelado,
        'ring-2 ring-danger-500' => $ciencia,
    ])
>
    {{-- Faixa: pedido, origem, idade --}}
    <header
        class="flex items-center gap-2 px-3 py-1.5 text-sm font-bold text-white"
        x-bind:class="{
            'bg-success-700': cor === 'verde',
            'bg-warning-600': cor === 'amarelo',
            'bg-danger-600': cor === 'vermelho',
            'bg-danger-600 animate-pulse': cor === 'atrasado',
            'bg-gray-700': cor === 'cinza',
        }"
    >
        <span class="tabular-nums">#{{ $item->pedido_id }}</span>
        <span class="truncate">· {{ $item->referencia }}</span>
        @if (count($this->setorIds) > 1 && $setor)
            <span class="rounded bg-black/25 px-1.5 text-[11px] uppercase">{{ $setor->nome }}</span>
        @endif
        @if ($item->etapa_total > 1)
            <span class="rounded bg-black/25 px-1.5 text-[11px]">etapa {{ $item->etapa }}/{{ $item->etapa_total }}</span>
        @endif
        <span class="ml-auto font-mono text-base tabular-nums" x-text="idade"></span>
    </header>

    <div @class(['space-y-2 px-3 py-2', 'py-1.5' => $compacto])>
        @if ($selos !== [])
            <div class="flex flex-wrap gap-1">
                @foreach ($selos as [$texto, $cor])
                    <span class="rounded px-1.5 py-0.5 text-[11px] font-extrabold tracking-wide text-white {{ $cor }}">{{ $texto }}</span>
                @endforeach
            </div>
        @endif

        <div @class(['font-extrabold leading-tight text-white', 'text-2xl' => ! $compacto, 'text-base' => $compacto])>
            @if ((float) $item->quantidade !== 1.0)
                <span class="text-primary-400">{{ FormatoQuantidade::item($item->quantidade) }}×</span>
            @endif
            {{ $s['produto'] ?? 'Item' }}
            @if (($s['tamanho'] ?? null) && ($s['tamanho'] ?? null) !== ($s['produto'] ?? null) && $sabores === [])
                <span class="text-base font-semibold text-gray-400">· {{ $s['tamanho'] }}</span>
            @endif
        </div>

        @unless ($compacto)
            @if (count($sabores) > 1)
                <div class="grid divide-x-4 divide-gray-600 rounded-lg bg-gray-800" style="grid-template-columns: repeat({{ count($sabores) }}, minmax(0, 1fr))">
                    @foreach ($sabores as $sabor)
                        <div class="px-2 py-1.5">
                            <div class="text-xs font-bold uppercase text-gray-400">{{ $sabor['fracao'] ?? $sabor['rotulo'] ?? '' }}</div>
                            <div class="text-lg font-extrabold leading-tight text-white">{{ $sabor['nome'] }}</div>
                        </div>
                    @endforeach
                </div>
            @elseif (count($sabores) === 1)
                <div class="text-lg font-extrabold text-white">{{ $sabores[0]['nome'] }}</div>
            @endif

            @if ($s['borda'] ?? null)
                <div class="rounded-md bg-primary-600/20 px-2 py-1 text-base font-extrabold uppercase text-primary-300">Borda: {{ $s['borda'] }}</div>
            @endif

            @foreach ((array) ($s['respostas'] ?? []) as $resposta)
                @if ($resposta !== ($s['borda'] ?? null))
                    <div class="text-sm font-semibold text-gray-200">{{ $resposta }}</div>
                @endif
            @endforeach

            @foreach ((array) ($s['remocoes'] ?? []) as $remocao)
                <div class="text-base font-extrabold uppercase text-danger-400">SEM {{ $remocao['nome'] }}</div>
            @endforeach

            @foreach ((array) ($s['adicionais'] ?? []) as $adicional)
                <div class="text-base font-extrabold text-success-400">+ {{ (float) ($adicional['qtd'] ?? 1) > 1 ? FormatoQuantidade::item($adicional['qtd']).' ' : '' }}{{ $adicional['nome'] }}</div>
            @endforeach

            @if ($s['observacao'] ?? null)
                <div class="rounded-md bg-amber-300 px-2 py-1 text-sm font-bold text-gray-950">{{ $s['observacao'] }}</div>
            @endif

            @if ($item->alterado_apos_inicio_em && is_array($s['antes'] ?? null))
                @php
                    $antes = $s['antes'];
                    $resumoAntes = collect([
                        $antes['produto'] ?? null,
                        collect($antes['sabores'] ?? [])->pluck('nome')->implode(' / ') ?: null,
                        collect($antes['adicionais'] ?? [])->map(fn ($a) => '+ '.$a['nome'])->implode(', ') ?: null,
                        $antes['observacao'] ?? null,
                    ])->filter()->implode(' · ');
                @endphp
                <div class="rounded bg-warning-500/10 px-2 py-1 text-xs text-warning-300">Antes: {{ $resumoAntes }}</div>
            @endif

            @if (($s['alergenos'] ?? []) !== [])
                <div class="flex items-center gap-1 text-xs font-bold text-danger-300">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-4 w-4" />
                    {{ implode(', ', $s['alergenos']) }}
                </div>
            @endif

            @if ($item->status === StatusItemProducao::EmPreparo && $item->operador_nome)
                <div class="text-xs font-semibold text-gray-400">com {{ $item->operador_nome }}</div>
            @endif
        @endunless
    </div>

    {{-- Botões --}}
    <footer class="flex gap-2 px-3 pb-3">
        @if ($ciencia)
            <button type="button" wire:click="ciente({{ $item->id }})" class="{{ $botao }} bg-danger-600">
                CIENTE{{ $cancelado ? ' — CANCELADO' : ' — ALTERADO' }}
            </button>
        @elseif ($item->status === StatusItemProducao::NaFila)
            <button type="button" wire:click="iniciar({{ $item->id }}, {{ $item->versao }})" @disabled($bloqueado) class="{{ $botao }} bg-primary-600">INICIAR</button>
            @if ($setor?->permite_pular_inicio)
                <button type="button" wire:click="concluir({{ $item->id }}, {{ $item->versao }})" @disabled($bloqueado) class="{{ $botao }} bg-success-600">PRONTO</button>
            @endif
        @elseif ($item->status === StatusItemProducao::EmPreparo)
            <button type="button" wire:click="concluir({{ $item->id }}, {{ $item->versao }})" @disabled($bloqueado) class="{{ $botao }} bg-success-600">PRONTO</button>
        @elseif ($item->status === StatusItemProducao::Pronto)
            <button type="button" wire:click="desfazer({{ $item->id }})" @disabled($bloqueado) class="flex min-h-12 flex-1 items-center justify-center rounded-lg text-sm font-bold text-gray-200 ring-1 ring-white/20">Voltar ao preparo</button>
        @endif

        @unless ($compacto || $cancelado)
            <button type="button" wire:click="mountAction('ficha', { item: {{ $item->id }} })" class="flex min-h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-gray-800 text-gray-200 ring-1 ring-white/10" title="Ficha técnica">
                <x-filament::icon icon="heroicon-o-book-open" class="h-8 w-8" />
            </button>
        @endunless

        @if ($item->status === StatusItemProducao::EmPreparo && ! $ciencia)
            <div x-data="{ aberto: false }" class="relative shrink-0">
                <button type="button" x-on:click="aberto = ! aberto" x-on:click.outside="aberto = false" class="flex min-h-16 w-12 items-center justify-center rounded-xl bg-gray-800 text-xl text-gray-300 ring-1 ring-white/10" title="Mais"><x-filament::icon icon="heroicon-o-ellipsis-horizontal" class="h-7 w-7" /></button>
                <div x-show="aberto" x-cloak class="absolute bottom-full right-0 z-10 mb-1 w-56 rounded-lg bg-gray-800 py-1 shadow-xl ring-1 ring-white/10">
                    <button type="button" wire:click="devolver({{ $item->id }})" x-on:click="aberto = false" class="block w-full px-4 py-3 text-left text-base font-semibold text-gray-100 hover:bg-white/5">Devolver para a fila</button>
                </div>
            </div>
        @endif
    </footer>
</article>
