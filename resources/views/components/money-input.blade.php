@props(['disabled' => false])

<div class="relative">
    <span class="absolute inset-y-0 left-0 flex items-center pl-2 mt-1 text-gray-300">R$</span>
    <input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'money pl-8 pr-3 py-1money border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm mt-1 w-full', 'placeholder' => '0,00']) !!}>
</div>