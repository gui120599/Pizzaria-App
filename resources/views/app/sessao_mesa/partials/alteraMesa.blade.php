<section class="h-full">
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Alterar Mesa da sessão ' . $sessaoMesa->id) }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Mesas Disponíveis') }}
        </p>
    </header>

    <div class="p-6 text-gray-900 grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach ($mesasDisponiveis as $mesa)
            <form action="{{ route('sessao_mesa.updateAlterarMesa', ['sessaoMesa' => $sessaoMesa]) }}" method="POST"
                enctype="multipart/form-data">
                @csrf <!-- Token CSRF obrigatório -->
                @method('PATCH') <!-- Simula o método PATCH -->

                <!-- Input hidden para mesa_id_antiga -->
                <input id="mesa_id_antiga" name="mesa_id_antiga" type="hidden"
                    value="{{ $sessaoMesa->sessao_mesa_mesa_id }}" />

                <!-- Input hidden para mesa_id_nova -->
                <input id="mesa_id_nova" name="mesa_id_nova" type="hidden" value="{{ $mesa->id }}" />

                <button class="w-full p-4 bg-green-500 rounded-md text-white flex flex-col items-center justify-center"
                    type="submit">
                    <div class="flex flex-col items-center">
                        <i class='bx bx-chair text-xl'></i>
                        <span class="uppercase font-bold">{{ $mesa->mesa_nome }}</span>
                    </div>
                </button>
            </form>
        @endforeach
    </div>
    <p class="mt-1 text-sm text-gray-600">
        {{ __('Mesas Ocupadas') }}
    </p>
    <div class="p-6 text-gray-900 grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach ($mesasOcupadas as $mesa)
            <a class="w-full p-4 bg-red-500 rounded-md text-white flex flex-col items-center justify-center"
                href="{{ route('sessaoMesa', ['mesa_id' => $mesa->id]) }}">
                <div class="flex flex-col items-center">
                    <i class='bx bx-chair text-xl'></i>
                    <span class="uppercase font-bold">{{ $mesa->mesa_nome }}</span>
                    <span class="uppercase text-sm">Ir para Sessão</span>
                </div>
            </a>
        @endforeach
    </div>

</section>
