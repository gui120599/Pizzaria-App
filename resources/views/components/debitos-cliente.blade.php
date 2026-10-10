{{--
    Débito de um cliente (App\Services\DebitosClienteService::porCliente()):
    cabeçalho com o total em aberto e, aberto, cada título com os pedidos que
    ele cobra — valor do pedido na venda e quanto dele ainda falta receber.
    Usado no relatório de débitos, na edição do cliente e na aba Pendentes do
    PDV (receber=true mostra o botão que abre o modal de recebimento;
    somenteVendas=true imprime o extrato só com os títulos de venda, como a aba;
    porMes=true separa os títulos pelo mês da compra, com subtotal).
--}}
@props(['debito', 'receber' => false, 'aberto' => false, 'imprimir' => true, 'somenteVendas' => false, 'porMes' => false])

@php
    $cliente = $debito['cliente'];
    $brl = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
    $quantidade = fn (float $q): string => rtrim(rtrim(number_format($q, 3, ',', '.'), '0'), ',');
@endphp

<div x-data="{ aberto: @js($aberto) }" {{ $attributes->class('bg-white dark:bg-gray-800') }}>
    <div class="flex items-center gap-3 p-4">
        <button type="button" x-on:click="aberto = ! aberto" class="flex min-w-0 flex-1 items-center gap-3 text-left">
            <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform dark:text-gray-500" x-bind:class="aberto && 'rotate-90'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
            </svg>
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $cliente?->cliente_nome ?? 'Sem cliente' }}</span>
                <span class="block text-xs text-gray-400 dark:text-gray-500">
                    {{ $debito['titulos']->count() }} {{ $debito['titulos']->count() === 1 ? 'título' : 'títulos' }}
                    · {{ $debito['titulos']->sum(fn ($titulo) => $titulo['pedidos']->count()) }} {{ $debito['titulos']->sum(fn ($titulo) => $titulo['pedidos']->count()) === 1 ? 'pedido' : 'pedidos' }}
                    @if ($cliente?->cliente_celular)
                        · {{ $cliente->cliente_celular }}
                    @endif
                    @if ($cliente?->cliente_limite_credito !== null)
                        · limite {{ $brl((float) $cliente->cliente_limite_credito) }}
                    @endif
                </span>
            </span>
        </button>

        <div class="flex shrink-0 items-center gap-3">
            @if ($debito['vencido'] > 0)
                <span class="inline-flex rounded bg-danger-50 px-1.5 py-0.5 text-[11px] font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">vencido {{ $brl($debito['vencido']) }}</span>
            @endif
            <span class="text-sm font-bold text-gray-800 dark:text-gray-100">{{ $brl($debito['total']) }}</span>
            @if ($imprimir && $cliente)
                <a href="{{ route('relatorios.debitos_clientes.imprimir', ['filters' => array_filter(['cliente_id' => $cliente->id, 'somente_vendas' => $somenteVendas ? 1 : null, 'agrupar_mes' => $porMes ? 1 : null])]) }}" target="_blank"
                    title="Imprimir extrato do cliente"
                    class="text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300">
                    <x-filament::icon icon="heroicon-o-printer" class="h-4 w-4" />
                </a>
            @endif
        </div>
    </div>

    <div x-show="aberto" x-cloak class="space-y-3 px-4 pb-4">
        @php
            // Sem agrupar: um grupo só, sem cabeçalho de mês.
            $grupos = $porMes
                ? \App\Services\DebitosClienteService::agruparPorMes($debito['titulos'])
                : collect([['mes' => null, 'total' => $debito['total'], 'titulos' => $debito['titulos']]]);
        @endphp
        @foreach ($grupos as $grupo)
        @if ($grupo['mes'])
            <div class="flex items-center justify-between border-b border-gray-200 pb-1 pt-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                <span>{{ $grupo['mes'] }}</span>
                <span>R$ {{ number_format($grupo['total'], 2, ',', '.') }}</span>
            </div>
        @endif
        @foreach ($grupo['titulos'] as $titulo)
            @php
                /** @var \App\Models\Lancamento $lancamento */
                $lancamento = $titulo['lancamento'];
                $venda = $titulo['venda'];
            @endphp
            <div wire:key="debito-titulo-{{ $lancamento->id }}" class="rounded-lg border border-gray-200 dark:border-white/10">
                <div class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-white/10">
                    <div class="min-w-0 text-xs">
                        <p class="font-semibold text-gray-700 dark:text-gray-200">
                            {{ $venda ? 'Venda #'.$venda->id : 'Título #'.$lancamento->id }}
                            @if ($venda?->created_at)
                                <span class="font-normal text-gray-400 dark:text-gray-500">· {{ $venda->created_at->format('d/m/Y H:i') }}</span>
                            @endif
                        </p>
                        <p class="text-gray-400 dark:text-gray-500">
                            Vencimento {{ $lancamento->vencimento?->format('d/m/Y') ?? '—' }}
                            @if ($titulo['vencido'])
                                <span class="font-semibold text-danger-600 dark:text-danger-400">· vencido</span>
                            @endif
                            @if ($titulo['recebido'] > 0)
                                · recebido {{ $brl($titulo['recebido']) }} de {{ $brl($titulo['valor']) }}
                            @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $brl($titulo['restante']) }}</span>
                        @if ($venda)
                            <a href="{{ route('lancamento.imprimir_fiado', ['id' => $lancamento->id]) }}" target="_blank"
                                title="Imprimir comprovante da venda"
                                class="text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300">
                                <x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4" />
                            </a>
                        @endif
                        @if ($receber)
                            <button wire:click="abrirModalRecebimento({{ $lancamento->id }})" type="button" class="text-xs font-semibold text-primary-700 hover:underline dark:text-primary-400">
                                Receber
                            </button>
                        @endif
                    </div>
                </div>

                <div class="divide-y divide-gray-100 text-xs dark:divide-white/5">
                    <div class="flex items-center gap-2 px-3 py-1 text-[10px] uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        <span class="flex-1">Origem</span>
                        <span class="w-20 text-right">Valor</span>
                        <span class="w-20 text-right">Em aberto</span>
                    </div>

                    @foreach ($titulo['pedidos'] as $linha)
                        @php
                            /** @var \App\Models\Pedido $pedido */
                            $pedido = $linha['pedido'];
                            // sessaoMesa tem withDefault: só é mesa se o pedido tiver sessão.
                            $mesa = $pedido->pedido_sessao_mesa_id ? $pedido->sessaoMesa->mesa : null;
                            $onde = $mesa ? ($mesa->mesa_nome ?? 'Mesa '.$mesa->mesa_numero) : $pedido->pedido_origem?->label();
                        @endphp
                        <div x-data="{ itens: false }" class="px-3 py-1.5">
                            <div class="flex items-center gap-2">
                                <button type="button" x-on:click="itens = ! itens" @disabled($linha['itens']->isEmpty())
                                    class="min-w-0 flex-1 truncate text-left text-gray-700 enabled:hover:underline dark:text-gray-300">
                                    Pedido #{{ $pedido->id }}
                                    <span class="text-gray-400 dark:text-gray-500">
                                        @if ($onde) · {{ $onde }} @endif
                                        @if ($pedido->pedido_datahora_abertura) · {{ $pedido->pedido_datahora_abertura->format('d/m/Y H:i') }} @endif
                                        @if ($linha['itens']->isNotEmpty()) · {{ $linha['itens']->count() }} {{ $linha['itens']->count() === 1 ? 'item' : 'itens' }} @endif
                                    </span>
                                </button>
                                <span class="w-20 shrink-0 text-right text-gray-500 dark:text-gray-400">{{ $brl($linha['valor']) }}</span>
                                <span class="w-20 shrink-0 text-right font-semibold text-gray-800 dark:text-gray-200">{{ $brl($linha['em_aberto']) }}</span>
                            </div>
                            @if ($linha['itens']->isNotEmpty())
                                <div x-show="itens" x-cloak class="mt-1 space-y-0.5 pl-3">
                                    @foreach ($linha['itens'] as $item)
                                        <div class="flex items-start gap-2 text-gray-500 dark:text-gray-400">
                                            <span class="min-w-0 flex-1">{{ $quantidade((float) $item->item_pedido_quantidade) }}x <x-item-nome :item="$item" :categoria="false" /></span>
                                            <span class="w-20 shrink-0 text-right">{{ $brl((float) $item->item_pedido_valor) }}</span>
                                            <span class="w-20 shrink-0"></span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @if ($titulo['ajuste'])
                        <div class="flex items-center gap-2 px-3 py-1.5">
                            <span class="min-w-0 flex-1 truncate text-gray-500 dark:text-gray-400">{{ $titulo['ajuste']['descricao'] }}</span>
                            <span class="w-20 shrink-0 text-right text-gray-500 dark:text-gray-400">{{ $brl($titulo['ajuste']['valor']) }}</span>
                            <span class="w-20 shrink-0 text-right font-semibold text-gray-800 dark:text-gray-200">{{ $brl($titulo['ajuste']['em_aberto']) }}</span>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
        @endforeach
    </div>
</div>
