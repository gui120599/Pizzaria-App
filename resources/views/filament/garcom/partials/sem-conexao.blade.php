{{-- Faixa de "sem conexão": o navegador avisa quando o Wi-Fi do salão cai. --}}
<div x-data="{ online: navigator.onLine }"
     x-on:online.window="online = true"
     x-on:offline.window="online = false"
     x-show="! online"
     x-cloak
     class="sticky top-0 z-30 rounded-xl bg-red-600 px-4 py-3 text-center text-sm font-semibold text-white shadow">
    Sem conexão com o servidor. O que já foi enviado está salvo — aguarde o Wi-Fi voltar para enviar de novo.
</div>
