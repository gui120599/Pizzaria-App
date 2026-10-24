<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    <title>Cadastro em análise — {{ config('app.name', 'Laravel') }}</title>

    @vite(['resources/css/app.css'])
</head>

<body class="min-h-screen flex items-center justify-center bg-gray-950 px-4">
    <div class="max-w-md w-full text-center">
        <svg xmlns="http://www.w3.org/2000/svg" class="mx-auto h-14 w-14 text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z" />
        </svg>

        <h1 class="mt-4 text-xl font-semibold text-white">Cadastro recebido!</h1>

        <p class="mt-2 text-gray-400">
            Seu cadastro de influenciador foi enviado e está em análise pela nossa equipe.
            Assim que for aprovado, você poderá entrar no painel e criar seus cupons.
        </p>

        <a href="{{ route('cardapio') }}" class="mt-6 inline-block rounded-lg bg-amber-500 px-5 py-2.5 font-medium text-gray-950 hover:bg-amber-400">
            Voltar ao cardápio
        </a>
    </div>
</body>

</html>
