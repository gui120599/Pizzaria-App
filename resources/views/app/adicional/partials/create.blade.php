<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Nova Adicional') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para o novo adicional.') }}
        </p>
    </header>

    <form action="{{ route('adicional.store') }}" method="post" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf
        <div class="w-full grid grid-cols-1 md:grid-cols-6 gap-x-4 gap-y-4">
            <div class="col-span-1 md:col-span-3 space-y-3">
                <div>
                    <x-input-label for="adicional_nome" :value="__('Nome do Adicional')" />
                    <x-text-input id="adicional_nome" name="adicional_nome" type="text" class="mt-1 w-full"
                        value="{{ old('adicional_nome') }}" autocomplete="off" autofocus />
                    <x-input-error :messages="$errors->updatePassword->get('adicional_nome')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="adicional_valor" :value="__('Adicional Valor')" />
                    <x-money-input id="adicional_valor" name="adicional_valor" type="text" class="mt-1 w-full money"
                        value="{{ old('adicional_valor') }}" autocomplete="off" autofocus />
                    <x-input-error :messages="$errors->updatePassword->get('adicional_valor')" class="mt-2" />
                </div>

            </div>
            {{-- Imagem --}}

            <div class="col-span-full md:col-span-3">
                <div class="flex justify-center items-center gap-x-2">
                    <i class='bx bxs-image'></i>
                    <x-input-label for="adicional_foto" :value="__('Foto do Adicional')" />
                </div>
                <div class="flex flex-col items-center justify-center gap-y-2">
                    <img id="imagem-preview" class="mborder rounded-lg object-contain w-40 h-40 p-1" />
                    <x-text-input id="adicional_foto" name="adicional_foto" type="file" class="cursor-pointer p-1 w-64 "
                        onchange="previewImage(this)" />
                </div>
                <x-input-error :messages="$errors->updatePassword->get('adicional_foto')" class="mt-2" />
            </div>
        </div>

        <x-primary-button>
            {{ __('Cadastrar Novo Adicional') }}
        </x-primary-button>
    </form>
    <script>
        function previewImage(input) {
            var preview = document.getElementById('imagem-preview');
            var file = input.files[0];
            var reader = new FileReader();

            reader.onloadend = function() {
                preview.src = reader.result;
            }

            if (file) {
                reader.readAsDataURL(file);
            } else {
                preview.src = "";
            }
        }
    </script>
</section>
