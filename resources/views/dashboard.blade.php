<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12 space-y-3">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-0">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    {{ __("You're logged in!") }}
                </div>
            </div>
        </div>
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-0">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach ($mesas as $mesa)
                        @if ($mesa->mesa_status == 'LIBERADA')
                            <a href="{{ route('sessaoMesa', ['mesa_id' => $mesa->id]) }}"
                                class="p-4 bg-green-500 rounded-md text-white text-center">
                            @else
                                <a href="{{ route('sessaoMesa', ['mesa_id' => $mesa->id]) }}"
                                    class="p-4 bg-blue-500 rounded-md text-white text-center">
                        @endif
                        <div class="flex flex-col">
                            <i class='bx bx-chair text-xl'></i>
                            <span class="uppercase font-bold">{{ $mesa->mesa_nome }}</span>
                        </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
