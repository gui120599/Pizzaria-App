<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Nova Categoria') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para nova Opção de entrega.') }}
        </p>
    </header>

    <form action="{{ route('opcoes_entregas.store') }}" method="post" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf

        <div>
            <x-input-label for="opcaoentrega_nome" :value="__('Nome da Opção de Entrega')" />
            <x-text-input id="opcaoentrega_nome" name="opcaoentrega_nome" type="text" class="mt-1 w-full" autocomplete="off" autofocus/>
            <x-input-error :messages="$errors->updatePassword->get('opcaoentrega_nome')" class="mt-2" />
        </div>
        <x-primary-button>
            {{ __('Cadastrar Nova Opção de Entrega') }}
        </x-primary-button>

    </form>
</section>
