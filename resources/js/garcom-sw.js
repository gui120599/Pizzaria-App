// Service Worker do Painel do Garçom (escopo /garcom/), servido pela rota garcom.sw.
// Só mostra a notificação e leva ao painel: nenhuma ação de negócio roda aqui.

const PAINEL = '/garcom';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

// Sem cache e sem interceptação: o painel segue online como sempre. O ouvinte vazio só mantém o app instalável.
self.addEventListener('fetch', () => {});

// userVisibleOnly: todo push precisa virar notificação (exigência do iOS), então nunca há push silencioso.
self.addEventListener('push', (event) => {
    let dados = {};

    try {
        dados = event.data ? event.data.json() : {};
    } catch (e) {
        dados = {};
    }

    const opcoes = {
        body: dados.corpo || '',
        tag: dados.tag || undefined,
        renotify: Boolean(dados.tag),
        icon: dados.icone || '/img/razelfood/icon-192.png',
        timestamp: dados.criado_em || Date.now(),
        vibrate: [200, 100, 200],
        data: { url: dados.url || PAINEL, chave: dados.chave || null },
    };

    if (dados.badge) {
        opcoes.badge = dados.badge;
    }

    event.waitUntil(self.registration.showNotification(dados.titulo || 'Salão', opcoes));
});

// O clique só navega, e só para dentro do painel.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const destino = new URL((event.notification.data && event.notification.data.url) || PAINEL, self.location.origin);

    if (destino.origin !== self.location.origin || ! (destino.pathname === PAINEL || destino.pathname.startsWith(PAINEL + '/'))) {
        return;
    }

    event.waitUntil((async () => {
        const abertas = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const painel = abertas.find((janela) => {
            const caminho = new URL(janela.url).pathname;

            return caminho === PAINEL || caminho.startsWith(PAINEL + '/');
        });

        if (painel) {
            await painel.focus();

            if ('navigate' in painel) {
                return painel.navigate(destino.href);
            }

            return undefined;
        }

        return self.clients.openWindow(destino.href);
    })());
});

// Inscrição trocada pelo navegador com o painel fechado: a próxima abertura do painel regrava no servidor.
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(
        self.registration.pushManager
            .subscribe(event.oldSubscription ? event.oldSubscription.options : { userVisibleOnly: true })
            .catch(() => undefined),
    );
});
