<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Atualizar Opção de Entrega') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para a opção de entrega.') }}
        </p>
    </header>

    <form action="{{ route('opcoes_entregas.update', ['opcoes_entregas'  => $opcoes_entregas]) }}" method="post" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="opcaoentrega_nome" :value="__('Nome do Opção de Entrega')" />
            <x-text-input id="opcaoentrega_nome" name="opcaoentrega_nome" type="text" class="mt-1 w-full" autocomplete="off" value="{{ $opcoes_entregas->opcaoentrega_nome }}" autofocus/>
            <x-input-error :messages="$errors->updatePassword->get('opcaoentrega_nome')" class="mt-2" />
        </div>
        <x-primary-button>
            {{ __('Atualizar Opção de Entrega') }}
        </x-primary-button>

    </form>
</section>
