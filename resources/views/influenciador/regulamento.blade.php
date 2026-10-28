<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    <title>Regulamento do programa de influenciadores — {{ config('app.name', 'Laravel') }}</title>

    @vite(['resources/css/app.css'])
</head>

<body class="min-h-screen bg-gray-950 px-4 py-10">
    <div class="mx-auto max-w-2xl text-gray-300">
        <h1 class="text-xl font-semibold text-white">Regulamento do programa de influenciadores</h1>

        @if ($regulamento)
            <p class="mt-1 text-sm text-gray-500">Versão {{ $regulamento->ri_versao }} — publicada em {{ $regulamento->ri_publicado_em->format('d/m/Y') }}</p>
            <div class="mt-6 whitespace-pre-line leading-relaxed">{{ $regulamento->ri_texto }}</div>
        @else
            <p class="mt-6 text-gray-400">O regulamento ainda não foi publicado.</p>
        @endif
    </div>
</body>

</html>
