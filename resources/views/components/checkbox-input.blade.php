@props(['disabled' => false, 'checked' => false])

<input 
    type="checkbox"
    {{ $disabled ? 'disabled' : '' }}
    {{ $checked ? 'checked' : '' }}
    {!! $attributes->merge(['class' => 'rounded border-gray-300 text-black shadow-sm focus:ring-black']) !!}>
