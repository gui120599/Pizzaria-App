<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Registrar Movimentação de Saída') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para registrar uma movimentação do tipo SAÍDA.') }}
        </p>
    </header>

    <form action="{{ route('mov_saida.store') }}" method="post" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf
        <div
            class="sm:col-span-4 lg:col-span-3 col-span-6 relative md:space-y-2 md:border-x md:px-3 border-t pt-1 md:pt-0 pb-1 md:pb-0 md:border-t-0 border-b md:border-b-0">

            
            <x-input-label for="mov_sessaocaixa_id" :value="__('Cód. Sessão do Caixa')" />
            <x-text-input id="mov_sessaocaixa_id" name="mov_sessaocaixa_id" type="text"
                class="mt-1 w-full" autocomplete="off" value="{{ $sessaoCaixa->id }}"
                readonly />
            <x-input-error :messages="$errors->get('mov_sessaocaixa_id')" class="mt-2" />

            <x-input-label for="mov_descricao" :value="__('Descrição')" />
            <x-text-input id="mov_descricao" name="mov_descricao" type="text" class="mt-1 w-full"
                autocomplete="off" placeholder="Descrição da movimentação" />
            <x-input-error :messages="$errors->get('mov_descricao')" class="mt-2" />

            <x-input-label for="mov_valor" :value="__('Valor')" />
            <x-money-input id="mov_valor" name="mov_valor" type="text" class="mt-1 w-full" autocomplete="off" />

            <x-input-label for="mov_observacoes" :value="__('Observações')" />
            <x-text-area id="mov_observacoes" name="mov_observacoes" type="text" class="mt-1 w-full"
                autocomplete="off" placeholder="Observações" />
        </div>

        <x-primary-button>
            {{ __('Registrar Movimentação') }}
        </x-primary-button>

    </form>
</section>
