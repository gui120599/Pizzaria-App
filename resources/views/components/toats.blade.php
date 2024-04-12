@props(['session'])

@if (session('success'))
    <div id="successToast"
        class="hidden fixed top-8 right-8 z-40 max-w-max bg-green-100 text-green-700 rounded-lg border border-green-200 z-40 px-4 py-3 shadow-lg shadow-green-400/10 transition-transform duration-300 transform scale-0">
        <div class="flex items-center gap-2">
            <i class='bx bx-check-square bx-tada text-4xl'></i>
            <p class="font-medium">{{ session('success') }}</p>
            <button class="rounded-lg border border-green-200 p-1 hover:bg-green-200"
                onclick="closeToast('successToast')">
                <i class='bx bx-x text-2xl'></i>
            </button>
        </div>
    </div>
@endif

@if (session('error'))
    <div id="errorToast"
        class="hidden fixed top-8 right-8 z-40 z-40 max-w-max bg-red-100 text-red-700 rounded-lg border border-red-200 z-40 px-4 py-3 shadow-lg shadow-red-400/10 transition-transform duration-300 transform scale-0">
        <div class="flex items-center gap-2">
            <i class='bx bxs-error bx-tada text-4xl'></i>
            <p class="font-medium">{{ session('error') }}</p>
            <button class="rounded-lg border border-red-200 p-1 hover:bg-red-200" onclick="closeToast('errorToast')">
                <i class='bx bx-x text-2xl'></i>
            </button>
        </div>
    </div>
@endif

<div id="errorToast1"
    class="hidden fixed top-8 right-8 z-40 max-w-max bg-red-100 text-red-700 rounded-lg border border-red-200 z-40 px-4 py-3 shadow-lg shadow-red-400/10 transition-transform duration-300 transform scale-0">
    <div class="flex items-center gap-2">
        <i class='bx bxs-error bx-tada text-4xl'></i>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button class="rounded-lg border border-red-200 p-1 hover:bg-red-200" onclick="closeToast('errorToast')">
            <i class='bx bx-x text-2xl'></i>
        </button>
    </div>
</div>


<script>
    function showToast(toastId) {
        var toast = document.getElementById(toastId);
        toast.classList.remove('hidden');
        toast.classList.remove('scale-0'); // Adicionando a classe de escala para suavizar a entrada
        setTimeout(function() {
            toast.classList.add('scale-0'); // Adicionando a classe de escala para suavizar a saída
            setTimeout(function() {
                toast.classList.add('hidden');
            }, 300); // Ajuste de tempo para coincidir com a duração da transição (300ms)
        }, 7000);
    }

    function closeToast(toastId) {
        var toast = document.getElementById(toastId);
        toast.classList.add('scale-0');
        setTimeout(function() {
            toast.classList.add('hidden');
        }, 300);
    }

    window.onload = function() {
        @if (session('success'))
            showToast('successToast');
        @endif

        @if (session('error'))
            showToast('errorToast');
        @endif

        @if ($errors->any())
            showToast('errorToast1');
            // Oculta todas as seções ao carregar a página
            $('.section-list').hide();

            // Oculta todas as seções e mostra apenas a correspondente
            $('.max-w-7xl .bg-white').hide();
            $('.section-create').show();

            // Destaca visualmente o link ativo
            $('.nav-link').removeClass('active');
            $('.section-create-link').addClass('active');
        @endif
    }
</script>
