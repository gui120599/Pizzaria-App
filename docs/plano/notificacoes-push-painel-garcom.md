# Notificações push no Painel do Garçom — investigação e plano

> **Data:** 02/11/2026 · **Status:** aprovado para implementação. D1–D7 decididas pelo usuário e D8 adotada como recomendada (seção 14.6) · nada implementado
> **Escopo:** painel `/garcom` (Filament) em smartphones dos colaboradores.
> **Branch auditada:** `prod-new` @ `268153d1`

---

## Sumário executivo

- **Hoje não existe push.** Todo aviso do garçom vem de **polling do Livewire** (`wire:poll` de 5–10 s), mais um sino de notificações em banco com poll de 30 s. Isso só funciona **com o painel aberto, na tela e com o navegador ativo**. Com a tela apagada ou outro app em primeiro plano, o celular congela a aba e o garçom não recebe nada.
- **Recomendação: Web Push nativo** (Push API + Service Worker + VAPID), enviado pelo próprio Laravel com a biblioteca `minishlink/web-push`. O painel `/garcom` vira PWA instalável, com escopo restrito a `/garcom/`.
  - Custo zero e nenhum serviço externo pago.
  - Sem Redis, sem WebSocket, sem worker permanente: o envio roda **depois da resposta HTTP** (`dispatchAfterResponse`, padrão que o projeto já usa na NFC-e automática).
- **Compatibilidade real:**
  - **Android + Chrome:** funciona com o painel em segundo plano, com o Chrome fechado e com a tela bloqueada. Instalar como app é recomendado, mas não obrigatório.
  - **iPhone:** funciona **somente com iOS/iPadOS 16.4 ou mais novo** e **somente com o painel adicionado à Tela de Início** e aberto por lá. Isso vale também para o Chrome no iPhone, que usa o motor do Safari. Em aba comum de navegador, no iPhone, **não há push**.
- **Push não substitui o polling.** Os alertas dentro do painel continuam como estão, e o push é uma camada a mais. A entrega de push **não é garantida**: celular offline, sem bateria, com notificações bloqueadas ou com economia de bateria agressiva pode atrasar ou perder avisos.
- **Eventos recomendados para o MVP:**
  - cliente chamou o garçom;
  - cliente pediu a conta;
  - pedido do QR aguardando aprovação;
  - pedido pronto, da mesa ou da retirada lançada pelo garçom;
  - "quer abrir a mesa", só para quem está com o ponto aberto.

  Novos eventos entram depois, conforme a necessidade.
- **Dois parâmetros do Admin** (seção 9.5):
  - **quem recebe os avisos da mesa:** só o garçom que abriu a mesa, ou todos os garçons com ponto aberto;
  - **repetir o aviso até o chamado ser atendido:** com intervalo e limite de segurança.
- **Esforço estimado:** de 7 a 10 dias de desenvolvimento, divididos em 4 fases, mais 1 semana de piloto no salão.
- **Decisões:** D1–D8 fechadas (seção 14.6).

---

## 1. Diagnóstico da arquitetura atual

### 1.1 Stack e hospedagem

| Item | Situação encontrada |
|---|---|
| Framework | Laravel 12 (estrutura Laravel 10), PHP 8.4 no dev (Sail) |
| Painel do garçom | Filament 4, painel próprio `garcom` (`app/Providers/Filament/GarcomPanelProvider.php`), `->spa()` (navegação via `wire:navigate`), `->topNavigation()`, tema Vite próprio |
| Produção | HostGator / cPanel compartilhado (`/home1/empo7374/sistema.emporiodapizzago.com.br`), **PHP 8.2 (ea-php82)**, sem Composer e sem npm. `vendor/` e `public/build/` vão versionados na branch `producao` (`scripts/promote-producao.sh`) |
| Banco | MySQL, sessão em banco (`SESSION_DRIVER=database`) |
| Fila | `QUEUE_CONNECTION=database`. **Não há worker permanente.** O Kernel agenda um `queue:work --stop-when-empty` por minuto **só** para a fila de exportações. A NFC-e automática usa `dispatchAfterResponse` |
| Agendador | `app/Console/Kernel.php` com cerca de 20 comandos, depende do cron `schedule:run` no cPanel |
| Broadcasting | **Não configurado.** `BROADCAST_CONNECTION=log`, `BroadcastServiceProvider` comentado em `config/app.php`, Echo/Pusher comentados em `resources/js/bootstrap.js`. O único evento "broadcastável" (`App\Events\Cozinha\ProducaoAtualizada`) só dispara com `cozinha.tempo_real.driver=reverb`, que não está em uso |
| Redis | Não usado (cache e sessão em banco) |
| Multi-tenant | **Não existe.** Uma instalação = um estabelecimento = um banco (`Empresa` é singleton). O multi-tenant está planejado só para o futuro "RazelFood Menu Manager" |

### 1.2 Painel do garçom: peças envolvidas

| Camada | Arquivos |
|---|---|
| Provider/rotas | `GarcomPanelProvider`: path `/garcom`, login próprio `Auth\LoginGarcom` (PIN com escolha rápida de usuário ou e-mail/senha), `databaseNotifications()` + `databaseNotificationsPolling('30s')` |
| Páginas | `MapaMesas` (`/garcom`), `AtenderMesa` (`/garcom/mesa/{sessao}`), `AtenderRetirada` (`/garcom/retirada/{pedido?}`), `BaterPonto`, `MeuEspelho`, `MeusRepasses`, `ChecklistsDoDia`, `ExecutarChecklist` |
| Services | `app/Services/Garcom/*` (`MapaMesasService`, `AtendimentoMesaService`, `RetiradaService`, `PinService`, `AutorizacaoGerenteService`) e `app/Services/MesaCliente/*` (`MesaChamadoService`, `PedidoMesaClienteService`, `AprovacaoPedidoMesaService`, `ParticipanteMesaService`, `EstadoMesaCliente`) |
| Views | `resources/views/filament/garcom/pages/*`, partials `alerta-pronto`, `sem-conexao`, `mesa-qr`, `mesa-rodadas` etc. |
| Lado do cliente (QR) | `MesaClienteController` (`POST /mesa/{codigo}/chamados`), store Alpine `mesa` em `resources/views/mesa-cliente/modais.blade.php` |

### 1.3 Autenticação e autorização

- Mesmo guard `web` do `/admin`. Quem entra no painel é decidido por `User::canAccessPanel()`:
  - `GARCOM_PANEL_ROLES = ['Admin', 'Gerente', 'Atendente', 'Garcom']` operam o salão (`User::atendeSalao()`);
  - `CHECKLIST_PANEL_ROLES = ['Cozinha', 'Caixa']` entram só para checklists;
  - colaborador da folha sem papel do salão entra só para o ponto.
- O login por PIN existe porque **há aparelhos compartilhados** no salão ("Trocar garçom / Sair" no menu do usuário). Isso afeta o push diretamente: a inscrição pertence ao **aparelho**, não à pessoa (ver 10.3).
- As permissões são Spatie + Filament Shield, com policies `MesaPolicy` e `SessaoMesaPolicy`.

### 1.4 Relação garçom × mesa × pedido

- `sessao_mesas.sessao_mesa_usuario_id` é o **garçom dono da conta da mesa**. A transferência de mesa troca esse valor.
- `pedidos.pedido_usuario_garcom_id` é o garçom de **cada rodada**. Nos pedidos do QR, ele vem preenchido com o dono da sessão (`PedidoMesaClienteService:99`).
- Retirada/entrega lançada pelo garçom: `pedido_origem = GARCOM`, sem sessão, com `pedido_usuario_garcom_id`.
- Chamados do QR: `mesa_chamados` (`mc_*`), tipos `CHAMAR_GARCOM`, `PEDIR_CONTA` e `ABRIR_MESA`, status `PENDENTE`, `ATENDIDO` e `CANCELADO`. Fica **um PENDENTE por mesa e tipo**: tocar de novo não cria outro.

### 1.5 Service Worker e manifest

- **Não existe Service Worker** em lugar nenhum do projeto: a busca por `serviceWorker` não retornou nada.
- **Já existe um manifest PWA para a cozinha**, servido por rota (`/cozinha/manifest.webmanifest`, `CozinhaPanelProvider`) com o partial `cozinha/partials/pwa-head.blade.php`. É o modelo a seguir para o garçom, com escopo próprio (`/garcom/`), sem conflito.
- Já existem ícones da marca: `public/img/marca/icon-192.png` e `apple-touch-icon.png`. **Falta um ícone de 512×512** e um ícone monocromático para o badge do Android.

### 1.6 HTTPS

- Web Push e Service Worker exigem **contexto seguro (HTTPS)**. A única exceção é `localhost`, no desenvolvimento.
- O domínio de produção é `sistema.emporiodapizzago.com.br`, no cPanel, que normalmente tem AutoSSL.
- **Verificar na Fase 0** três pontos:
  - se o certificado é válido;
  - se HTTP redireciona para HTTPS: o `public/.htaccess` **não tem** regra de redirecionamento;
  - se `APP_URL` está em `https://`.

---

## 2. Mapeamento do sistema de notificações existente

### 2.1 Como os avisos funcionam hoje

| Evento | Como é detectado | Como chega ao garçom |
|---|---|---|
| **Pedido pronto (mesa e retirada do garçom)** | `MapaMesasService::prontasDoGarcom()` consulta pedidos `PRONTO` (ou `ENTREGUE` + `Expedido` há menos de 15 min) do garçom | O `wire:poll` do `MapaMesas` (`polling_mapa_segundos` = 10 s) compara com `$prontasAvisadas` e dispara um toast Filament persistente mais o evento `garcom-pedido-pronto`, que **vibra e bipa** (`partials/alerta-pronto`). O `AtenderMesa` e o `AtenderRetirada` fazem o mesmo a cada 5 s |
| **Pedido pronto (módulo Cozinha)** | `SincronizadorPedidoProducao::avisarGarcom()` | Grava `ProducaoProntaNotification` (canal `database`) no **sino** do Filament, com poll de 30 s. Só cobre a mesa, e só quando a produção passa pelo módulo de cozinha |
| **Chamou o garçom / pediu a conta** | `MesaChamado` PENDENTE (`MesaChamadoService::abrir`) | `MapaMesasService::avisosDoGarcom()` no mesmo poll do mapa: toast "Chamou o garçom" + vibração. No `AtenderMesa`: "O cliente chamou pelo celular" |
| **Pedido do QR para aprovar** | `Pedido` INICIADO + `pedido_aprovacao_status = PENDENTE` | Igual à linha acima (toast "Pedido do cliente para aprovar") |
| **Quer abrir a mesa** | `MesaChamado` `ABRIR_MESA` | Vai para **todos** os garçons (a mesa ainda não tem dono) |
| **Conta solicitada** | `sessao_mesa_conta_solicitada_em` | Só muda a cor da mesa no mapa (roxa), sem toast |

### 2.2 Conclusão

O sistema **só atualiza a interface**. Não há infraestrutura de comunicação em tempo real (nem WebSocket, nem broadcasting, nem SSE) e nenhum canal que alcance o aparelho fora da página. A boa notícia é que a **lógica de "o que avisar e para quem" já existe**:

- `prontasDoGarcom`, `avisosDoGarcom` e `prontosParaServir`;
- os services de chamado e aprovação;
- o `HistoricoStatusPedidoObserver`, que já captura toda mudança de `pedido_status` feita via Eloquent.

Essa lógica é o que vai ser reaproveitado.

### 2.3 Diferença entre as tecnologias

| Tecnologia | O que é | Funciona com a página fechada ou em segundo plano? |
|---|---|---|
| **Notificação interna (toast Filament)** | Elemento HTML desenhado dentro da página | Não. Só existe enquanto a página está renderizada |
| **Polling (`wire:poll`)** | A página pergunta ao servidor de tempos em tempos | Não. O Android e o iOS congelam timers de abas em segundo plano ou com a tela apagada |
| **WebSocket / Reverb / Pusher** | Conexão aberta servidor → página, com aviso instantâneo | Não. A conexão cai quando o navegador suspende a aba. Exige processo permanente (Reverb) ou serviço pago (Pusher) |
| **Notificação do banco (`databaseNotifications`)** | Registro em tabela, lido pelo sino | Não. O sino também é polling |
| **Web Push (Push API + Service Worker)** | O servidor envia uma mensagem cifrada ao **serviço de push do navegador** (Google FCM, Apple APNs, Mozilla). O sistema operacional acorda o Service Worker, que mostra a **notificação do sistema** | **Sim**, desde que o navegador ou o app instalado esteja inscrito e o sistema permita |

**O que falta para avisos fora do painel:** Service Worker, manifest (obrigatório no iOS), chaves VAPID, tabela de inscrições, endpoint de registro, disparo no backend nos pontos certos e a interface de ativação.

---

## 3. Viabilidade técnica

**Viável, com baixo risco de infraestrutura.**

| Requisito | Situação |
|---|---|
| HTTPS | Domínio com cPanel. Confirmar certificado e redirecionamento (Fase 0) |
| PHP para assinar VAPID e cifrar o payload | `minishlink/web-push` v11 (07/2026) exige **PHP ≥ 8.2** + `curl`, `json`, `mbstring`, `openssl`. `gmp`/`bcmath` são **opcionais**, só melhoram o desempenho. Produção usa PHP 8.2: compatível. `guzzlehttp/guzzle` e `brick/math` já estão no `composer.lock` |
| Saída HTTPS do servidor | O servidor precisa fazer POST para `fcm.googleapis.com`, `*.push.apple.com` e `updates.push.services.mozilla.com`. A hospedagem compartilhada costuma liberar a porta 443 de saída (a integração Stone/Pagar.me e o NFe.io já fazem chamadas externas). Confirmar na Fase 0 |
| Envio assíncrono sem worker | `dispatchAfterResponse` (já usado em `StoneVendaAutomaticaService:241`). O PHP responde ao usuário e depois envia o push no mesmo processo (`fastcgi_finish_request` / `litespeed_finish_request`) |
| Ponto de captura dos eventos | Chamados e aprovação: services explícitos. Pedido pronto: observer de `Pedido` (o `PedidoStatusGateway::avancarAte` sobe degrau a degrau via `PedidoStatusService` e `save()`, então a transição para PRONTO sempre passa pelo Eloquent, inclusive com Expedição) |
| Deploy | Dependência nova via `sail composer require`. O `vendor/` vai versionado (fluxo atual). A biblioteca não depende de extensões nativas ausentes |

---

## 4. Compatibilidade Android e iOS

### 4.1 Android: Google Chrome (principal)

| Cenário | Recebe push? | Observação |
|---|---|---|
| Painel aberto e visível | Sim | O polling também avisa. O SW mostra a notificação mesmo assim (ver 8.3) |
| Painel em segundo plano (outra aba ou outro app) | **Sim** | |
| Chrome minimizado ou removido dos recentes | **Sim** | O Chrome recebe via FCM (Google Play Services) e acorda o SW |
| Tela bloqueada | **Sim** | Com `Urgency: high`, o FCM entrega mesmo em Doze |
| Celular reiniciado, Chrome nunca aberto | Em geral sim | Depende do fabricante |
| Chrome "Forçar parada" nas configurações | **Não**, até abrir o Chrome de novo | Regra do Android para qualquer app |
| Celular offline | Fica retido no FCM até o **TTL** e chega ao reconectar, se ainda estiver dentro do prazo | Por isso o TTL é curto (5–15 min) para eventos operacionais |
| Android 13+ | Exige permissão de notificação **do app Chrome** no sistema **e** permissão do site | Se o Chrome estiver bloqueado no sistema, nada chega |
| Aparelho Huawei/Honor sem Google Play Services | **Não funciona** | Não há FCM. Restrição oficial |
| Xiaomi (MIUI/HyperOS), Samsung ("apps em suspensão profunda"), Motorola, Oppo | Funciona, com risco de atraso ou perda se o Chrome estiver em economia de bateria agressiva | A tela de ajuda deve orientar a colocar o Chrome como "Sem restrições" de bateria |
| Navegador embutido (link aberto no WhatsApp, Instagram, leitor de QR) | **Não** | O painel precisa ser aberto no Chrome ou instalado como app |

**Instalação como PWA no Android:** **não é obrigatória** para o push, mas é recomendada:

- ícone próprio na tela inicial;
- abre em tela cheia, sem barra de endereço;
- o garçom não fecha a aba sem querer;
- aparece como app nas configurações de notificação.

O Chrome oferece "Instalar app" quando há manifest válido com ícones de 192 e 512 e Service Worker com `fetch`.

**Versões:** o Chrome para Android suporta Push API com payload há muitos anos (desde o Chrome 50). Na prática, qualquer Chrome atualizado em Android 8+ funciona, e o Android 7 ou anterior não recebe mais atualizações do Chrome. A política recomendada é Android 9+ com o Chrome atualizado.

### 4.2 iPhone / iPad: iOS e iPadOS

| Ponto | Realidade |
|---|---|
| Versão mínima | **iOS/iPadOS 16.4.** Em 15.x ou 16.0–16.3 não há Web Push |
| Aba comum do Safari ou do Chrome | **Sem push.** O `PushManager` não está disponível. Não é possível nem pedir a permissão |
| Exigência | O site precisa ser **adicionado à Tela de Início** e **aberto pelo ícone** (web app). A permissão só pode ser pedida **dentro do web app** e **após um toque do usuário** |
| Chrome no iPhone | Usa o WebKit (motor do Safari): **as mesmas limitações**. Desde o iOS 16.4, o Chrome também consegue "Adicionar à Tela de Início" (ícone de compartilhar). O push vem do web app instalado, não do Chrome |
| iOS 26 em diante | Todo site adicionado à Tela de Início abre como web app por padrão (chave "Abrir como app Web" no diálogo). Antes, era preciso o manifest com `display: standalone`, que vamos ter de qualquer forma |
| App fechado ou em segundo plano, tela bloqueada | **Recebe** (via APNs). Aparece na tela de bloqueio, na Central de Notificações e no Apple Watch |
| Modos de Foco / Não Perturbe | Podem silenciar o app. A orientação ao garçom precisa liberar o "Salão" no Foco de trabalho |
| Sessão e login | O web app instalado tem **cookies separados** do Safari: o garçom precisa entrar **dentro do app** depois de instalar |
| Remover o ícone ou apagar os dados do site | Perde a inscrição. Ao reinstalar, é preciso ativar de novo |
| Push "silencioso" | Proibido: cada push precisa exibir uma notificação, senão o iOS pode revogar a inscrição. **Não usar push para "apagar" ou sincronizar** |
| Personalização | Sem som próprio, sem padrão de vibração, `requireInteraction` ignorado. **Não depender de botões de ação na notificação** (suporte limitado e inconsistente no iOS) |
| Badge no ícone | `navigator.setAppBadge()` funciona no web app instalado, se o usuário permitir. Útil para "N chamados pendentes" (fase futura) |
| iPad configurado como "iPad compartilhado" | Não permite adicionar à Tela de Início por navegadores de terceiros |

### 4.3 Outros navegadores e aparelhos

| Navegador | Push | Posição recomendada |
|---|---|---|
| Samsung Internet | Suporta Service Worker + Push API | **Melhor esforço**: funciona, mas o suporte oficial é o Chrome |
| Firefox Android | Suporta (servidor de push da Mozilla) | Melhor esforço |
| Edge / Opera / Brave Android (Chromium) | Suportam, via FCM | Melhor esforço. O Brave pode vir com o push do Google desligado |
| Navegadores de fabricante (Mi Browser etc.) | Incerto | Não suportado |
| Navegadores dentro de apps (WebView) | Não | Orientar a abrir no Chrome |
| Notificações bloqueadas pelo usuário | Não há como pedir de novo por código | A tela deve detectar `Notification.permission === 'denied'` e mostrar o passo a passo para liberar nas configurações |

### 4.4 Política recomendada

- **Suporte oficial:** Android 9+ com Google Chrome atualizado (instalado como app, recomendado) **e** iPhone/iPad com iOS 16.4+ usando o painel adicionado à Tela de Início.
- **Melhor esforço:** Samsung Internet, Firefox e Edge no Android.
- **Sem suporte:** iOS anterior a 16.4, Huawei sem Google, navegadores embutidos.
- **Para todos:** os avisos dentro do painel (polling, vibração e bipe) **continuam funcionando** como hoje. O push é uma camada adicional, e o recurso de "aparelho incompatível" só esconde o botão de ativar e explica o motivo.

---

## 5. Limitações do Chrome (e do push em geral)

1. **No iPhone, o "Chrome" não decide nada.** Quem decide é o WebKit/iOS: Tela de Início obrigatória, iOS 16.4+.
2. **O site não controla a permissão depois de negada.** Só o usuário consegue reabrir, nas configurações do site ou do app.
3. **O Chrome não deixa pedir permissão "do nada".** Pedidos sem gesto do usuário são silenciados ou viram um ícone discreto (*quiet UI*), e quem nega muitas vezes fica com o site em silêncio permanente. Por isso a permissão **só é pedida no botão "Ativar notificações"**.
4. **`userVisibleOnly: true` é obrigatório:** todo push precisa mostrar notificação. Não há push "invisível" para sincronizar dados.
5. **Entrega sem garantia:** o serviço de push aceita a mensagem (HTTP 201), mas isso **não** confirma que ela chegou ao aparelho. Economia de bateria, Doze, modo avião, Foco e "Forçar parada" atrasam ou descartam a entrega.
6. **TTL:** mensagens não entregues são descartadas após o TTL definido no envio. TTL `0` = só entrega se o aparelho estiver online naquele instante.
7. **Tamanho do payload:** cerca de 4 KB cifrado. Mandamos só título, texto, URL e chave do evento.
8. **Inscrições expiram ou são revogadas** (o usuário limpa dados, reinstala, troca de aparelho). O servidor recebe `404`/`410` e precisa apagar a inscrição.
9. **Um Service Worker por escopo:** `/garcom/sw.js` controla só `/garcom/*`. Não interfere no `/admin`, no `/cozinha` nem no cardápio público.

---

## 6. Comparação entre as alternativas

### Alternativa A: Web Push nativo (VAPID + Service Worker + `minishlink/web-push`)

O Laravel assina (VAPID) e cifra cada mensagem e faz o POST direto no serviço de push do navegador do garçom (FCM no Chrome, APNs no iPhone, Mozilla no Firefox). Esses serviços são **gratuitos** e **não exigem conta**.

### Alternativa B: serviço externo

- **Firebase Cloud Messaging (SDK web):** no navegador, o FCM é só uma camada sobre a **mesma Push API**. Exige projeto Firebase, SDK JavaScript, credencial de service account e a API HTTP v1 com OAuth. No iPhone continua exigindo a Tela de Início. **Não entrega nada que a alternativa A não entregue.**
- **OneSignal, Pusher Beams e similares:** dão painel, segmentação, estatísticas e camada gratuita. Em troca:
  - script de terceiro **dentro de um painel autenticado**;
  - dados de colaboradores com um operador externo (LGPD, contrato);
  - dependência de fornecedor e limites do plano gratuito.

  O ganho real (estatísticas de entrega) é pequeno para uma pizzaria com poucos aparelhos.

### Alternativa C: aplicativo próprio ou híbrido

- **TWA (Trusted Web Activity) no Android:** empacota a PWA na Play Store. O push continua sendo Web Push. Só vale se a presença na loja importar.
- **Capacitor / React Native / Flutter com push nativo (FCM/APNs):**
  - vantagens: confiabilidade maior no iOS, som próprio, alertas persistentes, nenhum passo de "Adicionar à Tela de Início";
  - custos: conta Apple Developer (US$ 99/ano), Google Play (US$ 25), revisão das lojas, dois pipelines de build, atualizações via loja e reescrita ou embrulho do painel Filament.

  Só se justifica se o piloto mostrar perdas relevantes no iPhone, ou se o futuro SaaS precisar de app de marca nas lojas.

### Quadro comparativo

| Critério | A. Web Push nativo | B1. FCM web | B2. OneSignal e similares | C. App nativo/híbrido |
|---|---|---|---|---|
| Android (Chrome) | ✅ | ✅ | ✅ | ✅ |
| iPhone | ✅ iOS 16.4+, Tela de Início | ✅ mesmas regras | ✅ mesmas regras | ✅ sem restrição |
| Funciona com o painel fechado | ✅ | ✅ | ✅ | ✅ |
| Complexidade inicial | Média (SW + tabela + envio) | Média-alta (Firebase + OAuth) | Baixa-média (SDK pronto) | Alta |
| Custo | **R$ 0** | R$ 0 | Gratuito até um limite, depois pago | US$ 99/ano + R$ 125 + manutenção |
| Dependências | 1 biblioteca PHP | Conta Google + SDK JS | Fornecedor externo + script de terceiro | Lojas, SDKs nativos, CI móvel |
| Confiabilidade | A do serviço de push do navegador | Igual à A | Igual à A (mesma infraestrutura) | Maior no iOS (APNs nativo, alertas críticos com entitlement) |
| Manutenção | Baixa (código nosso, sem conta externa) | Média (credenciais, SDK) | Baixa, mas presa ao fornecedor | Alta |
| Privacidade/LGPD | Payload cifrado de ponta a ponta, sem operador intermediário | Google como operador | Fornecedor como operador | Varia |
| Adequação ao RazelFood hoje | **Alta** | Baixa | Baixa | Baixa (revisar no SaaS) |

**Recomendação: alternativa A.** O melhor equilíbrio entre custo, simplicidade e controle. Reavaliar C só com dados do piloto ou na fase SaaS.

---

## 7. Arquitetura recomendada

```
 Evento de domínio                    Backend (Laravel)                          Fora do servidor            Celular do garçom
 ─────────────────                    ─────────────────                          ────────────────            ─────────────────
 MesaChamadoService::abrir ─┐
 PedidoMesaClienteService ──┤                                     
 (pedido p/ aprovar)        ├─► NotificadorGarcom::notificar(EventoGarcom)
 Observer Pedido → PRONTO ──┘        │  1. resolve destinatários (regras da seção 9)
                                     │  2. filtra preferências / pausa / turno
                                     │  3. grava push_envios (único por chave+dispositivo) ← idempotência
                                     │  4. DB::afterCommit → EnviarPushGarcomJob::dispatchAfterResponse(ids)
                                     ▼
                              EnviarPushGarcomJob (mesmo processo PHP, depois da resposta)
                                     │  5. revalida: o evento ainda está pendente?  não → DESCARTADO
                                     │  6. WebPush (minishlink) assina VAPID, cifra, envia (TTL, Urgency, Topic)
                                     │  7. 201 → ENVIADO · 404/410 → desativa dispositivo · 429/5xx → FALHOU
                                     ▼
                                                                FCM / APNs / Mozilla ─────────────► Service Worker /garcom/sw.js
                                                                                                        │ push → showNotification(tag)
 push:reenviar-falhas (scheduler, 1/min) ── reenvia FALHOU ainda dentro do TTL                          │ click → foca/abre a URL do painel
 push:limpar (diário) ── apaga envios antigos e dispositivos inativos                                   ▼
                                                                                                   /garcom/mesa/{sessao}
```

**Componentes novos:**

| Componente | Responsabilidade |
|---|---|
| `config/pizzaria.php` → `push` | Liga/desliga (`PIZZARIA_PUSH_ATIVO`, padrão `false`), chaves VAPID (`.env`), subject, TTL e urgência por evento, cooldown, allowlist de hosts de push, modo de envio (`after_response`\|`sync`) |
| `App\Enums\EventoPushGarcomEnum` | `CHAMOU_GARCOM`, `PEDIU_CONTA`, `APROVAR_PEDIDO`, `PEDIDO_PRONTO`, `ABRIR_MESA`, `TESTE`, com título, ícone, TTL, urgência e se é pessoal ou para o turno |
| `App\Models\DispositivoPush` (`dispositivos_push`, `dp_*`) | Inscrição do aparelho vinculada ao usuário atual |
| `App\Models\PushEnvio` (`push_envios`, `pe_*`) | Registro de cada envio. Garante idempotência, mostra o que falhou e permite o reenvio |
| `App\Services\Push\DispositivoPushService` | Registrar/atualizar por endpoint (revincula aparelho compartilhado), desativar, pausar, validar o endpoint (allowlist) |
| `App\Services\Push\NotificadorGarcom` | Porta de entrada única: recebe um `EventoGarcom` (DTO: evento, chave, destinatários, título, corpo, URL, expiração) |
| `App\Services\Push\EnviadorPush` (interface) + `MinishlinkEnviadorPush` | Isola a biblioteca. Nos testes, `FakeEnviadorPush` |
| `App\Jobs\EnviarPushGarcomJob` | Revalida e envia em lote (`queueNotification` + `flush`) |
| `App\Observers\NotificacaoGarcomObserver` (em `Pedido`) | Transição para `PRONTO` → `PEDIDO_PRONTO` (mesa ou origem `GARCOM`) |
| Rota `GET /garcom/sw.js` | Service Worker servido pelo Laravel (`Content-Type: application/javascript`, `Cache-Control: no-cache`, `Service-Worker-Allowed: /garcom/`). **Não** criar a pasta `public/garcom/`: o `.htaccess` passaria a servir o diretório no lugar da rota `/garcom` |
| Rota `GET /garcom/manifest.webmanifest` | Igual à da cozinha: `id` e `scope` `/garcom/`, `start_url` `/garcom`, `display: standalone`, `orientation: portrait`, ícones de 192, 512 e *maskable* |
| Partial `filament/garcom/partials/pwa-head` | `<link rel="manifest">`, `theme-color`, `apple-touch-icon`, `apple-mobile-web-app-*` |
| Módulo `resources/js/garcom-push.js` | Registra o SW, detecta capacidade/plataforma/standalone, ativa, sincroniza e fecha as notificações já resolvidas |
| Página Filament `NotificacoesCelular` (menu do usuário) | Status, ativar, testar, pausar e desativar, guia de instalação. Um card no topo do `MapaMesas` convida a ativar (pode ser dispensado) |
| Comandos `push:gerar-vapid`, `push:reenviar-falhas`, `push:limpar` | Operação |
| Comando `push:lembrar-pendentes` (scheduler, 1 min) | Repete o aviso de chamados e pedidos para aprovar ainda pendentes (D7, seção 8.5) |
| `App\Services\Push\DestinatariosGarcom` | Aplica o parâmetro "quem recebe" (D2) e o filtro de ponto aberto (D3). A consulta de ponto aberto é extraída do `OperadorCozinhaService` para um service compartilhado |
| Seção "Notificações do salão" na página `ConfiguracaoPedidos` (/admin) | Parâmetros do Admin gravados no singleton `Empresa` (seção 9.5) |

**O que NÃO muda:** polling, toasts, vibração e bipe do painel; `ProducaoProntaNotification` (sino); fluxos de status, estoque e venda. O push só **observa** e **nunca bloqueia** a transação de negócio: qualquer falha de push é capturada e registrada.

---

## 8. Fluxo completo

### 8.1 Habilitar no celular (ação explícita do garçom)

1. O garçom entra no `/garcom`. Se o aparelho suporta push e ainda não está ativado, aparece no topo do mapa o card **"Receba os chamados mesmo com o celular no bolso"**, com o botão **Ativar** e um "Agora não" que some por 7 dias neste aparelho. Nenhuma permissão é pedida ao abrir a página.
2. Na tela **Notificações no celular**, o script avalia o aparelho:
   - **Suporta** (`serviceWorker`, `PushManager` e `Notification` presentes): mostra "Ativar notificações".
   - **iPhone fora da Tela de Início** (iOS sem `display-mode: standalone`): mostra o passo a passo ilustrado. No Safari ou no Chrome: ícone Compartilhar → "Adicionar à Tela de Início" → abrir pelo ícone "Salão" → entrar → Ativar. Se o iOS for anterior a 16.4: "Seu iPhone precisa do iOS 16.4 ou mais novo".
   - **Navegador embutido ou sem suporte:** "Abra este endereço no Google Chrome", com botão de copiar o link.
   - **Permissão negada:** passo a passo para liberar (Android: cadeado → Permissões → Notificações; iPhone: Ajustes → Notificações → Salão).
3. O toque em **Ativar** chama `Notification.requestPermission()` (gesto do usuário) → `pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: VAPID_PUBLICA })`. A inscrição é enviada ao servidor por um **método Livewire** da própria página: autenticado, com CSRF, sem rota nova exposta.
4. O servidor valida o endpoint: HTTPS e host na allowlist (anti-SSRF). Depois faz um *upsert* pelo hash do endpoint: se o aparelho estava vinculado a outro usuário, **revincula** ao usuário atual. Grava plataforma, navegador e se está instalado. Guarda o `id` do dispositivo na sessão.
5. A tela mostra **"Ativado neste aparelho ✅"** e o botão **Enviar teste**. O teste vai só para este aparelho, com limite de 1 a cada 30 s. Junto, aparece o "Chegou?" com o link "Não chegou — o que fazer".
6. Em toda abertura do painel, o script **sincroniza em silêncio**: se a permissão é `granted` e existe inscrição, reenvia ao servidor (idempotente). Isso cobre a troca de usuário em aparelho compartilhado, a rotação de chaves e o `pushsubscriptionchange` que aconteceu com o painel fechado.

### 8.2 Envio (exemplo: cliente chamou o garçom)

1. O cliente toca "Chamar garçom" no QR. `MesaChamadoService::abrir` cria o `MesaChamado`, ou devolve o PENDENTE existente, e **nesse caso não notifica de novo**.
2. Só quando o chamado é **novo**: `NotificadorGarcom::notificar(EventoGarcom::chamado($chamado))`.
   - Destinatário: conforme o parâmetro D2. Pode ser o **dono da sessão** (`sessao_mesa_usuario_id`) ou **todos os garçons com ponto aberto**.
   - Chave: `chamado:{id}`. Título: "🔔 Mesa 05 está chamando o garçom". URL: `/garcom/mesa/{sessao}`. Expira em: TTL de 5 min.
3. O notificador filtra os usuários: ativo, ainda com papel do salão, preferência do evento ligada e sem pausa. Depois pega os dispositivos ativos dele e insere `push_envios` com `insertOrIgnore` (único por chave + dispositivo). Linha já existente = já enviado = não envia de novo.
4. `DB::afterCommit` → `EnviarPushGarcomJob::dispatchAfterResponse($idsDosEnvios)`. O cliente do QR recebe a resposta na hora, sem esperar o push.
5. Depois da resposta, o job **revalida**: o chamado ainda está `PENDENTE`? Se não estiver, marca `DESCARTADO`. Se estiver:
   - envia com `TTL` = segundos restantes até `pe_expira_em`, `Urgency: high` e `Topic: mesa-{id}`. O `Topic` faz o serviço de push substituir uma mensagem pendente da mesma mesa ainda não entregue;
   - o retorno grava o status: 201 → `ENVIADO`; 404/410 → `EXPIRADO` + dispositivo desativado; 413/400 → `FALHOU` sem nova tentativa; 429/5xx/timeout → `FALHOU` com nova tentativa.
6. `push:reenviar-falhas` (cron de 1 min) reenvia as falhas que ainda estão dentro do prazo e com o evento ainda pendente: no máximo 3 tentativas.

### 8.3 Recebimento e clique (Service Worker)

```js
// /garcom/sw.js (esboço)
self.addEventListener('push', (event) => {
  const d = event.data?.json() ?? {};
  event.waitUntil(self.registration.showNotification(d.titulo ?? 'Salão', {
    body: d.corpo,
    tag: d.tag,              // ex.: "mesa-12": a mais nova substitui a anterior da mesma mesa
    renotify: true,          // vibra/toca de novo ao substituir
    icon: '/img/marca/icon-192.png',
    badge: '/img/marca/badge-96.png',
    timestamp: d.criado_em,  // mostra o horário real do evento, mesmo se chegar atrasado
    data: { url: d.url, chave: d.chave },
    vibrate: [200, 100, 200],
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = new URL(event.notification.data?.url ?? '/garcom', self.location.origin);
  if (url.origin !== self.location.origin || !url.pathname.startsWith('/garcom')) return; // só abre o painel
  event.waitUntil((async () => {
    const abertas = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    const painel = abertas.find((c) => new URL(c.url).pathname.startsWith('/garcom'));
    if (painel) { await painel.focus(); return painel.navigate(url.href); }
    return clients.openWindow(url.href);
  })());
});
```

- **Sempre mostra a notificação**, mesmo com o painel aberto. É a regra do iOS e do `userVisibleOnly`, e evita a revogação da inscrição.
- **O clique só navega.** A ação ("Atendido", "Aprovar") acontece **na página**, com sessão, CSRF, policy e validação do service. Se a sessão expirou, o Filament leva ao login e depois de volta à URL.
- **Notificação velha:** enquanto o painel está aberto, o poll do `MapaMesas`/`AtenderMesa` já sabe quais chaves continuam pendentes. O script chama `registration.getNotifications()` e **fecha** as que foram resolvidas (atendidas em outro aparelho, pedido já servido). Ao abrir pela notificação, se o evento já foi resolvido, a página mostra "Já atendido por Fulano às 20:14".
- **`pushsubscriptionchange`** (raro, mais no Firefox): o SW se inscreve de novo, e a sincronização do item 8.1.6 grava a inscrição na próxima abertura do painel.

### 8.4 Eventos do MVP (D1) e destinatários

"Garçom da mesa" e "todos com ponto aberto" seguem o parâmetro **Quem recebe os avisos da mesa** (D2, seção 9.5):

- **Só o garçom da mesa:** o dono da sessão (`sessao_mesa_usuario_id`) nos chamados e na aprovação, e o garçom da rodada (`pedido_usuario_garcom_id`, o mesmo critério do poll atual) no pedido pronto. O dono recebe **mesmo sem ponto aberto**, porque foi ele quem abriu a mesa.
- **Todos com ponto aberto:** todos os usuários com papel do salão cuja última marcação de ponto (nas últimas 16 h) é entrada ou retorno do intervalo (D3). O dono da mesa recebe sempre, mesmo sem ponto aberto.

| Evento | Destinatário | Chave / tag | TTL | Repete até atender (D7)? | Observação |
|---|---|---|---|---|---|
| Cliente chamou o garçom | Conforme D2 | `chamado:{id}` / `mesa-{id}` | 5 min | **Sim** | Já deduplicado: 1 PENDENTE por mesa e tipo. "🔔 Mesa 05 está chamando o garçom" |
| Cliente pediu a conta | Conforme D2 | `chamado:{id}` / `mesa-{id}` | 10 min | **Sim** | "💳 Mesa 05 pediu a conta". Encerra quando o garçom marca "Atendido" ou a conta fecha |
| Pedido do QR aguardando aprovação | Conforme D2 | `aprovar:{pedido}` / `mesa-{id}` | 10 min | **Sim** | "📝 Mesa 05 fez um pedido para aprovar". Encerra ao aprovar ou recusar |
| Pedido pronto (mesa) | Conforme D2 | `pronto:{pedido}` / `mesa-{id}` | 15 min | Não | "🍕 Pedido pronto — Mesa 08 (#1234)". Com Expedição, dispara no PRONTO intermediário |
| Pedido pronto (retirada/entrega lançada pelo garçom) | Garçom do pedido; no modo "todos", todos com ponto aberto | `pronto:{pedido}` / `retirada-{pedido}` | 15 min | Não | Cobre o "aguardando retirada" do salão |
| Quer abrir a mesa (QR de mesa fechada) | **Sempre** todos com ponto aberto (a mesa ainda não tem dono) | `chamado:{id}` / `abrir-{mesa}` | 5 min | **Sim** | Ao abrir a mesa, o chamado é atendido e a notificação se fecha nos outros aparelhos (8.3) |

**Fora do MVP** (entram conforme a necessidade, sem mudar a arquitetura: basta um novo caso no enum e um gatilho):

- pedido de delivery ou balcão pronto (é do caixa ou do entregador);
- mesa parada;
- escalar para o gerente;
- badge no ícone com o número de pendências.

**Sem ninguém com ponto aberto:** no modo "todos" e no "quer abrir a mesa", se ninguém estiver com o ponto aberto, nenhum push é enviado. O aviso continua aparecendo no mapa de quem estiver com o painel aberto, e o fato fica no log (`push_envios` sem linhas + `Log::info`). Isso é intencional: ninguém em turno = ninguém para incomodar fora do horário.

### 8.5 Repetição até o chamado ser atendido (D7)

- Com o parâmetro ligado, o comando `push:lembrar-pendentes` roda a cada minuto pelo scheduler. Para cada chamado `PENDENTE` (chamou o garçom, pediu a conta, quer abrir a mesa) e cada pedido do QR com aprovação `PENDENTE`, ele reenvia o aviso quando:
  - já se passaram **N minutos** desde o último aviso (parâmetro, padrão 2 min, mínimo 1 min, que é a granularidade do cron);
  - o número de repetições ainda está abaixo do **limite de segurança** (parâmetro, padrão 10).
- **Lembretes não empilham:** cada lembrete tem a chave `{chave}:lembrete:{n}` e a **mesma tag** do aviso original, então substitui a notificação anterior e vibra de novo (`renotify`). O título ganha o tempo de espera: "🔔 Mesa 05 chamando há 6 min".
- **Destinatários:** recalculados a cada lembrete com a mesma regra D2/D3. Quem abriu o ponto depois passa a receber; quem fechou o ponto para de receber.
- **Parada:** o lembrete para assim que o chamado sai de `PENDENTE`, ou seja, quando:
  - o garçom toca em "Atendido" no painel;
  - a mesa é aberta, no caso do "quer abrir a mesa";
  - a conta fecha (`encerrarDaSessao` cancela os chamados);
  - o pedido é aprovado ou recusado.

  O lembrete também para quando atinge o limite de segurança.
- **Por que existe o limite:** um chamado esquecido sem "Atendido" com a mesa aberta repetiria a noite inteira. Com 2 min × 10 repetições, os lembretes duram cerca de 20 min. O chamado continua aparecendo no mapa depois disso.
- **Depende do cron `schedule:run`:** sem ele, o primeiro aviso funciona, mas não há repetição. A tela de parâmetros mostra um alerta se o scheduler não rodou nos últimos 5 min (marca gravada em cache pelo próprio comando).

---

## 9. Estrutura de dados

### 9.1 `dispositivos_push` (model `DispositivoPush`, prefixo `dp_`)

| Coluna | Tipo | Nota |
|---|---|---|
| `id` | bigint | |
| `dp_user_id` | FK `users`, nullable, `nullOnDelete` | Usuário **atualmente** vinculado. Nulo depois do logout |
| `dp_endpoint` | text, cast `encrypted` | URL do serviço de push |
| `dp_endpoint_hash` | char(64), **unique** | sha256 do endpoint (busca e unicidade sem expor a URL) |
| `dp_chave_p256dh` | text, `encrypted` | |
| `dp_chave_auth` | text, `encrypted` | |
| `dp_content_encoding` | string(16) | `aes128gcm` (padrão) ou `aesgcm` |
| `dp_plataforma` | string(16) | `android`, `ios`, `desktop`, `outro` |
| `dp_navegador` | string(32) | Chrome, Safari, Samsung… (derivado do user agent) |
| `dp_instalado` | boolean | Aberto como app (standalone) |
| `dp_user_agent` | string(255) | Diagnóstico |
| `dp_ativo` | boolean, índice | |
| `dp_pausado_ate` | datetime nullable | "Pausar até o fim do turno", sem desinscrever |
| `dp_ultimo_sucesso_em`, `dp_ultima_falha_em` | datetime | |
| `dp_falhas_consecutivas` | smallint | Desativa ao passar de N (config) |
| `dp_desativado_em`, `dp_motivo_desativacao` | datetime, string(32) | `expirado`, `usuario`, `logout`, `falhas`, `sem_permissao` |
| `timestamps` | | |

Índice: `(dp_user_id, dp_ativo)`.

### 9.2 `push_envios` (model `PushEnvio`, prefixo `pe_`, `MassPrunable` de 30 dias)

| Coluna | Tipo | Nota |
|---|---|---|
| `id` | bigint | |
| `pe_chave` | string(80) | `chamado:123`, `pronto:456`, `aprovar:789`, `teste:{uuid}` |
| `pe_evento` | string(24) | `EventoPushGarcomEnum` |
| `pe_user_id` | FK `users` | |
| `pe_dispositivo_id` | FK `dispositivos_push`, `cascadeOnDelete` | |
| `pe_status` | string(16) | `PENDENTE`, `ENVIADO`, `FALHOU`, `EXPIRADO`, `DESCARTADO` |
| `pe_tentativas` | tinyint | |
| `pe_http_status` | smallint nullable | |
| `pe_erro` | string(255) nullable | |
| `pe_payload` | json | Título, corpo, URL, tag (sem dados pessoais além do primeiro nome do cliente na retirada) |
| `pe_expira_em` | datetime | Base do TTL e do reenvio |
| `timestamps` | | |

**Unique `(pe_chave, pe_dispositivo_id)`.** O nome do índice gerado tem 46 caracteres, abaixo do limite de 64 do MySQL. Índice extra: `(pe_status, pe_expira_em)` para o reenvio.

### 9.3 Preferências do usuário

`users.user_push_preferencias` (json nullable). `null` = padrão do sistema, com todos os eventos do MVP ligados. Exemplo: `{"ABRIR_MESA": false}`. A tela "Notificações no celular" mostra uma chave por evento.

### 9.4 Configuração

O bloco `pizzaria.push` lê do `.env`:

- `PIZZARIA_PUSH_ATIVO`;
- `PIZZARIA_PUSH_VAPID_PUBLICA` e `PIZZARIA_PUSH_VAPID_PRIVADA`;
- `PIZZARIA_PUSH_VAPID_SUBJECT` (ex.: `mailto:contato@…` ou a URL do site);
- TTL por evento, `cooldown_segundos` (60), `max_falhas_consecutivas` (5), `hosts_permitidos`, `envio` (`after_response`\|`sync`).

As chaves VAPID são geradas **uma vez** por instalação (`push:gerar-vapid` só imprime, não grava o `.env`). **Trocar as chaves invalida todas as inscrições.**

O `.env` guarda só o **técnico**: chave mestra, VAPID, TTL e allowlist. As regras de operação ficam com o Admin (9.5).

### 9.5 Parâmetros do Admin (D2 e D7)

Ficam no singleton `Empresa`, no mesmo padrão de `empresa_validade_link_ticket_horas`, e são editados numa seção nova **"Notificações do salão"** da página `ConfiguracaoPedidos` no /admin. A seção só aparece com `PIZZARIA_PUSH_ATIVO` ligado.

| Coluna | Tipo / padrão | Rótulo na tela |
|---|---|---|
| `empresa_push_destino_mesa` | string(16), `GARCOM_DA_MESA` \| `TODOS_EM_TURNO`, padrão `GARCOM_DA_MESA` (enum `DestinoAvisoMesaEnum`) | **Quem recebe os avisos da mesa:** "Só o garçom que abriu a mesa" / "Todos os garçons com ponto aberto" |
| `empresa_push_repetir_ate_atender` | boolean, padrão `true` | **Repetir o aviso até o chamado ser atendido** |
| `empresa_push_repetir_intervalo_min` | unsigned tinyint, padrão 2, entre 1 e 30 | Repetir a cada (minutos) |
| `empresa_push_repetir_max` | unsigned tinyint, padrão 10, entre 1 e 60 | Parar depois de (repetições) |

Os padrões são aplicados no `default()` da migration, e a regra de leitura fica num helper único (`Empresa::configuracaoPush()` ou equivalente), para os services não lerem as colunas soltas.

---

## 10. Segurança e multi-tenancy

### 10.1 Controle de quem recebe

- Só se registra quem passa por `canAccessPanel('garcom')` **e** `atendeSalao()`.
- No envio, os destinatários são **revalidados**: usuário ativo e ainda com papel do salão. Se perdeu o papel, o dispositivo é desativado com o motivo `sem_permissao`.
- Os destinatários vêm de dados do servidor (dono da sessão, garçom do pedido), nunca do cliente do QR.

### 10.2 Integridade e abuso

- **Anti-SSRF:** o servidor faz POST para a URL que o navegador informou. Por isso, aceitar só `https://` cujo host esteja na allowlist: `fcm.googleapis.com`, `*.push.apple.com`, `updates.push.services.mozilla.com`, `*.notify.windows.com`. Na Fase 0, conferir o host usado pelo Samsung Internet. Endpoints fora da lista são rejeitados e registrados em log.
- **Clique não executa ação.** A notificação só leva a uma URL **interna** (o SW confere a origem e o prefixo `/garcom`). Toda ação passa pelas policies e services atuais.
- **Payload mínimo:** sem telefone, valores, itens ou dados do cliente da mesa. O payload do Web Push é **cifrado de ponta a ponta** (RFC 8291): nem o Google nem a Apple conseguem ler.
- **Chaves da inscrição cifradas no banco** (cast `encrypted`). A chave VAPID privada fica só no `.env` e não vai ao git.
- **Limites:** teste com 1 envio a cada 30 s por usuário. Cooldown de 60 s por mesa e tipo de evento contra rajadas (ex.: o cliente atendido que chama de novo em seguida). Os limiters do QR (`mesa-cliente`) continuam valendo.

### 10.3 Aparelho compartilhado (login por PIN)

- A inscrição é do **aparelho**. O vínculo com a pessoa é feito **pelo servidor**:
  - no **login**, a sincronização automática (8.1.6) revincula o endpoint ao novo usuário;
  - no **logout** ("Trocar garçom / Sair"), um listener de `Illuminate\Auth\Events\Logout` desvincula o dispositivo guardado na sessão (`dp_user_id = null`, motivo `logout`). O aparelho deixa de receber os avisos de quem saiu.
- **Sessão expirada sem logout:** o aparelho continua recebendo os avisos do último usuário até alguém entrar. Isso é aceitável (o aviso só leva ao login), mas fica registrado como risco (13). Mitigação opcional: desvincular dispositivos sem uso do painel há mais de X horas (`push:limpar`).

### 10.4 Multi-tenancy

- **Hoje:** cada estabelecimento é uma instalação com domínio e banco próprios. O isolamento vem da **origem**:
  - Service Worker, inscrições e chaves VAPID são por domínio;
  - um aparelho inscrito em `sistema.emporiodapizzago.com.br` não pode receber push de outra instalação;
  - não há como vazar avisos entre estabelecimentos.
- **Futuro SaaS (RazelFood Menu Manager):**
  - acrescentar `tenant_id` em `dispositivos_push` e `push_envios`, com escopo global, e unique `(tenant_id, dp_endpoint_hash)`;
  - destinatários sempre resolvidos dentro do tenant;
  - se vários tenants dividirem o mesmo domínio, usar um caminho por tenant (`/t/{slug}/garcom/sw.js`) ou subdomínio, para escopos de SW separados;
  - chaves VAPID podem ser globais da plataforma.

  O desenho atual não impede essa evolução.

### 10.5 LGPD

O user agent e a plataforma são dados técnicos do colaborador, guardados para a finalidade operacional. Excluir o usuário anula o vínculo (`nullOnDelete`) e o `push:limpar` apaga os dispositivos órfãos. Os envios são retidos por 30 dias.

---

## 11. Infraestrutura necessária

| Item | Precisa? | Comentário |
|---|---|---|
| HTTPS válido + redirecionamento HTTP→HTTPS | **Sim** | Conferir na Fase 0. Se faltar o redirecionamento, ajustar no cPanel ("Forçar HTTPS") |
| Biblioteca `minishlink/web-push` ^11 | **Sim** (aprovação: D5) | Instalada via Sail, com o `vendor/` versionado, como já é o fluxo |
| Extensões PHP `curl`, `mbstring`, `openssl` | Sim | Padrão no ea-php82. Conferir com `php -m` no cPanel (Terminal ou um `phpinfo` temporário). `gmp` só para desempenho |
| Saída HTTPS para os serviços de push | Sim | Testar com o `Enviar teste` em produção |
| Fila/worker | **Não** para o MVP | `dispatchAfterResponse` envia no mesmo processo, depois da resposta |
| Cron `schedule:run` | Sim (já é necessário para outros módulos) | Usado para `push:reenviar-falhas` e `push:limpar`. Sem ele, o MVP funciona, só não reenvia as falhas |
| Redis / WebSocket / Reverb | **Não** | |
| Serviço externo pago | **Não** | |
| Firebase / conta Apple | **Não** | O Web Push no iOS não exige conta de desenvolvedor Apple |

**Consumo:** cada envio é um POST HTTPS de cerca de 100–300 ms, feito depois da resposta. Uma noite movimentada tem algumas centenas de envios, insignificante para o servidor. O `flush()` da biblioteca envia em paralelo quando há vários aparelhos.

**Escalabilidade:** para o SaaS com muitos estabelecimentos, trocar `envio = after_response` por uma fila com worker dedicado. O código do job é o mesmo; só muda o `dispatch`.

**Pós-deploy:** `migrate`, gerar e configurar o VAPID no `.env`, `config:cache` + reiniciar o PHP (OPcache), conforme o padrão já conhecido do servidor.

---

## 12. Estimativa de complexidade

| Fase | Conteúdo | Esforço |
|---|---|---|
| 0. Preparação | Conferir HTTPS, `php -m`, saída 443, `APP_URL`; respostas D1–D8; gerar VAPID; ícones 512 e badge | 0,5 dia + o tempo do usuário |
| 1. Fundação PWA + inscrição | Manifest, SW, `garcom-push.js`, tela "Notificações no celular", card de convite, tabelas, `DispositivoPushService`, `EnviadorPush`, envio de teste, logout/login no aparelho compartilhado | 2–3 dias |
| 2. Eventos do MVP + parâmetros | `NotificadorGarcom`, `EventoGarcom`, `push_envios`, job, gatilhos (chamado, conta, aprovação, abrir mesa, pronto), `DestinatariosGarcom` (D2 + ponto aberto), parâmetros do Admin (9.5), preferências | 2,5–3,5 dias |
| 3. Confiabilidade + repetição | Revalidação, `Topic`/tag, reenvio, desativação por 404/410 e falhas, cooldown, fechamento de notificações resolvidas, "já atendido" na página, `push:lembrar-pendentes` (D7), `push:limpar`, painel de diagnóstico no /admin (opcional) | 2–2,5 dias |
| 4. Piloto | 1 semana no salão com 2–3 aparelhos (Android + iPhone), medir atraso e perdas pelos `push_envios` | 1 semana de calendário |
| 5. Futuro | Novos eventos conforme a necessidade, escalonamento para o gerente, badge, métricas | a definir |

**Total de desenvolvimento:** de 7 a 10 dias.

**Complexidade geral: média.** O maior risco não é técnico, e sim a **adoção nos aparelhos**: instalação no iPhone, permissões e bateria. Isso se resolve com uma boa tela de ativação e um piloto acompanhado.

---

## 13. Riscos e limitações

| Risco | Impacto | Mitigação |
|---|---|---|
| Entrega não garantida (offline, bateria, Doze, Foco, "Forçar parada") | Garçom não é avisado | Push é **adicional**: polling e mapa continuam. `Urgency: high` + TTL. Guia de bateria. Piloto mede a taxa de entrega |
| iPhone sem Tela de Início ou iOS < 16.4 | Sem push | Tela detecta e orienta. Política oficial explícita |
| Garçom nega a permissão | Sem push naquele aparelho | Pedir só no botão, com explicação antes. Guia para reativar |
| Aparelho compartilhado com sessão expirada | Aviso do garçom anterior aparece no aparelho | Revincular no login, desvincular no logout, limpar dispositivos ociosos |
| Notificação de algo já resolvido | Garçom vai à mesa à toa | Revalidação antes de enviar, TTL curto, `Topic` substitui as pendentes, painel fecha as resolvidas, página mostra "já atendido" |
| Excesso de alertas (mesa chamando várias vezes, rodadas prontas juntas) | Garçom passa a ignorar | 1 chamado PENDENTE por mesa e tipo, cooldown, tag por mesa (substitui em vez de empilhar), "abrir mesa" só para quem está em turno |
| Modo "todos com ponto aberto" + repetição | Vários aparelhos vibrando para o mesmo chamado, repetidamente | Tag por mesa, limite de repetições, e o "Atendido" de qualquer garçom encerra para todos (o painel aberto fecha a notificação nos outros aparelhos). Avaliar no piloto se o padrão de 2 min é curto demais |
| Garçom atende fisicamente, mas não toca em "Atendido" | Lembretes continuam até o limite | Comportamento desejado (incentiva marcar). O limite de segurança impede repetição infinita |
| Garçom sem cadastro de colaborador/ponto (ex.: Gerente) | Nunca está "em turno" e não recebe no modo "todos" nem o "abrir mesa" | Documentar. Quem deve receber precisa ter cadastro na folha e bater ponto |
| Cron `schedule:run` parado | Sem repetição e sem reenvio de falhas | Alerta na tela de parâmetros (8.5). O primeiro aviso não depende do cron |
| Evento disparado duas vezes (concorrência, retry) | Notificação duplicada | Unique `(pe_chave, pe_dispositivo_id)` + `insertOrIgnore` + `DB::afterCommit` |
| Transação revertida depois do evento | Push de algo que não aconteceu | Disparo só em `DB::afterCommit` |
| Hospedagem bloqueia saída para algum serviço de push | Plataforma inteira sem push | Teste da Fase 0. Plano B: fila + worker em outro host (improvável) |
| `dispatchAfterResponse` indisponível (CLI, ambiente) | Envio síncrono atrasa a resposta | Config `envio`. Em comandos agendados, o envio roda no fim do comando |
| Troca das chaves VAPID | Todas as inscrições são invalidadas | Gerar uma vez, documentar, nunca rodar em produção sem plano de reativação |
| Fora do horário de trabalho | Incômodo | Eventos pessoais só existem com mesa aberta pelo próprio garçom. Para os de turno, filtro de ponto aberto. "Pausar notificações" no aparelho. Foco do celular |
| Dependência nova | Manutenção | Biblioteca madura e ativa (v11, 07/2026), isolada atrás da interface `EnviadorPush` |

---

## 14. Plano de implementação incremental

Cada fase termina com testes verdes e para para a validação do usuário. Tudo atrás de `PIZZARIA_PUSH_ATIVO` (padrão `false`): o deploy não muda nada até a chave ser ligada.

### Fase 0: preparação (sem código de produto)
1. Conferir em produção: HTTPS válido, HTTP→HTTPS, `APP_URL` em https, `php -m` (curl/mbstring/openssl; gmp opcional), saída 443.
2. Obter as respostas de D1–D8 (seção 14.6).
3. Ícones `icon-512.png`, `icon-maskable-512.png` e `badge-96.png` (monocromático) em `public/img/marca/`.

### Fase 1: PWA + inscrição + teste
1. `sail composer require minishlink/web-push:^11` (após D5).
2. Migrations `dispositivos_push`, `push_envios` e `users.user_push_preferencias`. Models, casts e factories.
3. `config/pizzaria.php` → `push`; comando `push:gerar-vapid`.
4. Rotas `GET /garcom/manifest.webmanifest` e `GET /garcom/sw.js` no `GarcomPanelProvider::routes()`, fora do `authMiddleware` (o navegador busca os dois sem sessão garantida). Partial `pwa-head` via `PanelsRenderHook::HEAD_END`.
5. `resources/js/garcom-push.js` (entrada Vite) com: detecção de capacidade e plataforma, registro do SW com escopo `/garcom/`, ativar, sincronizar, desativar.
6. `DispositivoPushService` (registrar/revincular, validar endpoint, desativar, pausar), interface `EnviadorPush` + `MinishlinkEnviadorPush` + `FakeEnviadorPush`.
7. Página `NotificacoesCelular` (Livewire/Filament) + item no menu do usuário + card de convite no `MapaMesas`. Botão "Enviar teste".
8. Listener de `Logout` para desvincular o dispositivo da sessão.
9. Testes (seção 15) + `pint` + `npm run build`.

### Fase 2: eventos do MVP
1. `EventoPushGarcomEnum`, DTO `EventoGarcom` com fábricas (`chamado`, `aprovacao`, `pronto`), `NotificadorGarcom`, `EnviarPushGarcomJob`.
2. Gatilhos:
   - `MesaChamadoService::abrir`: só quando cria um chamado novo;
   - `PedidoMesaClienteService`: quando o pedido fica PENDENTE de aprovação;
   - `NotificacaoGarcomObserver` em `Pedido`: `wasChanged('pedido_status')` para `PRONTO`, mesa ou origem `GARCOM`.
3. Migration das colunas `empresa_push_*` + enum `DestinoAvisoMesaEnum` + seção "Notificações do salão" na `ConfiguracaoPedidos` (9.5).
4. `DestinatariosGarcom`: regras da seção 8.4, parâmetro D2 e ponto aberto (D3). A lógica de ponto aberto é extraída do `OperadorCozinhaService` para um service compartilhado, sem mudar o comportamento da cozinha. Aplica também as preferências do usuário.
5. Testes.

### Fase 3: confiabilidade
1. Revalidação no job; `TTL`, `Urgency` e `Topic`; tratamento por status HTTP; `dp_falhas_consecutivas`.
2. `push:reenviar-falhas` (1 min), `push:lembrar-pendentes` (1 min, D7, seção 8.5) e `push:limpar` (diário) no Kernel, com `withoutOverlapping`. Alerta de "scheduler parado" na seção de parâmetros.
3. Cooldown por mesa e tipo.
4. Fechamento das notificações resolvidas: o poll do `MapaMesas`/`AtenderMesa` expõe as chaves pendentes, e o script fecha as demais.
5. "Já atendido por…" ao abrir a URL de um evento resolvido.
6. (Opcional) Diagnóstico no /admin: dispositivos por usuário e últimos envios, só para o Admin.

### Fase 4: piloto
1. Ligar `PIZZARIA_PUSH_ATIVO` em produção, ativar em 2–3 aparelhos: Android/Chrome instalado, Android/Chrome em aba, iPhone na Tela de Início.
2. Uma semana de uso. Medir pelos `push_envios` a taxa de `ENVIADO`, de expirados e de falhas, e colher o relato dos garçons sobre atraso e ruído.
3. Ajustar TTL, cooldown e eventos. Decidir a Fase 5.

### 14.6 Decisões (respondidas pelo usuário em 02/11/2026)

| # | Pergunta | Decisão |
|---|---|---|
| **D1** | Eventos do MVP | **Somente:** chamou o garçom, pediu a conta, pedido do QR para aprovar, pedido pronto (mesa + retirada do garçom), quer abrir a mesa. Novos eventos entram depois, conforme a necessidade |
| **D2** | Quem recebe os avisos da mesa | **Parâmetro do Admin:** só o garçom que abriu a mesa **ou** todos os garçons com ponto aberto (`empresa_push_destino_mesa`, seção 9.5) |
| **D3** | Quem conta como "em turno" (online) | **Quem estiver com o ponto aberto** (sem alternativa "usou o painel") |
| **D4** | Plataformas oficiais | **Aprovado:** Android 9+ com Chrome e iPhone com iOS 16.4+ na Tela de Início; o resto como melhor esforço |
| **D5** | Dependência `minishlink/web-push` ^11 | **Aprovada** (o usuário delegou a escolha; recomendação técnica mantida) |
| **D6** | Aparelho compartilhado | **Aprovado:** vincula a quem entrar e desvincula de quem sair |
| **D7** | Lembrete se o chamado não for atendido | **Parâmetro do Admin:** repetir o aviso até o chamado ser atendido, com intervalo e limite de segurança (seção 8.5) |
| **D8** | Sessão expirada × toque na notificação | **Mantida como está:** pede o PIN e depois abre a mesa da notificação. Estender a sessão só se o piloto mostrar necessidade |

**D8 explicado:**

- O login no painel do garçom expira depois de um tempo sem uso: o `SESSION_LIFETIME`, 120 minutos no padrão do Laravel. É preciso conferir o valor no `.env` de produção.
- O push **continua chegando mesmo com o login expirado**, porque está ligado ao aparelho, não à tela aberta.
- Exemplo: o garçom ficou 3 horas sem mexer no celular e recebe "🔔 Mesa 05 está chamando". Ao tocar, aparece primeiro a **tela de PIN**. Depois do PIN, ele vai direto para a Mesa 05.
- A pergunta é se isso é aceitável ou se o garçom deve **continuar logado por mais tempo** no `/garcom`. Exemplo: o turno inteiro, até tocar em "Trocar garçom / Sair".
- **Proposta:** manter como está, com o PIN antes de abrir a mesa e o redirecionamento garantido para a mesa da notificação.
  - É mais seguro para o aparelho compartilhado: se o garçom esquecer de sair, o próximo a pegar o celular precisa do próprio PIN.
  - Digitar 4 dígitos é rápido.
  - Estender a sessão pode ser feito depois, se o piloto mostrar que incomoda.

---

## 15. Estratégia de testes

### 15.1 Automatizados (PHPUnit, banco isolado: ver o padrão `sail exec -u sail -e DB_DATABASE=...`)

O `FakeEnviadorPush` fica ligado no container. **Nenhum teste chama um serviço de push real.**

- **Inscrição:**
  - registra para um usuário do salão;
  - nega um usuário sem papel do salão;
  - o mesmo endpoint com outro usuário **revincula** (aparelho compartilhado);
  - rejeita endpoint `http://` ou com host fora da allowlist (SSRF);
  - o logout desvincula;
  - "desativar" e "pausar" respeitados.
- **Destinatários:**
  - no modo `GARCOM_DA_MESA`: chamado → só o dono da sessão; pronto → `pedido_usuario_garcom_id`;
  - no modo `TODOS_EM_TURNO`: todos com ponto aberto, e o dono incluído mesmo sem ponto aberto;
  - transferência de mesa → o novo dono;
  - abrir mesa → só quem está com o ponto aberto, em qualquer modo;
  - usuário com o ponto **fechado** ou em intervalo → não recebe os eventos de turno;
  - ninguém com ponto aberto → nenhum envio, sem erro;
  - preferência desligada → não envia;
  - usuário que perdeu o papel → não envia e desativa.
- **Gatilhos:**
  - chamado novo notifica, e o repetido (PENDENTE existente) não;
  - pedido pronto via `PedidoStatusService`, via `PedidoStatusGateway` (cozinha, com e sem Expedição) e via tela legada;
  - pedido de delivery pronto **não** notifica o garçom;
  - transação revertida não envia.
- **Idempotência e concorrência:** dois disparos com a mesma chave → 1 envio por dispositivo; o reenvio não duplica.
- **Revalidação:** chamado atendido antes do job → `DESCARTADO`; pedido já ENTREGUE → `DESCARTADO`.
- **Repetição (D7), com o relógio fixado:**
  - com o parâmetro desligado → nenhum lembrete;
  - ligado → lembrete a cada N min enquanto `PENDENTE`;
  - para ao marcar "Atendido", ao fechar a conta, ao abrir a mesa ou ao aprovar/recusar;
  - para no limite de repetições;
  - não duplica se o comando rodar duas vezes no mesmo minuto;
  - recalcula os destinatários a cada lembrete.
- **Parâmetros:** a seção "Notificações do salão" grava e valida os limites (intervalo de 1 a 30, repetições de 1 a 60) e só aparece para o Admin com o push ligado.
- **Respostas HTTP:** 201 → `ENVIADO`; 410/404 → dispositivo desativado; 429/500 → `FALHOU` e reenviado dentro do TTL, com no máximo 3 tentativas; após `pe_expira_em` → `EXPIRADO` sem novo envio.
- **Payload:** sem dados sensíveis; URL interna; `Topic` e TTL coerentes.
- **Rotas:** `manifest.webmanifest` (JSON válido, escopo `/garcom/`); `sw.js` (content-type, `Service-Worker-Allowed`, sem cache).
- **Regressão:** testes atuais do painel do garçom, do QR e da cozinha continuam verdes; `ViewsCompilamTest`.

### 15.2 Navegador (Playwright/Chromium, local)

- O manifest é reconhecido; o SW registra com escopo `/garcom/`; o botão "Ativar" chama a permissão só no clique (contexto com permissão concedida); a sincronização chama o método Livewire.
- **Push real não é testável no Playwright.** A entrega é validada manualmente (15.3).

### 15.3 Matriz manual em aparelhos reais (HTTPS obrigatório)

No celular, `http://IP-da-máquina` não é contexto seguro. Para testar antes da produção, use um túnel HTTPS temporário (ex.: Cloudflare Tunnel) ou um subdomínio de homologação.

| Aparelho | Painel visível | Outra app | Tela bloqueada | Navegador/app fechado | Offline → online < TTL | Offline → online > TTL | Logout/troca de garçom |
|---|---|---|---|---|---|---|---|
| Android + Chrome (aba) | | | | | | | |
| Android + Chrome (instalado) | | | | | | | |
| Android Samsung/Xiaomi (bateria otimizada) | | | | | | | |
| iPhone iOS 16.4+ (Tela de Início via Safari) | | | | | | | |
| iPhone (Tela de Início via Chrome) | | | | | | | |
| iPhone em aba (deve mostrar "instale") | — | — | — | — | — | — | — |

Também verificar: o clique abre a mesa certa; o clique com a sessão expirada leva ao login e volta; uma notificação resolvida some com o painel aberto; permissão negada mostra o guia.

---

## 16. Critérios de aceite

1. O garçom ativa as notificações no próprio celular em até 3 toques (Android) ou seguindo o guia de instalação (iPhone), **sem** a permissão ser pedida automaticamente ao abrir a página.
2. A tela mostra com clareza: ativado, desativado, bloqueado, incompatível ou "instale na Tela de Início", com um botão de teste que entrega em segundos.
3. Uma chamada de garçom pelo QR gera a notificação "🔔 Mesa X está chamando o garçom" no aparelho do **dono da mesa**, ou de **todos com ponto aberto**, conforme o parâmetro. Funciona com o painel em segundo plano, com o Chrome fechado ou com a tela bloqueada (Android), e com o web app fechado (iPhone 16.4+).
4. Um pedido pronto gera "🍕 Pedido pronto — Mesa X" para o garçom da rodada, ou para todos com ponto aberto no modo "todos". O pedido de delivery ou balcão não gera.
4a. Com "Repetir até atender" ligado, o chamado não atendido volta a notificar a cada N minutos, substituindo o aviso anterior, até alguém marcar "Atendido" ou até o limite configurado. Desligado, avisa uma vez só.
4b. O Admin altera "Quem recebe os avisos da mesa" e a repetição no /admin, sem deploy.
5. Tocar na notificação abre o painel na mesa ou na retirada correspondente. Nenhuma ação é executada sem a página, a sessão e a policy.
6. A mesma ocorrência não gera duas notificações no mesmo aparelho. Chamado já atendido ou pedido já servido não é enviado, e é fechado se o painel estiver aberto.
7. Só usuários com papel do salão recebem. Trocar de garçom no aparelho compartilhado passa os avisos para quem entrou. Ninguém recebe avisos de outro estabelecimento.
8. Inscrições expiradas (404/410) são desativadas automaticamente. O usuário consegue desativar ou pausar no próprio aparelho.
9. O painel continua funcionando exatamente como hoje com o push desligado, negado ou indisponível (polling, toasts, vibração).
10. Nenhuma infraestrutura nova (Redis, WebSocket, worker permanente, serviço pago) e só uma dependência PHP nova.
11. Testes automatizados da seção 15.1 verdes e matriz manual 15.3 preenchida no piloto.

---

## Apêndice A: prompt de implementação (segunda etapa)

> Decisões D1–D8 já incorporadas (02/11/2026). Pronto para enviar ao agente de implementação.

```text
Você vai implementar as notificações push do Painel do Garçom do RazelFood (Laravel 12 + Filament 4, Sail, MySQL),
seguindo o plano aprovado em docs/plano/notificacoes-push-painel-garcom.md. Leia o plano INTEIRO antes de começar;
ele é a fonte da verdade. Se algo no código contradisser o plano, pare e me pergunte.

DECISÕES APROVADAS (seção 14.6 do plano):
- D1 eventos do MVP — SOMENTE estes: CHAMOU_GARCOM, PEDIU_CONTA, APROVAR_PEDIDO, PEDIDO_PRONTO (mesa + origem GARCOM),
  ABRIR_MESA. Nenhum outro evento agora; o enum deve facilitar acrescentar depois.
- D2 parâmetro do Admin `empresa_push_destino_mesa` (GARCOM_DA_MESA | TODOS_EM_TURNO, padrão GARCOM_DA_MESA),
  editado numa seção "Notificações do salão" da página ConfiguracaoPedidos. O dono da mesa recebe sempre.
  ABRIR_MESA vai sempre para todos com ponto aberto. Regras completas na seção 8.4.
- D3 "em turno/online" = ponto aberto (última marcação nas últimas 16 h é entrada ou retorno de intervalo).
  Extraia a consulta de OperadorCozinhaService::usuariosComPontoAberto para um service compartilhado sem
  mudar o comportamento da cozinha. Não existe fallback "usou o painel".
- D4 suporte oficial: Android 9+ Chrome e iOS 16.4+ na Tela de Início; demais = melhor esforço.
- D5 dependência aprovada: minishlink/web-push ^11.
- D6 aparelho compartilhado: revincula no login, desvincula no logout.
- D7 parâmetros do Admin `empresa_push_repetir_ate_atender` (padrão true), `empresa_push_repetir_intervalo_min`
  (padrão 2, 1–30) e `empresa_push_repetir_max` (padrão 10, 1–60); comando push:lembrar-pendentes a cada minuto
  repete CHAMOU_GARCOM, PEDIU_CONTA, ABRIR_MESA e APROVAR_PEDIDO enquanto PENDENTE. Regras na seção 8.5.
- D8 sessão do /garcom: sem mudança; garantir que, ao tocar na notificação com a sessão expirada, depois do
  login (PIN ou senha) o garçom caia na URL da notificação (intended URL).

ESCOPO: Fases 1, 2 e 3 do plano (seção 14). Pare ao fim de CADA fase, mostre o que fez, os testes e aguarde meu OK.
Fase 0 (conferências em produção) é minha; a Fase 4 (piloto) também.

REGRAS DO PROJETO (obrigatórias):
- Todos os comandos via Sail (vendor/bin/sail artisan|composer|npm|php). Crie arquivos com `sail artisan make:*` --no-interaction.
- Siga as convenções: colunas com prefixo do model (dp_*, pe_*), services em app/Services, enums em app/Enums,
  PHPDoc em vez de comentários inline, tipos explícitos, chaves {} sempre, pt-BR nos textos.
- Tudo atrás de config('pizzaria.push.ativo') (env PIZZARIA_PUSH_ATIVO, padrão false). Com a chave desligada, nada
  muda no comportamento atual — nem script carregado, nem card de convite.
- NÃO altere o polling, os toasts, a vibração/bipe nem a ProducaoProntaNotification existentes. O push é adicional.
- O push NUNCA pode quebrar a transação de negócio: gatilhos só em DB::afterCommit, try/catch com Log::warning.
- Envio via EnviarPushGarcomJob::dispatchAfterResponse (padrão já usado em StoneVendaAutomaticaService); config
  pizzaria.push.envio = after_response|sync. Sem Redis, sem broadcasting, sem worker permanente.
- Isole a biblioteca atrás da interface App\Services\Push\EnviadorPush; nos testes use FakeEnviadorPush —
  nenhum teste pode chamar serviço de push real.
- Valide endpoint (https + host na allowlist config pizzaria.push.hosts_permitidos) — é proteção anti-SSRF.
- Service Worker e manifest servidos por ROTA no GarcomPanelProvider::routes() (/garcom/sw.js com
  Service-Worker-Allowed: /garcom/ e Cache-Control: no-cache; /garcom/manifest.webmanifest no molde do da cozinha).
  NÃO crie a pasta public/garcom/ (o .htaccess serviria o diretório no lugar da rota /garcom).
- Permissão de notificação só no clique de "Ativar" — nunca ao carregar a página.
- O clique na notificação só navega para URL interna /garcom/*; nenhuma ação executada pelo Service Worker.
- Sempre exibir notificação a cada push (exigência do iOS/userVisibleOnly); nada de push silencioso.
- MySQL: confira que nomes de índices compostos ficam < 64 caracteres (nomeie explicitamente se preciso).
- Testes PHPUnit (sail artisan make:test --phpunit) cobrindo a seção 15.1 do plano. Rode em BANCO ISOLADO:
  `vendor/bin/sail exec -u sail -e DB_DATABASE=testing_push php artisan test --compact <arquivo>` (crie o banco antes);
  sessões paralelas colidem no banco `testing`. Rode também os testes existentes do garçom, mesa-cliente e cozinha.
- `vendor/bin/sail bin pint --dirty --format agent` antes de finalizar cada fase.
- Rode `vendor/bin/sail npm run build` ao mexer em JS/CSS (produção não tem npm; public/build/ vai commitado).
- Se remover/renomear qualquer view ou componente Blade: grep sem truncar e `sail artisan view:cache`.

GIT:
- NÃO faça commit nem push sem eu pedir. Quando eu pedir: liste os arquivos por nome (nunca `git add -A`/`.`),
  confira `git diff` de arquivos que já estavam sujos antes da sua sessão e inclua só o seu trecho
  (vendor/ alterado pelo composer require entra junto: vendor/minishlink, deps novas e vendor/composer/*).
- Promoção para produção: scripts/promote-producao.sh, só quando eu autorizar o push.

ENTREGA DE CADA FASE: lista de arquivos criados/alterados, como testar manualmente (inclusive no celular via
túnel HTTPS), resultado dos testes, pendências de deploy (migrate, gerar VAPID com push:gerar-vapid e colocar no
.env de produção, config:cache + reiniciar PHP/OPcache, conferir cron schedule:run).
```

---

## Fontes consultadas

- WebKit: [Web Push for Web Apps on iOS and iPadOS](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/) (requisitos do iOS 16.4, Tela de Início, gesto do usuário, badge, Foco, navegadores de terceiros).
- WebKit: [News from WWDC25: WebKit in Safari 26](https://webkit.org/?p=16993) e [heise: iOS 26 muda o comportamento de web apps na Tela de Início](https://heise.de/-10749652) ("Abrir como app Web" por padrão).
- [9to5Mac: Chrome no iOS passa a adicionar web apps à Tela de Início](https://9to5mac.com/2023/07/14/google-chrome-web-apps-ios/) e [PushAlert: push no Chrome iOS](https://pushalert.co/blog/?p=2506).
- [OneSignal: Web push no iOS](https://documentation.onesignal.com/docs/en/web-push-for-ios) (Tela de Início obrigatória, sem push em modo privado).
- Firebase: [Prioridade de mensagens](https://firebase.google.com/docs/cloud-messaging/customize-messages/setting-message-priority) e [Opções/TTL](https://firebase.google.com/docs/cloud-messaging/concept-options.html) (Doze, `Urgency: high`, TTL até 28 dias, "aceito ≠ entregue").
- Packagist: [minishlink/web-push](https://packagist.org/packages/minishlink/web-push) (v11.0.0, PHP ≥ 8.2, curl/json/mbstring/openssl; gmp/bcmath opcionais).
- GitHub: [laravel-notification-channels/webpush composer.json](https://github.com/laravel-notification-channels/webpush) (Laravel ^12, minishlink ^11).
