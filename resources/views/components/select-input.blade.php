@props(['options' => [], 'valueField' => 'id', 'displayField' => 'name', 'disabled' => false, 'selectedValue' => null, 'descOpcaoVazia' => null])

<select {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'w-full my-select border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm cursor-pointer']) !!}>
    <option value="">{{ $descOpcaoVazia ?? 'Selecione uma opção' }}</option> {{-- opção vazia para dizer que é opcional --}}
    @foreach ($options as $option)
        <option value="{{ $option[$valueField] }}" {{ $option[$valueField] == $selectedValue ? 'selected' : '' }}>
            {{ $option[$displayField] }}
        </option>
    @endforeach
</select>
