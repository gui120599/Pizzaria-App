@props(['value' => null, 'messages' => []])

<div class="flex flex-row space-x-1">
    <label {{ $attributes->merge(['class' => 'block font-medium text-sm text-gray-700']) }}>
        {{ $value ?? $slot }}
    </label>

    @if (!empty($messages))
        @foreach ((array) $messages as $message)
            <span class="text-xs text-red-600">{{ $message }}</span>
        @endforeach
    @endif
</div>

