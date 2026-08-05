@php
    // Mesma lógica de errors/403.blade.php: dentro do painel /admin usa o
    // estilo do Filament, fora dele usa o layout Blade do site operacional.
    $painelPadrao = \Filament\Facades\Filament::getCurrentOrDefaultPanel();
    $dentroDoFilament = \Filament\Facades\Filament::getCurrentPanel() !== null
        || ($painelPadrao && request()->is($painelPadrao->getPath().'/*', $painelPadrao->getPath()));
@endphp

@if ($dentroDoFilament)
    <x-filament-error-page
        code="404"
        icon="heroicon-o-magnifying-glass"
        title="Página não encontrada"
        message="A página que você está procurando não existe ou foi movida."
    />
@else
    <x-app-layout>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center space-x-2">
                <i class='bx bx-search-alt'></i>
                <span>Página não encontrada</span>
            </h2>
        </x-slot>
        <div class="py-6">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <div class="w-full">
                        <div class="text-center">
                            <h1 class="text-5xl font-bold text-red-600">404</h1>
                            <p class="text-xl mt-4">A página que você está procurando não existe ou foi movida.</p>
                            <a href="{{ route('dashboard') }}"
                                class="mt-6 inline-block text-blue-500 hover:underline">Voltar ao Painel de Controle</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </x-app-layout>
@endif
