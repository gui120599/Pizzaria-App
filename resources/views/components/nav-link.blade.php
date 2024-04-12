@props(['active'])

@php
$classes = ($active ?? false)
            ? 'nav-link p2.5 mt-3 h-16 flex items-center rounded-md px-4 cursor-pointer bg-teal-700 transition duration-150 ease-in-out'
            : 'nav-link p2.5 mt-3 h-10 flex items-center rounded-md px-4 cursor-pointer hover:bg-teal-900 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
