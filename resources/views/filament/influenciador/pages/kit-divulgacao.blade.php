<x-filament-panels::page>
    @php
        $materiais = $this->materiais();
        $cupons = $this->cupons();
    @endphp

    @if ($link = $this->linkRastreavel())
        <x-filament::section>
            <x-slot name="heading">Seu link de divulgação</x-slot>

            <div class="flex flex-col gap-4 md:flex-row md:items-center">
                <img src="{{ $this->qrDoLink() }}" alt="QR do seu link" class="h-40 w-40 rounded-lg bg-white p-2" />

                <div class="space-y-2">
                    <p class="break-all font-mono text-sm">{{ $link }}</p>
                    <p class="text-sm text-gray-500">
                        Quem abrir o link cai no cardápio
                        @if ($cupomPrincipal = $this->cupomPrincipal())
                            com o cupom <strong>{{ $cupomPrincipal->cupom_codigo }}</strong> já preenchido.
                        @else
                            (escolha um cupom principal em "Meus Cupons" para ele já vir preenchido).
                        @endif
                    </p>
                    <button
                        type="button"
                        x-data="{ copiado: false }"
                        x-on:click="
                            navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($link) }});
                            copiado = true;
                            setTimeout(() => copiado = false, 2000);
                        "
                        class="fi-btn fi-btn-size-sm inline-flex items-center gap-1 rounded-lg bg-success-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-success-500"
                    >
                        <span x-show="! copiado">Copiar link</span>
                        <span x-show="copiado" x-cloak>Copiado!</span>
                    </button>
                </div>
            </div>
        </x-filament::section>
    @endif

    @if ($cupons->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Cupom para as legendas</x-slot>

            <select
                wire:model.live="cupomSelecionadoId"
                class="fi-select-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm"
            >
                @foreach ($cupons as $cupom)
                    <option value="{{ $cupom->id }}">{{ $cupom->cupom_codigo }}</option>
                @endforeach
            </select>
        </x-filament::section>
    @endif

    @if ($materiais->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500">Nenhum material de divulgação disponível no momento.</p>
        </x-filament::section>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ($materiais as $material)
            <x-filament::section>
                <x-slot name="heading">{{ $material->material_titulo }}</x-slot>

                @if ($material->material_descricao)
                    <p class="text-sm text-gray-500 mb-3">{{ $material->material_descricao }}</p>
                @endif

                @if ($material->material_arquivo_path)
                    <a
                        href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($material->material_arquivo_path) }}"
                        target="_blank"
                        class="fi-btn fi-btn-color-primary inline-flex items-center gap-1 mb-3 text-sm font-medium text-primary-600 hover:underline"
                    >
                        Baixar arquivo
                    </a>
                @endif

                @if ($material->material_conteudo_texto)
                    @php $legenda = $this->legendaPara($material); @endphp
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3 text-sm mb-3 whitespace-pre-line">{{ $legenda }}</div>

                    <button
                        type="button"
                        x-data="{ copiado: false }"
                        x-on:click="
                            navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($legenda) }});
                            copiado = true;
                            setTimeout(() => copiado = false, 2000);
                        "
                        class="fi-btn fi-btn-size-sm fi-btn-color-success inline-flex items-center gap-1 rounded-lg bg-success-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-success-500"
                    >
                        <span x-show="! copiado">Copiar legenda</span>
                        <span x-show="copiado" x-cloak>Copiado!</span>
                    </button>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
