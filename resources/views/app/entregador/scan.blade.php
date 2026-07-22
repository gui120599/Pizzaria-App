<x-entregador-layout>
    <div class="flex flex-col items-center text-center gap-4 py-6">

        @switch($acao)
            @case('aceitar')
                <i class='bx bx-package text-6xl text-blue-500'></i>
                <h1 class="text-xl font-bold text-gray-800">Pedido #{{ $pedido->id }}</h1>
                <p class="text-gray-500 text-sm">Pronto pra sair pra entrega</p>

                <div class="w-full bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-left space-y-2">
                    <p class="font-semibold text-gray-800">{{ $pedido->cliente?->cliente_nome ?? 'Cliente não identificado' }}</p>
                    <p class="text-gray-600 text-sm">{{ $pedido->pedido_endereco_entrega ?? '—' }}</p>
                    <p class="font-bold text-gray-900">Total: R$ {{ number_format($pedido->pedido_valor_total, 2, ',', '.') }}</p>
                </div>

                <form method="POST" action="{{ route('entregador.scan.confirmar', $pedido) }}" class="w-full">
                    @csrf
                    <button type="submit" class="w-full py-3 bg-orange-500 hover:bg-orange-600 active:scale-95 text-white font-bold rounded-lg text-base flex items-center justify-center gap-2 transition-all">
                        <i class='bx bx-cycling text-xl'></i> Confirmar Saída para Entrega
                    </button>
                </form>
            @break

            @case('entregar')
                <i class='bx bx-cycling text-6xl text-green-600'></i>
                <h1 class="text-xl font-bold text-gray-800">Pedido #{{ $pedido->id }}</h1>
                <p class="text-gray-500 text-sm">Você está com este pedido em transporte</p>

                <div class="w-full bg-white rounded-xl shadow-sm border border-gray-200 p-4 text-left space-y-2">
                    <p class="font-semibold text-gray-800">{{ $pedido->cliente?->cliente_nome ?? 'Cliente não identificado' }}</p>
                    <p class="text-gray-600 text-sm">{{ $pedido->pedido_endereco_entrega ?? '—' }}</p>
                    <p class="font-bold text-gray-900">Total a cobrar: R$ {{ number_format($pedido->pedido_valor_total, 2, ',', '.') }}</p>
                </div>

                <form method="POST" action="{{ route('entregador.scan.confirmar', $pedido) }}" class="w-full">
                    @csrf
                    <button type="submit" class="w-full py-3 bg-green-600 hover:bg-green-700 active:scale-95 text-white font-bold rounded-lg text-base flex items-center justify-center gap-2 transition-all">
                        <i class='bx bx-check-double text-xl'></i> Confirmar Entrega ao Cliente
                    </button>
                </form>
            @break

            @case('saiu')
                <i class='bx bx-check-circle text-6xl text-green-500'></i>
                <h1 class="text-xl font-bold text-gray-800">Saída confirmada!</h1>
                <p class="text-gray-500 text-sm">Pedido #{{ $pedido->id }} está em transporte.</p>
            @break

            @case('entregue')
                <i class='bx bxs-party text-6xl text-green-500'></i>
                <h1 class="text-xl font-bold text-gray-800">Entrega confirmada!</h1>
                <p class="text-gray-500 text-sm">Pedido #{{ $pedido->id }} entregue. Bom apetite! 🍕</p>
            @break

            @case('indisponivel')
            @case('erro')
                <i class='bx bx-error-circle text-6xl text-red-400'></i>
                <h1 class="text-xl font-bold text-gray-800">Pedido #{{ $pedido->id }} indisponível</h1>
                <p class="text-gray-500 text-sm">{{ $motivo }}</p>
            @break
        @endswitch

        <a href="{{ route('entregador.painel') }}" class="mt-4 flex items-center gap-1.5 text-sm text-teal-600 hover:text-teal-800">
            <i class='bx bx-arrow-back'></i> Voltar ao painel
        </a>
    </div>
</x-entregador-layout>
