<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    {{--
        Quando o Livewire filho despacha 'itens-pedido-atualizados',
        o Alpine captura no window e atualiza o estado do form do Filament
        via $wire.set(), que aponta para data.<statePath> na página pai.
    --}}
    <div
        x-data
        x-on:itens-pedido-atualizados.window="$wire.set('data.{{ $getStatePath() }}', $event.detail.itens)"
    >
        @livewire(
            'pedido-produto-selector',
            [
                'pedidoId'      => $getRecord()?->id,
                'itensIniciais' => $getState() ?? [],
            ],
            key('pedido-itens-' . ($getRecord()?->id ?? 'new'))
        )
    </div>
</x-dynamic-component>
