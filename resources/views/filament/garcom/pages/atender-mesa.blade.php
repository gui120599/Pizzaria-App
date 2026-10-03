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

            {{-- Cabeçalho da conta --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                <a href="{{ \App\Filament\Garcom\Pages\MapaMesas::getUrl() }}" wire:navigate
                   class="font-semibold text-primary-600 dark:text-primary-400">&larr; Mesas</a>
                <span>{{ $sessao->sessao_mesa_pessoas }} pessoa(s)</span>
                <span>Aberta há {{ (int) $sessao->created_at->diffInMinutes(now()) }} min</span>
                <span>Garçom: {{ $sessao->garcom?->name_first ?: $sessao->garcom?->name }}</span>
                @if ($sessao->contaSolicitada())
                    <span class="rounded-full bg-violet-600 px-2 py-0.5 text-xs font-semibold text-white">Pediu a conta</span>
                @endif
                <span class="ml-auto hidden text-base font-bold text-gray-900 lg:inline dark:text-white">
                    Conta: R$ {{ number_format($conta['total'], 2, ',', '.') }}
                </span>
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
                    @livewire('pedido-produto-selector', [
                        'pedidoId' => $rascunhoId,
                        'saveButtonLabel' => 'Enviar para a cozinha',
                        'sessaoMesaClientes' => $pessoasMesa,
                        'clientePadraoId' => $clientePadraoId,
                        'layoutDesktop' => true,
                    ], key('rodada-'.$rascunhoId.'-'.($clientePadraoId ?? 0).'-'.count($pessoasMesa)))

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
                            @include('filament.garcom.partials.carrinho-lateral', ['acaoEnviar' => 'enviarRodada', 'tituloCarrinho' => 'Rodada atual'])
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
