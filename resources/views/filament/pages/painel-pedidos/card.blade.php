{{--
    Card de pedido do Painel de Pedidos.

    UM card para todas as colunas. Na tela legada este markup estava duplicado
    quatro vezes (~200 linhas de template literal em JavaScript cada), com as
    variações espalhadas por ifs aninhados.

    O que varia por coluna:
    - endereço aparece quando o pedido exige entrega (flag da opção de entrega,
      não o `opcao_entrega.id === 3` hardcoded da tela antiga);
    - entregador aparece quando há um atribuído;
    - o aviso "já pago" aparece quando o pedido tem venda finalizada;
    - o botão primário vem de $acaoPrimaria.

    Relações do Pedido usam withDefault() (cliente, opcaoEntrega, sessaoMesa.mesa),
    então NUNCA vêm null: checar a FK, não o objeto.
--}}
@php
    use App\Enums\UrgenciaPedidoEnum;

    $urgencia = UrgenciaPedidoEnum::paraPedido($pedido, $status);
    $minutos = UrgenciaPedidoEnum::minutosNoStatus($pedido, $status);
    $itens = $pedido->item_pedido_pedido_id;
    $jaPago = $pedido->pedido_datahora_finalizado !== null;
@endphp

<div
    class="rounded-lg bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 {{ $urgencia->classeBorda() }}"
>
    {{-- Cabeçalho: número, tempo no status, forma de entrega --}}
    <div class="flex items-start justify-between gap-2 px-3 pt-2.5">
        <div class="min-w-0">
            <span class="text-base font-bold text-gray-950 dark:text-white">#{{ $pedido->id }}</span>

            @if ($pedido->pedido_sessao_mesa_id)
                <span class="ml-1 text-sm font-semibold text-primary-600 dark:text-primary-400">
                    {{ $pedido->sessaoMesa->mesa->mesa_nome }}
                </span>
            @endif

            <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                {{ $pedido->opcaoEntrega->opcaoentrega_nome }}
                @if ($pedido->pedido_cliente_id)
                    &middot; {{ $pedido->cliente->cliente_nome }}
                @endif
            </div>
        </div>

        {{-- Tempo no status atual. É a informação que faltava: antes o card
             mostrava só a hora de abertura e a cozinha calculava de cabeça. --}}
        <div class="shrink-0 text-right">
            @if ($minutos !== null)
                <span
                    @class([
                        'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums',
                        'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300' => $urgencia->ehNormal(),
                        'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400' => $urgencia === UrgenciaPedidoEnum::ATENCAO,
                        'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-400' => $urgencia === UrgenciaPedidoEnum::ATRASADO,
                    ])
                    title="{{ $urgencia->label() }} neste status"
                >
                    <x-heroicon-m-clock class="h-3.5 w-3.5" />
                    {{ $minutos }} min
                </span>
            @endif

            @if ($pedido->pedido_datahora_abertura)
                <div class="mt-0.5 text-[11px] text-gray-400 tabular-nums">
                    {{ $pedido->pedido_datahora_abertura->format('H:i') }}
                </div>
            @endif
        </div>
    </div>

    {{-- Endereço, só para quem sai para entrega --}}
    @if ($pedido->exigeEntrega() && $pedido->pedido_endereco_entrega)
        <div class="mt-2 flex items-start gap-1.5 px-3 text-xs text-gray-600 dark:text-gray-300">
            <x-heroicon-m-map-pin class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" />
            <span>{{ $pedido->pedido_endereco_entrega }}</span>
        </div>
    @endif

    @if ($pedido->pedido_usuario_entrega_id)
        <div class="mt-1 flex items-center gap-1.5 px-3 text-xs font-medium text-gray-600 dark:text-gray-300">
            <x-heroicon-m-truck class="h-3.5 w-3.5 shrink-0 text-gray-400" />
            <span>{{ $pedido->entregador->name_first }}</span>
        </div>
    @endif

    {{-- Itens --}}
    <ul class="mt-2 divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
        @foreach ($itens as $item)
            <li class="flex gap-2 px-3 py-1.5">
                <span class="w-6 shrink-0 text-center text-sm font-bold text-gray-950 tabular-nums dark:text-white">
                    {{ (int) $item->item_pedido_quantidade }}
                </span>

                <div class="min-w-0 flex-1">
                    <span class="text-sm font-semibold uppercase leading-tight text-gray-950 dark:text-white">
                        {{ $item->produto?->categoria?->categoria_nome }} {{ $item->produto?->produto_descricao }}
                    </span>

                    @foreach ($item->adicionaisItemPedido as $adicional)
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            + {{ (int) $adicional->aip_quantidade }} {{ $adicional->adicional?->adicional_nome }}
                        </div>
                    @endforeach

                    @if ($item->item_pedido_observacao)
                        <div class="text-xs font-medium text-amber-700 dark:text-amber-400">
                            OBS: {{ $item->item_pedido_observacao }}
                        </div>
                    @endif
                </div>

                <span class="shrink-0 text-xs font-semibold text-gray-500 tabular-nums dark:text-gray-400">
                    {{ Number::currency((float) $item->item_pedido_valor, 'BRL', 'pt_BR') }}
                </span>
            </li>
        @endforeach
    </ul>

    @if ($jaPago)
        <div class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-success-700 dark:text-success-400">
            <x-heroicon-m-check-badge class="h-4 w-4 shrink-0" />
            Já pago &middot; venda #{{ $pedido->pedido_venda_id }}
        </div>
    @endif

    {{-- Ações. Alvos de toque generosos: esta tela roda em tablet no balcão. --}}
    <div class="flex items-stretch gap-1 border-t border-gray-100 p-2 dark:border-white/5">
        @if ($podeCancelar)
            <button
                type="button"
                wire:click="mountAction('{{ $acaoCancelar }}', { pedido: {{ $pedido->id }} })"
                wire:loading.attr="disabled"
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-danger-600 ring-1 ring-danger-200 transition hover:bg-danger-50 disabled:opacity-50 dark:text-danger-400 dark:ring-danger-500/30 dark:hover:bg-danger-500/10"
                title="{{ $acaoCancelar === 'rejeitar' ? 'Rejeitar pedido' : 'Cancelar pedido' }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        @endif

        <a
            href="{{ route('pedido.imprimir', ['id' => $pedido->id]) }}"
            target="_blank"
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-500 ring-1 ring-gray-200 transition hover:bg-gray-50 dark:text-gray-400 dark:ring-white/10 dark:hover:bg-white/5"
            title="Imprimir pedido"
        >
            <x-heroicon-o-printer class="h-5 w-5" />
        </a>

        <a
            href="{{ \App\Filament\Pages\AtenderPedido::getUrl(['pedido' => $pedido->id]) }}"
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-gray-500 ring-1 ring-gray-200 transition hover:bg-gray-50 dark:text-gray-400 dark:ring-white/10 dark:hover:bg-white/5"
            title="Abrir no atendimento"
        >
            <x-heroicon-o-pencil-square class="h-5 w-5" />
        </a>

        @if ($acaoPrimaria)
            {{--
                wire:target COM os argumentos: sem eles o spinner acenderia em
                todos os cards da coluna. É cosmético, de todo modo — a garantia
                contra transição dupla é o lockForUpdate do PedidoStatusService.
            --}}
            <button
                type="button"
                wire:click="mountAction('{{ $acaoPrimaria }}', { pedido: {{ $pedido->id }} })"
                wire:target="mountAction('{{ $acaoPrimaria }}', { pedido: {{ $pedido->id }} })"
                wire:loading.attr="disabled"
                wire:loading.class="opacity-50 cursor-wait"
                class="flex h-11 flex-1 items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 text-sm font-semibold text-white transition hover:bg-primary-500 disabled:opacity-50"
            >
                {{ $rotuloPrimario }}
                <x-heroicon-m-arrow-right class="h-4 w-4" />
            </button>
        @endif
    </div>
</div>
