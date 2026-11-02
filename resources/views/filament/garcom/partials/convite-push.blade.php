{{--
    Convite para ativar o push neste aparelho. Aparece só quando o aparelho pode
    receber (ou precisa ser instalado) e ainda não está ativado; "Agora não"
    some por 7 dias neste aparelho. Não pede permissão: só leva à tela de ativação.
--}}
<div x-data="{
        mostrar: false,
        chave: 'garcomPushConviteAte',
        async iniciar() {
            try {
                if (Number(localStorage.getItem(this.chave)) > Date.now()) { return; }
            } catch (e) {}
            const esperar = () => {
                const suporte = window.GarcomPush.suporte();
                if (suporte !== 'ok' && suporte !== 'ios_instalar') { return; }
                window.GarcomPush.inscricaoAtual().then((inscricao) => {
                    this.mostrar = suporte === 'ios_instalar' || ! (inscricao && window.GarcomPush.permissao() === 'granted');
                });
            };
            window.GarcomPush ? esperar() : window.addEventListener('garcom-push:pronto', esperar, { once: true });
        },
        dispensar() {
            try { localStorage.setItem(this.chave, String(Date.now() + 7 * 24 * 3600 * 1000)); } catch (e) {}
            this.mostrar = false;
        },
    }"
    x-init="iniciar()"
    x-show="mostrar" x-cloak
    class="rounded-2xl bg-primary-50 p-4 ring-1 ring-primary-600/20 dark:bg-primary-500/10 dark:ring-primary-400/30">
    <div class="text-base font-semibold text-gray-950 dark:text-white">Receba os chamados mesmo com o celular no bolso</div>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
        Ative as notificações para ser avisado quando uma mesa chamar, pedir a conta ou tiver pedido pronto.
    </p>
    <div class="mt-3 flex items-center gap-4">
        <a href="{{ \App\Filament\Garcom\Pages\NotificacoesCelular::getUrl() }}" wire:navigate
           class="rounded-xl bg-primary-600 px-5 py-3 text-sm font-semibold text-white active:scale-[.98]">
            Ativar
        </a>
        <button type="button" x-on:click="dispensar()" class="text-sm font-medium text-gray-500 dark:text-gray-400">
            Agora não
        </button>
    </div>
</div>
