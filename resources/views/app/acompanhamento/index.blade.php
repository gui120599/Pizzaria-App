<x-cardapio-layout titulo="Acompanhe seu pedido · Empório da Pizza" descricao="Acompanhe o andamento do seu pedido no Empório da Pizza.">
    @push('head')
        <meta name="robots" content="noindex, nofollow">
    @endpush

    @livewire('acompanhamento-pedido', ['pedidoId' => $pedidoId, 'completo' => $completo])
</x-cardapio-layout>
