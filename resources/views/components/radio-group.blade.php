@props([
    'options' => [],
    'valueField' => 'id',
    'displayField' => 'name',
    'name',
    'selectedValue' => null,
    'cols' => 2,
])

@php
    $gridCols = ['1' => 'grid-cols-1', '2' => 'grid-cols-2', '3' => 'grid-cols-3'][(string) $cols] ?? 'grid-cols-2';
@endphp

<div role="radiogroup" class="grid {{ $gridCols }} gap-2 mt-1">
    @foreach ($options as $option)
        @php
            $val   = is_array($option) ? ($option[$valueField] ?? null) : ($option->{$valueField} ?? null);
            $label = is_array($option) ? ($option[$displayField] ?? '') : ($option->{$displayField} ?? '');
            $rid   = $name . '_' . $val;
        @endphp
        <label for="{{ $rid }}"
               class="flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 cursor-pointer transition-colors hover:bg-gray-50
                      has-[:checked]:border-teal-500 has-[:checked]:bg-teal-50 has-[:checked]:ring-1 has-[:checked]:ring-teal-500">
            <input type="radio" id="{{ $rid }}" name="{{ $name }}" value="{{ $val }}"
                   {{ $val == $selectedValue ? 'checked' : '' }}
                   {{ $attributes->merge(['class' => 'text-teal-600 focus:ring-teal-500 h-4 w-4 cursor-pointer shrink-0']) }}>
            <span class="text-sm font-medium text-gray-700 truncate">{{ $label }}</span>
        </label>
    @endforeach
</div>
