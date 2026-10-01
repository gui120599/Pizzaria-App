{{--
    Modal "Link de entrega" — QR code + link assinado que o entregador escaneia
    pra confirmar a entrega pelo celular, sem precisar abrir o painel inteiro.
    Mesma URL assinada usada no ticket impresso (Pedido::linkScanEntrega()).
--}}
<div class="space-y-4 text-center">
    <div class="flex justify-center">
        <x-qrcode :data="$url" :size="220" />
    </div>

    <div class="flex items-center gap-2 rounded-lg bg-gray-50 p-2 text-left dark:bg-white/5">
        <input
            type="text"
            readonly
            value="{{ $url }}"
            class="fi-input flex-1 truncate border-0 bg-transparent p-0 text-xs text-gray-600 focus:ring-0 dark:text-gray-300"
            onclick="this.select()"
        />
    </div>

    <a
        href="{{ $whatsappUrl }}"
        target="_blank"
        class="fi-btn fi-btn-color-success inline-flex items-center justify-center gap-2 rounded-lg bg-success-600 px-4 py-2 text-sm font-semibold text-white"
    >
        <x-heroicon-o-chat-bubble-left-ellipsis class="h-4 w-4" />
        Enviar por WhatsApp
    </a>
</div>
