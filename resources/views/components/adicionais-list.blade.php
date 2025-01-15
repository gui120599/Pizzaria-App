@props(['adicionais' => [], 'produtoAdicionais' => [], 'produtoId' => null])

<div class="adicionais-list">
    @foreach ($adicionais as $adicional)
        @php
            $isActive = in_array($adicional->id, $produtoAdicionais);
        @endphp
        <div class="flex items-center justify-between p-2 border-b border-gray-200">
            <span>{{ $adicional->adicional_nome }}</span>
            <button class="toggle-icon" data-adicional-id="{{ $adicional->id }}" data-produto-id="{{ $produtoId ?? '' }}"
                onclick="toggleAdicional(this)">
                @if ($isActive)
                    <i class='bx bxs-toggle-right' style='color:#00fd83'></i>
                @else
                    <i class='bx bx-toggle-left'></i>
                @endif
            </button>
        </div>
    @endforeach
</div>

<script>
    function toggleAdicional(button) {
        const adicionalId = $(button).data('adicional-id');
        const produtoId = $(button).data('produto-id');
        const icon = $(button).find('i');
        const isActive = icon.hasClass('bxs-toggle-right');

        $.ajax({
            type: "POST",
            url: `/produto/${produtoId}/adicional/${adicionalId}/toggle`,
            data: {
                'isActive': !isActive, // Inverte o estado atual
                '_token': '{{ csrf_token() }}' // CSRF Token
            },
            dataType: "json",
            success: function(response) {
                if (response.success) {
                    if (isActive) {
                        icon.removeClass('bxs-toggle-right').addClass('bx-toggle-left').css('color', '');
                    } else {
                        icon.removeClass('bx-toggle-left').addClass('bxs-toggle-right').css('color',
                            '#00fd83');
                    }
                }
            },
            error: function() {
                alert('Erro ao alternar o adicional. Por favor, tente novamente.');
            }
        });
    }
</script>
