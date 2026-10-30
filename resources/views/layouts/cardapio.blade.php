<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $titulo }}</title>
    <meta name="description" content="{{ $descricao }}">
    <meta name="theme-color" content="#14100D">

    <meta property="og:type" content="website">
    <meta property="og:locale" content="pt_BR">
    <meta property="og:site_name" content="Empório da Pizza">
    <meta property="og:title" content="{{ $titulo }}">
    <meta property="og:description" content="{{ $descricao }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('img/marca/og-cardapio.jpg') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/marca/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('img/marca/icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/marca/apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:600,700|figtree:400,500,600,700&display=swap" rel="stylesheet">
    <link rel="preload" as="image" href="{{ asset('img/marca/logo.webp') }}" imagesrcset="{{ asset('img/marca/logo.webp') }} 1x, {{ asset('img/marca/logo@2x.webp') }} 2x">

    @stack('head')

    @vite(['resources/css/cardapio.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body>
    {{ $slot }}
    @livewireScripts
</body>

</html>
