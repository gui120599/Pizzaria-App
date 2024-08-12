@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm md:text-[10px] truncate overflow-ellipsis  text-gray-700']) }}>
    {{ $value ?? $slot }}
</label>
