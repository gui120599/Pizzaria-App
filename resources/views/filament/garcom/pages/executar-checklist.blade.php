{{--
    Execução do checklist (Painel do Garçom). Mobile-first: botões grandes,
    salvamento a cada resposta (toque ou blur), foto comprimida no navegador
    antes do upload (máx. 1600px, ver @script no fim).
--}}
@php
    use App\Enums\Checklist\ExecucaoStatus;
    use App\Enums\Checklist\ExigenciaFoto;
    use App\Enums\Checklist\TipoResposta;
    use App\Filament\Garcom\Pages\ChecklistsDoDia;
    use Illuminate\Support\Js;

    $execucao = $this->execucao;
    $podeResponder = $this->podeResponder;
    $pendencias = $this->pendenciasDoFechamento;
    $progresso = $execucao->progresso();
@endphp

<x-filament-panels::page>
    <div class="w-full space-y-5 pb-36"
         x-on:checklist-rolar-para.window="$nextTick(() => document.getElementById('item-' + $event.detail.itemId)?.scrollIntoView({ behavior: 'smooth', block: 'center' }))">

        @include('filament.garcom.partials.sem-conexao')

        {{-- Progresso e conformidade parcial (repetidos na barra fixa do rodapé) --}}
        <div>
            <div class="flex items-center justify-between gap-3 text-sm">
                <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $execucao->itens_respondidos }} de {{ $execucao->total_itens }} itens</span>
                <span class="flex items-center gap-2">
                    @if ($execucao->percentual_conformidade !== null)
                        <span @class([
                            'font-semibold tabular-nums',
                            'text-emerald-600 dark:text-emerald-400' => (float) $execucao->percentual_conformidade >= 95,
                            'text-amber-600 dark:text-amber-400' => (float) $execucao->percentual_conformidade < 95 && (float) $execucao->percentual_conformidade >= 80,
                            'text-red-600 dark:text-red-400' => (float) $execucao->percentual_conformidade < 80,
                        ])>{{ number_format((float) $execucao->percentual_conformidade, 0, ',', '.') }}% conforme</span>
                    @endif
                    <x-filament::badge :color="$execucao->status->getColor()">{{ $execucao->status->getLabel() }}</x-filament::badge>
                </span>
            </div>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                <div class="h-full rounded-full bg-primary-500 transition-all" style="width: {{ $progresso }}%"></div>
            </div>
        </div>

        @if ($execucao->status === ExecucaoStatus::Reprovado)
            <div class="space-y-3 rounded-2xl bg-red-50 p-4 ring-1 ring-red-200 dark:bg-red-500/10 dark:ring-red-500/30">
                <p class="font-semibold text-red-800 dark:text-red-300">Reprovado pela gerência</p>
                <p class="text-sm text-red-700 dark:text-red-300">{{ $execucao->motivo_reprovacao }}</p>
                @can('executar', $execucao)
                    <button type="button" wire:click="reabrir" wire:loading.attr="disabled"
                            class="w-full rounded-xl bg-red-600 py-3 text-base font-semibold text-white active:scale-[.98]">
                        Corrigir checklist
                    </button>
                @endcan
            </div>
        @elseif ($execucao->motivo_reprovacao && $podeResponder)
            <div class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                <span class="font-semibold">Correção pedida:</span> {{ $execucao->motivo_reprovacao }}
            </div>
        @endif

        @if (in_array($execucao->status, [ExecucaoStatus::Concluido, ExecucaoStatus::ConcluidoComPendencias, ExecucaoStatus::Aprovado], true))
            <div class="space-y-2 rounded-2xl bg-white p-5 text-center shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-4xl font-bold tabular-nums text-gray-950 dark:text-white">
                    {{ $execucao->percentual_conformidade !== null ? number_format((float) $execucao->percentual_conformidade, 0, ',', '.').'%' : '—' }}
                </p>
                <p class="text-sm text-gray-600 dark:text-gray-300">de conformidade · {{ $execucao->itens_nao_conformes }} não conforme(s)</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Finalizado por {{ $execucao->concluidoPor?->name ?? '—' }} às {{ $execucao->concluido_em?->format('H:i') }}
                    @if ($execucao->modelo?->exige_aprovacao && $execucao->status !== ExecucaoStatus::Aprovado) · aguardando aprovação @endif
                </p>
                <a href="{{ ChecklistsDoDia::getUrl() }}" wire:navigate
                   class="mt-2 block w-full rounded-xl bg-primary-600 py-3 text-base font-semibold text-white active:scale-[.98]">
                    Voltar aos checklists
                </a>
            </div>
        @elseif ($execucao->status === ExecucaoStatus::NaoRealizado)
            <div class="rounded-2xl bg-gray-100 p-4 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-300">
                O horário deste checklist passou antes da finalização. As respostas dadas ficaram registradas.
            </div>
        @endif

        {{-- Passagem de turno (RF14) --}}
        @if ($pendencias['fechamento'] && ($pendencias['naoConformidades']->isNotEmpty() || $pendencias['recados'] !== []))
            <div class="space-y-2 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/30">
                <p class="font-semibold text-amber-900 dark:text-amber-200">
                    Pendências do fechamento de {{ $pendencias['fechamento']->data_referencia->format('d/m') }}
                </p>
                <ul class="space-y-1 text-sm text-amber-900 dark:text-amber-200">
                    @foreach ($pendencias['naoConformidades'] as $nc)
                        <li class="flex gap-2"><span aria-hidden="true">•</span><span>{{ $nc->descricao }}</span></li>
                    @endforeach
                    @foreach ($pendencias['recados'] as $recado)
                        <li class="flex gap-2"><span aria-hidden="true">✎</span><span>{{ $recado }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($this->itensPorSecao as $secao => $itens)
            <section class="space-y-3" wire:key="secao-{{ md5($secao) }}">
                <h2 class="px-1 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $secao }}</h2>

                @foreach ($itens as $item)
                    @php
                        $tipo = $item->tipo_resposta;
                        $errosDoItem = $erros[$item->id] ?? [];
                        $pedeFoto = $tipo === TipoResposta::Foto
                            || $item->exigenciaFoto() === ExigenciaFoto::Sempre
                            || ($item->exigenciaFoto() === ExigenciaFoto::SeNaoConforme && $item->conforme === false);
                        $minimo = $item->regra('valor_minimo');
                        $maximo = $item->regra('valor_maximo');
                    @endphp
                    <article id="item-{{ $item->id }}" wire:key="item-{{ $item->id }}"
                             @class([
                                 'space-y-3 rounded-2xl bg-white p-4 shadow-sm ring-2 transition dark:bg-gray-900',
                                 'ring-amber-500' => $errosDoItem !== [],
                                 'ring-red-500' => $errosDoItem === [] && $item->conforme === false,
                                 'ring-emerald-500' => $errosDoItem === [] && $item->conforme === true,
                                 'ring-gray-200 dark:ring-white/10' => $errosDoItem === [] && $item->conforme === null,
                             ])>
                        <div>
                            <h3 class="text-base font-semibold leading-snug text-gray-950 dark:text-white">
                                {{ $item->titulo }}
                                @if ($item->obrigatorio())<span class="text-red-500" title="Obrigatório">*</span>@endif
                                @if ($item->critico())
                                    <span class="ms-1 inline-block rounded bg-red-100 px-1.5 py-0.5 align-middle text-[11px] font-semibold uppercase text-red-700 dark:bg-red-500/20 dark:text-red-300">Crítico</span>
                                @endif
                            </h3>
                            @if ($item->instrucao)
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $item->instrucao }}</p>
                            @endif
                        </div>

                        @if ($podeResponder)
                            @if (in_array($tipo, [TipoResposta::Check, TipoResposta::SimNao], true))
                                @php
                                    [$rotuloSim, $rotuloNao] = $tipo === TipoResposta::Check ? ['Feito', 'Não feito'] : ['Sim', 'Não'];
                                    $respondido = $item->foiRespondido();
                                @endphp
                                <div class="grid grid-cols-2 gap-2">
                                    <button type="button" wire:click="responder({{ $item->id }}, true)" wire:loading.attr="disabled" wire:target="responder({{ $item->id }}, true)"
                                            @class([
                                                'rounded-xl py-4 text-base font-semibold transition active:scale-[.97]',
                                                'bg-emerald-600 text-white' => $respondido && $item->valor_booleano === true,
                                                'bg-gray-100 text-gray-800 dark:bg-white/10 dark:text-gray-100' => ! ($respondido && $item->valor_booleano === true),
                                            ])>✓ {{ $rotuloSim }}</button>
                                    <button type="button" wire:click="responder({{ $item->id }}, false)" wire:loading.attr="disabled" wire:target="responder({{ $item->id }}, false)"
                                            @class([
                                                'rounded-xl py-4 text-base font-semibold transition active:scale-[.97]',
                                                'bg-red-600 text-white' => $respondido && $item->valor_booleano === false,
                                                'bg-gray-100 text-gray-800 dark:bg-white/10 dark:text-gray-100' => ! ($respondido && $item->valor_booleano === false),
                                            ])>✗ {{ $rotuloNao }}</button>
                                </div>
                            @elseif ($tipo->usaFaixa())
                                <div class="flex items-center gap-2">
                                    <input type="number" inputmode="decimal" step="0.1"
                                           wire:model.blur="respostas.{{ $item->id }}"
                                           aria-label="{{ $item->titulo }}"
                                           class="w-full rounded-xl border-gray-300 py-3 text-lg tabular-nums shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white">
                                    @if ($item->regra('unidade'))
                                        <span class="shrink-0 text-base font-semibold text-gray-600 dark:text-gray-300">{{ $item->regra('unidade') }}</span>
                                    @endif
                                </div>
                                @if ($minimo !== null || $maximo !== null)
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        Esperado:
                                        @if ($minimo !== null && $maximo !== null)
                                            de {{ number_format((float) $minimo, 1, ',', '.') }} a {{ number_format((float) $maximo, 1, ',', '.') }}
                                        @elseif ($minimo !== null)
                                            no mínimo {{ number_format((float) $minimo, 1, ',', '.') }}
                                        @else
                                            no máximo {{ number_format((float) $maximo, 1, ',', '.') }}
                                        @endif
                                        {{ $item->regra('unidade') }}
                                    </p>
                                @endif
                            @elseif ($tipo === TipoResposta::Selecao)
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($item->regra('opcoes', []) as $opcao)
                                        @php $escolhida = $item->valor_opcao === (string) $opcao['valor']; @endphp
                                        <button type="button" wire:click="responder({{ $item->id }}, {{ Js::from((string) $opcao['valor']) }})" wire:loading.attr="disabled"
                                                @class([
                                                    'rounded-xl px-4 py-3 text-base font-semibold transition active:scale-[.97]',
                                                    'bg-emerald-600 text-white' => $escolhida && ($opcao['conforme'] ?? false),
                                                    'bg-red-600 text-white' => $escolhida && ! ($opcao['conforme'] ?? false),
                                                    'bg-gray-100 text-gray-800 dark:bg-white/10 dark:text-gray-100' => ! $escolhida,
                                                ])>{{ $opcao['rotulo'] }}</button>
                                    @endforeach
                                </div>
                            @elseif ($tipo === TipoResposta::Texto)
                                <textarea wire:model.blur="respostas.{{ $item->id }}" rows="3" aria-label="{{ $item->titulo }}"
                                          class="w-full rounded-xl border-gray-300 text-base shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"></textarea>
                            @endif

                            @error("respostas.{$item->id}")
                                <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        @else
                            <p @class([
                                'text-base font-semibold',
                                'text-red-600 dark:text-red-400' => $item->conforme === false,
                                'text-gray-950 dark:text-white' => $item->conforme !== false,
                            ])>{{ $item->respostaFormatada() ?? 'Sem resposta' }}</p>
                        @endif

                        {{-- Fotos --}}
                        @if ($item->anexos->isNotEmpty() || ($podeResponder && $pedeFoto))
                            <div class="flex flex-wrap items-center gap-2">
                                @foreach ($item->anexos as $anexo)
                                    <div class="relative" wire:key="anexo-{{ $anexo->id }}">
                                        <a href="{{ $anexo->url() }}" target="_blank" rel="noopener">
                                            <img src="{{ $anexo->url() }}" alt="Foto" class="h-20 w-20 overflow-hidden rounded-xl bg-gray-100 object-cover text-[0px] dark:bg-white/5 ring-1 ring-gray-200 dark:ring-white/10" loading="lazy">
                                        </a>
                                        @if ($podeResponder)
                                            <button type="button" wire:click="removerFoto({{ $anexo->id }})" wire:confirm="Remover esta foto?"
                                                    class="absolute -right-2 -top-2 flex h-7 w-7 items-center justify-center rounded-full bg-gray-900 text-sm text-white shadow"
                                                    aria-label="Remover foto">×</button>
                                        @endif
                                    </div>
                                @endforeach

                                @if ($podeResponder && $pedeFoto)
                                    <label x-data="{ enviando: false, falhou: false }"
                                           class="flex h-20 min-w-20 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-gray-300 px-3 text-xs font-semibold text-gray-600 dark:border-white/20 dark:text-gray-300">
                                        <span x-show="! enviando" class="flex flex-col items-center gap-1">
                                            <x-filament::icon icon="heroicon-o-camera" class="h-6 w-6" />
                                            {{ $item->anexos->isEmpty() ? 'Tirar foto' : 'Outra foto' }}
                                        </span>
                                        <span x-show="enviando" x-cloak>Enviando…</span>
                                        <span x-show="falhou" x-cloak class="text-red-600">Falhou, tente de novo</span>
                                        <input type="file" accept="image/*" capture="environment" class="sr-only"
                                               x-on:change="
                                                   const arquivo = $event.target.files[0];
                                                   if (! arquivo) return;
                                                   enviando = true; falhou = false;
                                                   window.comprimirFotoChecklist(arquivo)
                                                       .catch(() => arquivo)
                                                       .then((foto) => $wire.upload('fotos.{{ $item->id }}', foto,
                                                           () => { enviando = false },
                                                           () => { enviando = false; falhou = true }))
                                                   $event.target.value = '';
                                               ">
                                    </label>
                                @endif
                            </div>
                            @error("fotos.{$item->id}")
                                <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        @endif

                        {{-- Observação: aberta quando não conforme, opcional nos demais --}}
                        @if ($podeResponder)
                            @php $observacaoAberta = filled($item->observacao) || $item->conforme === false; @endphp
                            {{-- A chave muda com o estado: o bloco é recriado e o Alpine reavalia "aberta". --}}
                            <div wire:key="observacao-{{ $item->id }}-{{ $observacaoAberta ? 'aberta' : 'fechada' }}"
                                 x-data="{ aberta: {{ Js::from($observacaoAberta) }} }">
                                <button type="button" x-show="! aberta" x-on:click="aberta = true"
                                        class="text-sm font-semibold text-primary-600 dark:text-primary-400">+ Observação</button>
                                <div x-show="aberta" @unless ($observacaoAberta) x-cloak @endunless class="space-y-1">
                                    <label for="observacao-{{ $item->id }}" class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                        Observação
                                        @if ($item->conforme === false && $item->exigeObservacaoNaoConforme())
                                            <span class="text-red-500">— descreva o problema</span>
                                        @endif
                                    </label>
                                    <textarea id="observacao-{{ $item->id }}" wire:model.blur="observacoes.{{ $item->id }}" rows="2"
                                              class="w-full rounded-xl border-gray-300 text-base shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"></textarea>
                                    @error("observacoes.{$item->id}")
                                        <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        @elseif ($item->observacao)
                            <p class="text-sm text-gray-600 dark:text-gray-300">Obs.: {{ $item->observacao }}</p>
                        @endif

                        @if ($errosDoItem !== [])
                            <ul class="space-y-0.5 text-sm font-medium text-amber-700 dark:text-amber-400">
                                @foreach ($errosDoItem as $mensagem)
                                    <li>{{ $mensagem }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($item->foiRespondido())
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $item->respondidoPor?->name_first ?? $item->respondidoPor?->name ?? '—' }} · {{ $item->respondido_em->format('H:i') }}
                            </p>
                        @endif
                    </article>
                @endforeach
            </section>
        @endforeach

        @if ($podeResponder)
            <div class="space-y-1">
                <label for="observacao-geral" class="px-1 text-sm font-medium text-gray-700 dark:text-gray-200">Observação geral (opcional)</label>
                <textarea id="observacao-geral" wire:model="observacaoGeral" rows="2"
                          class="w-full rounded-xl border-gray-300 text-base shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"></textarea>
                @error('observacaoGeral')
                    <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white/95 p-3 backdrop-blur dark:border-white/10 dark:bg-gray-900/95">
                <div class="mx-auto mb-2 flex max-w-xl items-center gap-3 text-xs font-semibold text-gray-600 dark:text-gray-300">
                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                        <div class="h-full rounded-full bg-primary-500 transition-all" style="width: {{ $progresso }}%"></div>
                    </div>
                    <span class="tabular-nums">{{ $execucao->itens_respondidos }}/{{ $execucao->total_itens }}</span>
                    @if ($execucao->percentual_conformidade !== null)
                        <span class="tabular-nums">· {{ number_format((float) $execucao->percentual_conformidade, 0, ',', '.') }}% conforme</span>
                    @endif
                </div>
                <button type="button" wire:click="finalizar" wire:loading.attr="disabled" wire:target="finalizar"
                        class="mx-auto block w-full max-w-xl rounded-xl bg-primary-600 py-4 text-base font-semibold text-white shadow active:scale-[.98] disabled:opacity-60">
                    <span wire:loading.remove wire:target="finalizar">Finalizar checklist</span>
                    <span wire:loading wire:target="finalizar">Finalizando…</span>
                </button>
            </div>
        @endif
    </div>
</x-filament-panels::page>

@script
<script>
    // Reduz a foto da câmera para no máximo 1600px (JPEG 80%) antes do upload.
    window.comprimirFotoChecklist ??= (arquivo, maximo = 1600, qualidade = 0.8) => new Promise((resolve, reject) => {
        const url = URL.createObjectURL(arquivo);
        const imagem = new Image();

        imagem.onload = () => {
            const escala = Math.min(1, maximo / Math.max(imagem.width, imagem.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(imagem.width * escala);
            canvas.height = Math.round(imagem.height * escala);
            canvas.getContext('2d').drawImage(imagem, 0, 0, canvas.width, canvas.height);
            URL.revokeObjectURL(url);
            canvas.toBlob(
                (blob) => blob ? resolve(new File([blob], 'foto.jpg', { type: 'image/jpeg' })) : reject(new Error('Falha ao comprimir')),
                'image/jpeg',
                qualidade,
            );
        };
        imagem.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Imagem inválida'));
        };
        imagem.src = url;
    });
</script>
@endscript
