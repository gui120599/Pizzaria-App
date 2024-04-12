@props(['options' => [], 'valueField' => 'id', 'displayField' => 'name', 'disabled' => false, 'selectedValue' => null])

<select {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'my-select border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm cursor-pointer']) !!}>
    @foreach ($options as $option)
        <option value="{{ $option[$valueField] }}" {{ $option[$valueField] == $selectedValue ? 'selected' : '' }} class="mt-1 w-full" style="cursor: pointer;">
            {{ $option[$displayField] }}
        </option>
    @endforeach
</select>

