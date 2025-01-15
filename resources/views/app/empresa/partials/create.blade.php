<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Nova Empresa') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para nova empresa.') }}
        </p>
    </header>

    <form action="{{ route('empresa.store') }}" method="post" class="mt-6 space-y-6" enctype="multipart/form-data">
        @csrf

        <div>
            <x-input-label for="empresa_razao_social" :value="__('Razão Social')" />
            <x-text-input id="empresa_razao_social" name="empresa_razao_social" type="text" class="mt-1 w-full" autocomplete="off" autofocus/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_razao_social')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_nome_fantasia" :value="__('Nome Fantasia')" />
            <x-text-input id="empresa_nome_fantasia" name="empresa_nome_fantasia" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_nome_fantasia')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_cnpj" :value="__('CNPJ')" />
            <x-text-input id="empresa_cnpj" name="empresa_cnpj" type="text" class="cnpj mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_cnpj')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_regime_tributario" :value="__('Regime Tributário')" />
            <select id="empresa_regime_tributario" name="empresa_regime_tributario" class="mt-1 w-full border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm">
                <option value="isento">Isento</option>
                <option value="microempreendedorIndividual">Microempreendedor Individual</option>
                <option value="simplesNacional">Simples Nacional</option>
                <option value="lucroPresumido">Lucro Presumido</option>
                <option value="lucroReal">Lucro Real</option>
                <option value="none">Nenhum</option>
            </select>
            <x-input-error :messages="$errors->updatePassword->get('empresa_regime_tributario')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_endereco_uf_estado" :value="__('UF Estado')" />
            <x-text-input id="empresa_endereco_uf_estado" name="empresa_endereco_uf_estado" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_endereco_uf_estado')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_endereco_cidade_id_ibge" :value="__('Cidade (ID IBGE)')" />
            <x-text-input id="empresa_endereco_cidade_id_ibge" name="empresa_endereco_cidade_id_ibge" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_endereco_cidade_id_ibge')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_endereco_rua" :value="__('Rua')" />
            <x-text-input id="empresa_endereco_rua" name="empresa_endereco_rua" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_endereco_rua')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_endereco_numero_endereco" :value="__('Número do Endereço')" />
            <x-text-input id="empresa_endereco_numero_endereco" name="empresa_endereco_numero_endereco" type="text" class="mt-1 w-full" autocomplete="off" value="S/N"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_endereco_numero_endereco')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_endereco_cep" :value="__('CEP')" />
            <x-text-input id="empresa_endereco_cep" name="empresa_endereco_cep" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_endereco_cep')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_endereco_complemento_endereco" :value="__('Complemento do Endereço')" />
            <x-text-input id="empresa_endereco_complemento_endereco" name="empresa_endereco_complemento_endereco" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_endereco_complemento_endereco')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_endereco_bairro" :value="__('Bairro')" />
            <x-text-input id="empresa_endereco_bairro" name="empresa_endereco_bairro" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_endereco_bairro')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_endereco" :value="__('Endereço Completo')" />
            <x-text-input id="empresa_endereco" name="empresa_endereco" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_endereco')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_api_nfeio_conta_id" :value="__('Conta ID (API NFE.io)')" />
            <x-text-input id="empresa_api_nfeio_conta_id" name="empresa_api_nfeio_conta_id" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_api_nfeio_conta_id')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_api_nfeio_company_id" :value="__('Company ID (API NFE.io)')" />
            <x-text-input id="empresa_api_nfeio_company_id" name="empresa_api_nfeio_company_id" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_api_nfeio_company_id')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_api_nfeio_apikey" :value="__('API Key (NFE.io)')" />
            <x-text-input id="empresa_api_nfeio_apikey" name="empresa_api_nfeio_apikey" type="text" class="mt-1 w-full" autocomplete="off"/>
            <x-input-error :messages="$errors->updatePassword->get('empresa_api_nfeio_apikey')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="empresa_status" :value="__('Status')" />
            <select id="empresa_status" name="empresa_status" class="mt-1 w-full border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm">
                <option value="Active">Ativo</option>
                <option value="Inactive">Inativo</option>
            </select>
            <x-input-error :messages="$errors->updatePassword->get('empresa_status')" class="mt-2" />
        </div>

        <x-primary-button>
            {{ __('Cadastrar Nova Empresa') }}
        </x-primary-button>
    </form>
</section>
