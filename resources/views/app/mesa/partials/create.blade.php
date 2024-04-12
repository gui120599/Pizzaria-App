<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Nova Mesa') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para a nova mesa.') }}
        </p>
    </header>

    <form action="{{ route('mesa.store') }}" method="post" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf

        <div>
            <x-input-label for="mesa_nome" :value="__('Nome da Mesa')" />
            <x-text-input id="mesa_nome" name="mesa_nome" type="text" class="mt-1 w-full" autocomplete="off" autofocus/>
            <x-input-error :messages="$errors->updatePassword->get('mesa_nome')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="status" :value="__('Status da Mesa')" />
            <select id="status" name="status" class="border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm cursor-pointer mt-1 w-full">
                <option value="LIBERADA" class="mt-1 w-full" style="cursor: pointer;">LIBERADA</option>
                <option value="OCUPADA" class="mt-1 w-full" style="cursor: pointer;">OCUPADA</option>
                <option value="INATIVA" class="mt-1 w-full" style="cursor: pointer;">INATIVA</option>
            </select>
            <x-input-error :messages="$errors->updatePassword->get('status')" class="mt-2" />
        </div>

        <x-primary-button>
            {{ __('Cadastrar Nova Mesa') }}
        </x-primary-button>

    </form>
</section>
