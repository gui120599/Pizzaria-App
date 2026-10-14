{{-- Pré-visualização da ficha no admin: o mesmo componente do tablet. --}}
<div wire:key="ficha-preview-{{ md5(serialize([$ficha->subtitulo, $ficha->observacao, count($ficha->passos)])) }}">
    <x-cozinha.ficha-tecnica :ficha="$ficha" />
</div>
