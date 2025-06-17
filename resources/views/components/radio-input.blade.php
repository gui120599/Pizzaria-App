@props(['opcoes', 'selectedId', 'name','id'])

@foreach ($opcoes as $opcao)
    <label for="opcao_entrega_{{ $opcao->id }}_id_{{$id}}"
        class="flex items-center cursor-pointer">
        <input id="opcao_entrega_{{ $opcao->id }}_id_{{$id}}"
               name="{{ $name }}"
               type="radio"
               value="{{ $opcao->id }}"
               class="form-radio text-green-500 h-5 w-5 cursor-pointer"
               {{ $opcao->id == $selectedId ? 'checked' : '' }}>
        <span class="ml-2 text-gray-700">
            {{ $opcao->opcaoentrega_nome }}
        </span>
    </label>
@endforeach
