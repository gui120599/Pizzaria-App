@props(['disabled' => false, 'rows' => 3]) <!-- Defina o valor padrão para 'rows' como 3 -->

<textarea {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'border-gray-300 focus:border-black focus:ring-black rounded-md shadow-sm', 'rows' => $rows]) !!}></textarea>
