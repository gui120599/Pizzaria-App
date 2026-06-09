<x-app-layout>
    <div class="py-2 px-2 sm:px-6 lg:px-8">
        <div class="max-w-7xl mx-auto">

            {{-- Cabeçalho --}}
            <div class="flex items-center gap-3 mb-4">
                <a href="{{ route('sessaoMesa.pedidosMesa', ['mesa_id' => $mesa->id]) }}"
                   class="flex items-center gap-1 text-sm text-gray-500 hover:text-teal-600 transition">
                    <i class='bx bx-arrow-back'></i> Voltar
                </a>
                <span class="text-gray-300">|</span>
                <i class='bx bx-chair text-teal-600'></i>
                <h1 class="text-lg font-bold text-gray-800">
                    Novo Pedido — <span class="text-teal-600">{{ $mesa->mesa_nome }}</span>
                </h1>
                <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-yellow-100 text-yellow-700">
                    RASCUNHO
                </span>
                <span class="ml-auto text-xs text-gray-400">Sessão #{{ $sessaoMesa->id }}</span>
            </div>

            @if(session('success'))
                <div class="mb-4 px-4 py-2 bg-green-100 text-green-700 rounded-lg text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-4 px-4 py-2 bg-red-100 text-red-700 rounded-lg text-sm font-medium">
                    <i class='bx bx-error-circle mr-1'></i> {{ session('error') }}
                </div>
            @endif

            {{-- Seletor de produtos --}}
            @livewire('pedido-produto-selector', ['pedidoId' => $pedido->id, 'saveButtonLabel' => 'Abrir Pedido', 'sessaoMesaClientes' => $sessaoMesaClientes], key('mesa-pedido-'.$pedido->id))

            {{-- Inputs ocultos do formulário --}}
            <form id="pedido-form" action="{{ route('sessaoMesa.salvarNovoPedidoMesa', ['mesa_id' => $mesa->id]) }}" method="POST">
                @csrf
                <input type="hidden" name="pedido_id" value="{{ $pedido->id }}">
                <input type="hidden" name="pedido_usuario_garcom_id" value="{{ auth()->id() }}">
                <input type="hidden" name="pedido_opcaoentrega_id" value="1">
            </form>

        </div>
    </div>
</x-app-layout>
