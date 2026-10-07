{{-- Modal "QR da mesa" no admin: imagem, link e situação do pedido pelo celular. --}}
<div class="space-y-3 text-center">
    <img src="{{ $qr }}" alt="QR {{ $mesa->mesa_nome }}" class="mx-auto h-56 w-56">
    <a href="{{ $url }}" target="_blank" rel="noopener" class="block break-all text-sm text-primary-600 underline dark:text-primary-400">{{ $url }}</a>
    @unless ($mesa->mesa_pedido_cliente_ativo)
        <p class="text-sm font-medium text-danger-600 dark:text-danger-400">O pedido pelo celular está desligado nesta mesa: o QR mostra só o aviso para chamar o garçom.</p>
    @endunless
</div>
