<div x-data="{ open: false }" class="border-t border-gray-100">
    <button type="button" @click="open = !open"
            class="w-full flex items-center justify-between px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50">
        <span class="flex items-center gap-1.5">
            <i class='bx bx-qr-scan'></i> Ver QR Code do pedido
        </span>
        <i class='bx bx-chevron-down transition-transform' x-bind:class="open ? 'rotate-180' : ''"></i>
    </button>

    <div x-show="open" x-transition class="px-4 pb-3 flex flex-col items-center gap-2" style="display: none;">
        <x-qrcode :data="$pedido->linkScanEntrega()" :size="180" />
        <p class="text-gray-400 text-xs text-center">
            Reserva pra quando o ticket impresso se perder — escaneie com a câmera de outro celular.
        </p>
    </div>
</div>
