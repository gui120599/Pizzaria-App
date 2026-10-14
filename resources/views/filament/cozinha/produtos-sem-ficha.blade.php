{{-- Produtos produzidos vendidos sem ficha de preparo, do mais vendido para o menos. --}}
@php use App\Filament\Resources\FichasTecnicas\FichaTecnicaResource; @endphp

@if ($linhas->isEmpty())
    <div class="text-sm text-gray-500 dark:text-gray-400">Todos os produtos vendidos no período têm ficha de preparo. 🎉</div>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="py-2 pr-3">Produto</th>
                    <th class="py-2 pr-3">Tamanho / categoria</th>
                    <th class="py-2 pr-3 text-right">Vendidos</th>
                    <th class="py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($linhas as $linha)
                    <tr>
                        <td class="py-2 pr-3 font-semibold text-gray-950 dark:text-white">{{ $linha->produto }}</td>
                        <td class="py-2 pr-3 text-gray-600 dark:text-gray-300">{{ $linha->categoria ?? '—' }}</td>
                        <td class="py-2 pr-3 text-right tabular-nums">{{ \App\Support\FormatoQuantidade::item($linha->quantidade) }}</td>
                        <td class="py-2 text-right">
                            @if (FichaTecnicaResource::canCreate())
                                <a href="{{ FichaTecnicaResource::getUrl('create', ['produto' => $linha->produto_id]) }}" class="text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">Criar ficha</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
