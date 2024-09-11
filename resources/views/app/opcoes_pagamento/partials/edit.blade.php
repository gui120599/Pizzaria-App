<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Atualizar Opção de Pagamento') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para a opção de pagamento.') }}
        </p>
    </header>

    <form action="{{ route('opcoes_pagamento.update', ['opcoes_pagamento' => $opcoes_pagamento]) }}" method="post"
        class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf
        @method('patch')

        <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
            <div class="lg:col-span-4 md:col-span-6">
                <x-input-label for="opcaopag_nome" :value="__('Nome da Opção de Pagamento')" />
                <x-text-input id="opcaopag_nome" name="opcaopag_nome" type="text" class="mt-1 w-full"
                    autocomplete="off" value="{{ $opcoes_pagamento->opcaopag_nome }}" autofocus />
                <x-input-error :messages="$errors->updatePassword->get('opcaopag_nome')" class="mt-2" />
            </div>
        </div>
        <div class="md:col-span-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
            <div class="lg:col-span-2 md:col-span-3">
                <x-input-label for="opcaopag_desc_nfe" :value="__('Descrição para NFE')" />
                <x-select-input :options="$opcaopag_desc_nfe" value-field="opcaopag_desc_nfe" display-field="opcaopag_desc_nfe"
                    id="opcaopag_desc_nfe" name="opcaopag_desc_nfe" class="mt-1 w-full" />
                <x-input-error :messages="$errors->updatePassword->get('opcaopag_desc_nfe')" class="mt-2" />
            </div>
            <div class="lg:col-span-2 md:col-span-3">
                <x-input-label for="opcaopag_tipo_taxa" :value="__('Tipo da Taxa de Pagamento')" />
                <x-select-input :options="$tipos_taxas" value-field="id" display-field="opcaopag_tipo_taxa"
                    id="opcaopag_tipo_taxa" name="opcaopag_tipo_taxa" class="mt-1 w-full" />
                <x-input-error :messages="$errors->updatePassword->get('opcaopag_tipo_taxa')" class="mt-2" />
            </div>
            <div class="lg:col-span-2 md:col-span-3">
                <x-input-label for="opcaopag_valor_percentual_taxa" :value="__('% da Taxa de Pagamento')" />
                <x-text-input id="opcaopag_valor_percentual_taxa" name="opcaopag_valor_percentual_taxa" type="text"
                    class="mt-1 w-full" autocomplete="off"
                    value="{{ $opcoes_pagamento->opcaopag_valor_percentual_taxa }}" />
                <x-input-error :messages="$errors->updatePassword->get('opcaopag_valor_percentual_taxa')" class="mt-2" />
            </div>
        </div>
        <x-primary-button>
            {{ __('Atualizar Opção de Entrega') }}
        </x-primary-button>

    </form>
    <script type="module">
        $(document).ready(function() {
            // Função que seleciona automatico a opção vinda do banco de dados
            $('#opcaopag_tipo_taxa').val('{{ $opcoes_pagamento->opcaopag_tipo_taxa }}');
            $('#opcaopag_desc_nfe').val('{{ $opcoes_pagamento->opcaopag_desc_nfe }}');
        });
    </script>
</section>
