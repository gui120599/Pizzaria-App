{{--
    Push do Painel do Garçom: módulo JS + sincronização silenciosa da inscrição.
    Registra o Service Worker, mas NÃO pede permissão: isso só acontece no botão
    "Ativar" da tela Notificações no celular.
--}}
<script>
    window.GarcomPushConfig = @js([
        'vapid' => (string) config('pizzaria.push.vapid.publica'),
        'sw' => route('filament.garcom.sw'),
        'escopo' => '/garcom/',
    ]);
</script>
@vite('resources/js/garcom-push.js')
@persist('garcom-push-sincronizador')
    @livewire(\App\Livewire\PushSincronizador::class)
@endpersist
