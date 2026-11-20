<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Comprovante da compra #{{ $compra->id }}</title>
    <style>
        :root { color-scheme: light dark; --fundo: #f6f6f4; --cartao: #fff; --texto: #1f2328; --suave: #6b7280; --marca: #c2410c; --ok: #15803d; --erro: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --fundo: #18181b; --cartao: #27272a; --texto: #f4f4f5; --suave: #a1a1aa; } }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--fundo); color: var(--texto); }
        main { max-width: 480px; margin: 0 auto; padding: 24px 16px; }
        .cartao { background: var(--cartao); border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 1.25rem; margin: 0 0 4px; }
        p { margin: 0 0 12px; color: var(--suave); line-height: 1.4; }
        label.botao, button { display: block; width: 100%; text-align: center; padding: 14px; border: 0; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        label.botao { background: var(--marca); color: #fff; margin-top: 16px; }
        button { background: var(--texto); color: var(--cartao); margin-top: 12px; }
        button:disabled { opacity: .5; }
        input[type=file] { display: none; }
        img { width: 100%; border-radius: 12px; margin-top: 16px; display: none; }
        .status { margin-top: 12px; font-weight: 600; }
        .status.ok { color: var(--ok); } .status.erro { color: var(--erro); }
    </style>
</head>
<body>
<main>
    <div class="cartao">
        <h1>Comprovante da compra #{{ $compra->id }}</h1>
        <p>{{ $compra->prestador?->nome_exibicao }} · {{ $compra->compra_data_emissao?->format('d/m/Y') }} · R$ {{ number_format((float) $compra->compra_valor_total, 2, ',', '.') }}</p>
        @if ($anexos > 0)
            <p>Esta compra já tem {{ $anexos }} {{ $anexos === 1 ? 'foto anexada' : 'fotos anexadas' }}. Você pode enviar mais uma.</p>
        @endif

        <form id="formulario" method="post" action="{{ request()->fullUrl() }}" enctype="multipart/form-data">
            @csrf
            <label class="botao" for="foto">Tirar ou escolher foto</label>
            <input id="foto" name="foto" type="file" accept="image/*" capture="environment">
            <img id="previa" alt="Prévia do comprovante">
            <button id="enviar" type="submit" disabled>Enviar comprovante</button>
            <div id="status" class="status" role="status"></div>
        </form>
    </div>
</main>
<script>
    (() => {
        const LADO_MAXIMO = 1600;
        const campo = document.getElementById('foto');
        const previa = document.getElementById('previa');
        const botao = document.getElementById('enviar');
        const status = document.getElementById('status');
        let arquivoReduzido = null;

        const reduzir = (arquivo) => new Promise((resolve) => {
            const imagem = new Image();
            imagem.onload = () => {
                const escala = Math.min(1, LADO_MAXIMO / Math.max(imagem.width, imagem.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(imagem.width * escala);
                canvas.height = Math.round(imagem.height * escala);
                canvas.getContext('2d').drawImage(imagem, 0, 0, canvas.width, canvas.height);
                canvas.toBlob((blob) => resolve(blob ?? arquivo), 'image/jpeg', 0.82);
                URL.revokeObjectURL(imagem.src);
            };
            imagem.onerror = () => resolve(arquivo);
            imagem.src = URL.createObjectURL(arquivo);
        });

        campo.addEventListener('change', async () => {
            const arquivo = campo.files[0];
            status.textContent = '';
            if (!arquivo) { return; }
            arquivoReduzido = await reduzir(arquivo);
            previa.src = URL.createObjectURL(arquivoReduzido);
            previa.style.display = 'block';
            botao.disabled = false;
        });

        document.getElementById('formulario').addEventListener('submit', async (evento) => {
            if (!arquivoReduzido) { return; }
            evento.preventDefault();
            botao.disabled = true;
            status.className = 'status';
            status.textContent = 'Enviando…';

            const dados = new FormData();
            dados.append('foto', arquivoReduzido, 'comprovante.jpg');

            try {
                const resposta = await fetch(window.location.href, {
                    method: 'POST',
                    body: dados,
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                });
                const corpo = await resposta.json().catch(() => ({}));
                if (!resposta.ok) {
                    throw new Error(corpo.message || (resposta.status === 403 ? 'Este link expirou. Peça um novo ao Claude.' : 'Não foi possível enviar.'));
                }
                status.className = 'status ok';
                status.textContent = corpo.mensagem || 'Comprovante enviado.';
            } catch (erro) {
                status.className = 'status erro';
                status.textContent = erro.message;
                botao.disabled = false;
            }
        });
    })();
</script>
</body>
</html>
