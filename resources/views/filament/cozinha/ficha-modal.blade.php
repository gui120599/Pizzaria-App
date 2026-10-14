{{-- Ficha técnica no tablet (spec §6.5): o mesmo componente da pré-visualização do admin, em fonte grande. --}}
<div wire:key="ficha-modal-{{ md5($ficha->titulo.$ficha->subtitulo.$ficha->observacao) }}">
    <x-cozinha.ficha-tecnica :ficha="$ficha" :grande="true" />
</div>
