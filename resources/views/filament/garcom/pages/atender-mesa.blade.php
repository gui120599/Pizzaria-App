{{--
    Atendimento da mesa no Painel do Garçom. O PedidoProdutoSelector grava os
    itens direto no rascunho da rodada; o botão dele dispara #pedido-form, que
    aqui chama enviarRodada(). O poll só vigia rodadas prontas e o fechamento
    da conta — o selector é um componente filho e não re-renderiza com ele.

    Celular: abas Pedir | Rodadas | Conta ($aba). Desktop (≥ lg): cardápio
    sempre à esquerda e painel fixo à direita com Rodada atual | Rodadas |
    Conta ($abaPainel). Rodadas e Conta são renderizadas uma vez só e as
    classes responsivas decidem onde aparecem.
--}}
<x-filament-panels::page>
    @if ($sessaoId)
        @php
            $sessao = $this->sessao();
            $conta = $this->conta();
        @endphp

        <div wire:poll.{{ (int) config('pizzaria.salao.polling_mesa_segundos') }}s="atualizar" class="w-full space-y-4">
            @include('filament.garcom.partials.sem-conexao')
            @include('filament.garcom.partials.alerta-pronto')
            @include('filament.garcom.partials.mesa-qr')

            {{-- Cabeçalho da conta: quem/quanto tempo (e a conta pedida) separado das ações da mesa --}}
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                    <a href="{{ \App\Filament\Garcom\Pages\MapaMesas::getUrl() }}" wire:navigate
                       class="inline-flex min-h-11 items-center font-semibold text-primary-600 dark:text-primary-400">&larr; Mesas</a>
                    <span>{{ $sessao->sessao_mesa_pessoas }} pessoa(s)</span>
                    <span aria-hidden="true">·</span>
                    <span>Aberta há {{ (int) $sessao->created_at->diffInMinutes(now()) }} min</span>
                    <span aria-hidden="true">·</span>
                    <span>Garçom: {{ $sessao->garcom?->name_first ?: $sessao->garcom?->name }}</span>
                    @if ($sessao->contaSolicitada())
                        <span class="rounded-full bg-violet-600 px-3 py-1 text-sm font-bold text-white">Pediu a conta</span>
                    @endif
                    <span class="ml-auto hidden text-base font-bold text-gray-900 lg:inline dark:text-white">
                        Conta: R$ {{ number_format($conta['total'], 2, ',', '.') }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="mountAction('verQr')"
                            class="inline-flex min-h-11 items-center gap-1.5 rounded-full px-4 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 active:scale-95 dark:text-gray-200 dark:ring-white/20">
                        <x-filament::icon icon="heroicon-o-qr-code" class="h-5 w-5" />
                        QR da mesa
                    </button>
                    @if (config('pizzaria.mesa_cliente.exigir_pin') && $sessao->sessao_mesa_pin)
                        <span class="inline-flex min-h-11 items-center gap-2 rounded-full bg-gray-900 py-1 pl-4 pr-1.5 text-sm font-semibold text-white dark:bg-white dark:text-gray-900"
                              title="Informe este PIN a quem está na mesa para pedir pelo celular">
                            PIN <span class="font-mono text-base tracking-widest">{{ $sessao->sessao_mesa_pin }}</span>
                            <button type="button" wire:click="gerarNovoPin"
                                    wire:confirm="Gerar um novo PIN? Quem já entrou pelo celular continua na conta."
                                    class="grid size-9 place-items-center rounded-full hover:bg-white/20 dark:hover:bg-gray-900/10" title="Gerar novo PIN" aria-label="Gerar novo PIN">
                                <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" />
                            </button>
                        </span>
                    @endif
                </div>
            </div>

            {{-- Abas do celular --}}
            <div class="grid grid-cols-3 gap-1 rounded-2xl bg-gray-100 p-1 lg:hidden dark:bg-white/5">
                @foreach (['pedir' => 'Pedir', 'rodadas' => 'Rodadas', 'conta' => 'Conta'] as $valor => $rotulo)
                    <button type="button" wire:click="$set('aba', '{{ $valor }}')"
                            @class([
                                'rounded-xl py-3 text-base font-semibold transition',
                                'bg-white text-gray-900 shadow dark:bg-gray-800 dark:text-white' => $aba === $valor,
                                'text-gray-500 dark:text-gray-400' => $aba !== $valor,
                            ])>
                        {{ $rotulo }}
                    </button>
                @endforeach
            </div>

            <div class="lg:grid lg:grid-cols-12 lg:items-start lg:gap-6">
                {{-- Cardápio: aba Pedir no celular, coluna fixa no desktop --}}
                <div @class(['space-y-3 lg:col-span-7 lg:block 2xl:col-span-8', 'hidden' => $aba !== 'pedir'])>
                    <div class="lg:hidden">
                        @include('filament.garcom.partials.pessoas-mesa')
                    </div>

                    @php $pessoasMesa = $this->clientesDaMesa(); @endphp
                    @if ($editandoPedidoId)
                        <div class="flex items-center justify-between gap-2 rounded-xl bg-primary-50 px-4 py-2.5 text-sm text-primary-800 ring-1 ring-primary-200 dark:bg-primary-500/10 dark:text-primary-200 dark:ring-primary-500/30">
                            <span>Editando o pedido do cliente. Ao enviar, ele é aprovado.</span>
                            <button type="button" wire:click="cancelarEdicao" class="font-semibold underline">Parar</button>
                        </div>
                    @endif
                    {{-- Editando o pedido do cliente (QR), o seletor grava nele em vez do rascunho. --}}
                    @livewire('pedido-produto-selector', [
                        'pedidoId' => $editandoPedidoId ?? $rascunhoId,
                        'saveButtonLabel' => $editandoPedidoId ? 'Aprovar e enviar' : 'Enviar para a cozinha',
                        'sessaoMesaClientes' => $pessoasMesa,
                        'clientePadraoId' => $clientePadraoId,
                        'layoutDesktop' => true,
                    ], key('rodada-'.($editandoPedidoId ? 'qr'.$editandoPedidoId : $rascunhoId).'-'.($clientePadraoId ?? 0).'-'.count($pessoasMesa)))

                    <form id="pedido-form" wire:submit="enviarRodada" class="hidden"></form>
                </div>

                {{-- Painel lateral (desktop) + abas Rodadas/Conta do celular --}}
                <div class="space-y-4 lg:sticky lg:top-4 lg:col-span-5 lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto lg:pr-1 2xl:col-span-4">
                    <div class="hidden grid-cols-3 gap-1 rounded-2xl bg-gray-100 p-1 lg:grid dark:bg-white/5">
                        @foreach (['rodada' => 'Rodada atual', 'rodadas' => 'Rodadas', 'conta' => 'Conta'] as $valor => $rotulo)
                            <button type="button" wire:click="$set('abaPainel', '{{ $valor }}')"
                                    @class([
                                        'rounded-xl py-2.5 text-sm font-semibold transition',
                                        'bg-white text-gray-900 shadow dark:bg-gray-800 dark:text-white' => $abaPainel === $valor,
                                        'text-gray-500 dark:text-gray-400' => $abaPainel !== $valor,
                                    ])>
                                {{ $rotulo }}
                            </button>
                        @endforeach
                    </div>

                    @if ($abaPainel === 'rodada')
                        <div class="hidden space-y-4 lg:block">
                            @include('filament.garcom.partials.pessoas-mesa')
                            @include('filament.garcom.partials.carrinho-lateral', [
                                'acaoEnviar' => 'enviarRodada',
                                'tituloCarrinho' => $editandoPedidoId ? 'Pedido do cliente' : 'Rodada atual',
                                'rotuloEnviar' => $editandoPedidoId ? 'Aprovar e enviar' : null,
                            ])
                        </div>
                    @endif

                    <div @class([
                        'hidden' => $aba !== 'rodadas',
                        'lg:block' => $abaPainel === 'rodadas',
                        'lg:hidden' => $abaPainel !== 'rodadas',
                    ])>
                        @include('filament.garcom.partials.mesa-rodadas')
                    </div>

                    <div @class([
                        'hidden' => $aba !== 'conta',
                        'lg:block' => $abaPainel === 'conta',
                        'lg:hidden' => $abaPainel !== 'conta',
                    ])>
                        @include('filament.garcom.partials.mesa-conta')
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
