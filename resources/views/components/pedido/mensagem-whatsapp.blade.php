@props(['id', 'texto', 'rotulo' => 'Mensagem para o cliente', 'linhas' => 10, 'editavel' => true])

{{--
    Texto pronto para o atendente colar no WhatsApp do cliente, com botão
    Copiar. Editável: o wire:ignore mantém o que o atendente mudou entre
    re-renders. Somente leitura (editavel=false) acompanha a tela.
--}}
<div {{ $attributes->class('space-y-1.5 text-left') }}
     @if ($editavel) wire:ignore @endif
     x-data="{
         copiado: false,
         async copiar() {
             const campo = this.$refs.mensagem;
             try { await navigator.clipboard.writeText(campo.value); } catch (_) { campo.select(); document.execCommand('copy'); }
             this.copiado = true;
             setTimeout(() => this.copiado = false, 2000);
         },
     }">
    <label for="{{ $id }}" class="flex items-center gap-1 text-xs font-semibold text-gray-700 dark:text-gray-300">
        <i class='bx bxl-whatsapp text-base text-green-600'></i> {{ $rotulo }}
    </label>
    <textarea id="{{ $id }}" x-ref="mensagem" rows="{{ $linhas }}" @readonly(! $editavel) @unless ($editavel) wire:key="{{ $id }}-{{ md5($texto) }}" @endunless
        class="w-full rounded-xl border border-gray-300 dark:border-white/10 bg-gray-50 dark:bg-gray-900 px-3 py-2 text-sm text-gray-800 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500">{{ $texto }}</textarea>
    <button type="button" @click="copiar()"
        class="w-full py-2.5 inline-flex items-center justify-center gap-1.5 bg-green-600 hover:bg-green-500 text-white rounded-xl text-sm font-bold transition-colors">
        <i class='bx' :class="copiado ? 'bx-check' : 'bx-copy'"></i>
        <span x-text="copiado ? 'Copiado!' : 'Copiar mensagem'">Copiar mensagem</span>
    </button>
</div>
