<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Nova Caixa') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para a nova caixa.') }}
        </p>
    </header>

    <form action="{{ route('caixa.store') }}" method="post" class="mt-6 space-y-6">
        @csrf

        <div>
            <x-input-label for="caixa_nome" :value="__('Nome da Caixa')" />
            <x-text-input id="caixa_nome" name="caixa_nome" type="text" class="mt-1 w-full" value="{{old('caixa_nome')}}" autocomplete="off" autofocus />
            <x-input-error :messages="$errors->updatePassword->get('caixa_nome')" class="mt-2" />
        </div>

        <x-primary-button>
            {{ __('Cadastrar Novo Caixa') }}
        </x-primary-button>
    </form>
</section>