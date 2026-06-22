<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        /* Estilização da barra de rolagem */

        /* Ocultar a barra de rolagem e as setas (compatível com navegadores WebKit) */
        ::-webkit-scrollbar-button {
            width: 0;
            height: 0;
            display: none;
            /* Oculta as setas */
        }

        /* Estilo da barra de rolagem */
        ::-webkit-scrollbar {
            width: 8px;
            /* Define a largura da barra */
            height: 8px; /* Altura da barra no eixo horizontal */
            
        }

        ::-webkit-scrollbar-track {
            background: #1a1a1a;
            /* Cor de fundo da barra */
            border-radius: 8px;
        }

        ::-webkit-scrollbar-thumb {
            background: #555;
            /* Cor do indicador */
            border-radius: 8px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #888;
            /* Cor ao passar o mouse */
        }
    </style>
</head>

<body class="font-sans text-gray-900 antialiased bg-black">
    {{ $slot }}
    @livewireScripts
</body>

</html>
