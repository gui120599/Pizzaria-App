// Web Push do Painel do Garçom: registra o Service Worker, detecta o aparelho e (des)inscreve.
// A permissão de notificação só é pedida em ativar(), chamado por um toque do garçom.
// Config vinda do servidor: window.GarcomPushConfig = { vapid, sw, escopo }.

const config = () => window.GarcomPushConfig || {};

function chaveParaBytes(base64Url) {
    const preenchida = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const binario = atob(preenchida);

    return Uint8Array.from(binario, (c) => c.charCodeAt(0));
}

function mesmaChave(subscription, vapid) {
    const atual = subscription.options && subscription.options.applicationServerKey;

    if (! atual) {
        return true;
    }

    const esperada = chaveParaBytes(vapid);
    const bytes = new Uint8Array(atual);

    return bytes.length === esperada.length && bytes.every((valor, i) => valor === esperada[i]);
}

const ehIos = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const ehStandalone = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
const ehNavegadorEmbutido = () => /FBAN|FBAV|Instagram|Line\/|MicroMessenger|; wv\)/.test(navigator.userAgent);
const temApis = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

/**
 * Situação do aparelho para a tela de ativação.
 * 'ok' | 'ios_instalar' | 'ios_antigo' | 'embutido' | 'indisponivel'
 */
function suporte() {
    if (ehIos() && ! ehStandalone()) {
        return 'ios_instalar';
    }

    if (ehIos() && ! temApis()) {
        return 'ios_antigo';
    }

    if (ehNavegadorEmbutido()) {
        return 'embutido';
    }

    if (! window.isSecureContext || ! temApis()) {
        return 'indisponivel';
    }

    return 'ok';
}

const permissao = () => ('Notification' in window ? Notification.permission : 'denied');

async function registro() {
    if (! temApis() || ! config().sw) {
        return null;
    }

    try {
        await navigator.serviceWorker.register(config().sw, { scope: config().escopo || '/garcom/' });

        return await navigator.serviceWorker.ready;
    } catch (e) {
        return null;
    }
}

async function inscricaoAtual() {
    const reg = await registro();

    return reg ? reg.pushManager.getSubscription() : null;
}

async function inscrever(reg) {
    return reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: chaveParaBytes(config().vapid),
    });
}

/** Pede a permissão e inscreve. Só chamar a partir de um clique. */
async function ativar() {
    if (suporte() !== 'ok') {
        throw new Error('Este aparelho não suporta notificações.');
    }

    const resposta = await Notification.requestPermission();

    if (resposta !== 'granted') {
        throw new Error(resposta === 'denied' ? 'negado' : 'dispensado');
    }

    const reg = await registro();

    if (! reg) {
        throw new Error('Não foi possível iniciar o serviço de notificações.');
    }

    let inscricao = await reg.pushManager.getSubscription();

    if (inscricao && ! mesmaChave(inscricao, config().vapid)) {
        await inscricao.unsubscribe();
        inscricao = null;
    }

    return (inscricao || await inscrever(reg)).toJSON();
}

async function desinscrever() {
    const inscricao = await inscricaoAtual();

    if (inscricao) {
        await inscricao.unsubscribe();
    }
}

/**
 * Sincronização silenciosa a cada abertura do painel: com a permissão já dada
 * e uma inscrição existente, reenvia ao servidor (revincula aparelho compartilhado).
 */
async function sincronizar(wire) {
    if (suporte() !== 'ok' || permissao() !== 'granted') {
        return;
    }

    const reg = await registro();

    if (! reg) {
        return;
    }

    let inscricao = await reg.pushManager.getSubscription();

    if (! inscricao) {
        return;
    }

    if (! mesmaChave(inscricao, config().vapid)) {
        await inscricao.unsubscribe();
        inscricao = await inscrever(reg);
    }

    await wire.sincronizar(inscricao.toJSON(), ehStandalone());
}

/**
 * Fecha as notificações cujo assunto já foi resolvido. Só mexe nas com mais de
 * 30 s, para não fechar uma que chegou durante a consulta.
 */
async function fecharResolvidas(chavesAbertas) {
    if (suporte() !== 'ok' || permissao() !== 'granted') {
        return;
    }

    const reg = await navigator.serviceWorker.getRegistration(config().escopo || '/garcom/');

    if (! reg) {
        return;
    }

    const abertas = new Set(chavesAbertas);

    (await reg.getNotifications()).forEach((notificacao) => {
        const chave = notificacao.data && notificacao.data.chave;
        const [tipo, id] = chave ? chave.split(':') : [];

        if (! ['chamado', 'aprovar', 'pronto'].includes(tipo) || Date.now() - notificacao.timestamp < 30000) {
            return;
        }

        if (! abertas.has(`${tipo}:${id}`)) {
            notificacao.close();
        }
    });
}

window.addEventListener('garcom-push-pendencias', (evento) => {
    const detalhe = evento.detail;
    const chaves = Array.isArray(detalhe) ? detalhe[0] : (detalhe && detalhe.chaves);

    if (Array.isArray(chaves)) {
        fecharResolvidas(chaves);
    }
});

window.GarcomPush = {
    suporte,
    permissao,
    ativar,
    desinscrever,
    sincronizar,
    fecharResolvidas,
    inscricaoAtual,
    instalado: ehStandalone,
    ios: ehIos,
};

// O Service Worker precisa estar registrado em toda abertura (manifest + push); isso não pede permissão.
registro();

window.dispatchEvent(new Event('garcom-push:pronto'));
