<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    <title>{{ config('app.name', 'Laravel') }} - Entregador</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="font-sans antialiased bg-gray-100 min-h-screen">
    <!-- Topo simples: sem sidebar/menu completo, feito pra celular -->
    <header class="bg-gray-900 text-white sticky top-0 z-40 shadow">
        <div class="max-w-lg mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class='bx bx-cycling text-2xl text-orange-400'></i>
                <span class="font-bold text-sm leading-tight">
                    {{ auth()->user()->name_first ?? auth()->user()->name }}
                </span>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex items-center gap-1 text-white/70 hover:text-white text-xs">
                    <i class='bx bx-log-out text-lg'></i> Sair
                </button>
            </form>
        </div>
    </header>

    <main class="max-w-lg mx-auto px-3 py-4 pb-10">
        {{ $slot }}
    </main>

    <div>
        <x-toats></x-toats>
    </div>

    @livewireScripts
</body>

</html>
