<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Atualizar Mesa') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para a mesa.') }}
        </p>
    </header>

    <form action="{{ route('mesa.update', ['mesa' => $mesa]) }}" method="post" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="mesa_nome" :value="__('Nome da Mesa')" />
            <x-text-input id="mesa_nome" name="mesa_nome" type="text" class="mt-1 w-full" autocomplete="off" value="{{ $mesa->mesa_nome }}" autofocus/>
            <x-input-error :messages="$errors->updatePassword->get('mesa_nome')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="mesa_status" :value="__('Status da Mesa')" />
            <select id="mesa_status" name="mesa_status" class="border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm cursor-pointer mt-1 w-full">
                <option value="LIBERADA" @if($mesa->mesa_status === 'LIBERADA') selected @endif>LIBERADA</option>
                <option value="OCUPADA" @if($mesa->mesa_status === 'OCUPADA') selected @endif>OCUPADA</option>
                <option value="INATIVA" @if($mesa->mesa_status === 'INATIVA') selected @endif>INATIVA</option>
            </select>
            <x-input-error :messages="$errors->updatePassword->get('mesa_status')" class="mt-2" />
        </div>

        <x-primary-button>
            {{ __('Atualizar Mesa') }}
        </x-primary-button>

    </form>
</section>
