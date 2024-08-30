<script type="module">
        // Converte o array $data do Blade para um objeto JavaScript
        let response = @json($data);

    // Abre a URL contida no campo 'uri' em uma nova aba
    window.open(response.uri, '_blank');

    // Aguarda 2 segundos antes de redirecionar para a rota 'dashboard'
    setTimeout(() => {
        window.location.href = "{{ route('venda') }}";
    }, 2000);
</script>

