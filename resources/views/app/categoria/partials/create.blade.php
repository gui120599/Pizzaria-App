<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Nova Categoria') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para novo categoria.') }}
        </p>
    </header>

    <form action="{{ route('categoria.store') }}" method="post" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf

        <div>
            <x-input-label for="categoria_nome" :value="__('Nome do Categoria')" />
            <x-text-input id="categoria_nome" name="categoria_nome" type="text" class="mt-1 w-full" autocomplete="off" autofocus/>
            <x-input-error :messages="$errors->updatePassword->get('categoria_nome')" class="mt-2" />
        </div>
        <x-primary-button>
            {{ __('Cadastrar Nova Categoria') }}
        </x-primary-button>

    </form>
</section>
