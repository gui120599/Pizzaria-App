{{--
    Uma coluna do Kanban.

    Em telas grandes as colunas viram grid; abaixo disso a faixa rola na
    horizontal com scroll-snap, e cada coluna ocupa quase a largura da tela.
    Uma markup só, sem duplicar o card por breakpoint.
--}}
@php
    use App\Enums\StatusPedidoEnum;

    $ehEntregue = $status === StatusPedidoEnum::ENTREGUE;
    $ehIniciado = $status === StatusPedidoEnum::INICIADO;

    // Qual ação é a primária desta coluna, e como se chama o botão.
    [$acaoPrimaria, $rotuloPrimario] = match ($status) {
        StatusPedidoEnum::INICIADO => ['confirmar', 'Confirmar'],
        StatusPedidoEnum::ABERTO => ['aceitar', 'Aceitar'],
        StatusPedidoEnum::PREPARANDO => ['avancar', 'Pronto'],
        StatusPedidoEnum::PRONTO => [$usaTransporte ? 'despachar' : 'avancar', $usaTransporte ? 'Despachar' : 'Entregue'],
        StatusPedidoEnum::EM_TRANSPORTE => ['avancar', 'Entregue'],
        default => [null, null],
    };

    // Pedido que ainda não entrou em produção é "rejeitado" (permission reject);
    // do preparo em diante é "cancelado", com os guards de mesa e pagamento.
    $acaoCancelar = in_array($status, [StatusPedidoEnum::INICIADO, StatusPedidoEnum::ABERTO], true)
        ? 'rejeitar'
        : 'cancelar';
@endphp

<section
    id="coluna-{{ Str::slug($status->value) }}"
    class="flex min-h-0 w-[88vw] shrink-0 snap-start flex-col sm:w-[46vw] lg:w-[32vw] xl:w-auto"
>
    {{-- Cabeçalho da coluna --}}
    <header
        @class([
            'flex items-center justify-between gap-2 rounded-t-xl px-3 py-2',
            'bg-gray-100 dark:bg-white/5' => $status->cor() === 'gray',
            'bg-info-100 dark:bg-info-500/15' => $status->cor() === 'info',
            'bg-warning-100 dark:bg-warning-500/15' => $status->cor() === 'warning',
            'bg-success-100 dark:bg-success-500/15' => $status->cor() === 'success',
        ])
    >
        <div class="flex min-w-0 items-center gap-1.5">
            <x-filament::icon :icon="$status->icone()" class="h-4 w-4 shrink-0 text-gray-600 dark:text-gray-300" />
            <h2 class="truncate text-sm font-bold text-gray-950 dark:text-white">
                {{ $status->labelColuna() }}
            </h2>
        </div>

        <span class="shrink-0 rounded-full bg-white/70 px-2 py-0.5 text-xs font-bold text-gray-700 tabular-nums dark:bg-gray-900/50 dark:text-gray-200">
            {{ $pedidos->count() }}
        </span>
    </header>

    {{-- Filtro de data, só na coluna Entregue (substitui o <input type="date">
         solto no header da tela legada). --}}
    @if ($ehEntregue)
        <div class="flex items-center gap-2 border-x border-gray-200 bg-gray-50 px-3 py-1.5 dark:border-white/10 dark:bg-white/5">
            <label for="dataEntregue" class="text-xs text-gray-500 dark:text-gray-400">Turno de</label>
            <input
                id="dataEntregue"
                type="date"
                wire:model.live="dataEntregue"
                class="min-w-0 flex-1 rounded-md border-gray-300 bg-white py-1 text-xs dark:border-white/10 dark:bg-gray-900 dark:text-white"
            />
        </div>
    @endif

    {{-- Corpo: rolagem independente por coluna. dvh, não vh — a barra de
         endereço do mobile muda a altura visível. --}}
    <div class="flex-1 space-y-2 overflow-y-auto overscroll-contain rounded-b-xl border border-gray-200 bg-gray-50 p-2 h-[calc(100dvh-15rem)] dark:border-white/10 dark:bg-white/[0.02]">
        @forelse ($pedidos as $pedido)
            <div wire:key="pedido-{{ $pedido->id }}">
                @include('filament.pages.painel-pedidos.card', [
                    'pedido' => $pedido,
                    'status' => $status,
                    'acaoPrimaria' => $acaoPrimaria,
                    'rotuloPrimario' => $rotuloPrimario,
                    'acaoCancelar' => $acaoCancelar,
                    'podeCancelar' => $status->podeSerCancelado(),
                ])
            </div>
        @empty
            <p class="px-2 py-6 text-center text-xs text-gray-400">
                @if ($ehIniciado)
                    Nenhum pedido do cardápio aguardando.
                @else
                    Nada aqui.
                @endif
            </p>
        @endforelse

        @if ($ehEntregue && ! $this->entregueExpandido && $pedidos->count() >= config('pizzaria.pedidos.limite_entregue', 50))
            <button
                type="button"
                wire:click="expandirEntregues"
                class="w-full rounded-lg py-2 text-xs font-semibold text-primary-600 transition hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-500/10"
            >
                Carregar mais entregues
            </button>
        @endif
    </div>
</section>
