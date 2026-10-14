{{-- QR de pareamento do tablet: uso único, expira em poucos minutos. --}}
<div class="space-y-3 text-center">
    <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $dispositivo->nome }}</p>
    <img src="{{ $qr }}" alt="QR de pareamento" class="mx-auto h-56 w-56">
    <p class="text-sm text-gray-600 dark:text-gray-300">
        No tablet, abra a câmera e leia o QR (ou abra o link abaixo no navegador dele).
    </p>
    <a href="{{ $url }}" target="_blank" rel="noopener" class="block break-all text-xs text-primary-600 underline dark:text-primary-400">{{ $url }}</a>
    @if ($dispositivo->token_expira_em)
        <p class="text-xs font-medium text-warning-600 dark:text-warning-400">
            Vale uma vez, até {{ $dispositivo->token_expira_em->format('H:i') }}. Depois disso, gere outro.
        </p>
    @endif
</div>
