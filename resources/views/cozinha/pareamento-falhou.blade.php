<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pareamento do tablet</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #111827; color: #f9fafb; font-family: system-ui, sans-serif; padding: 16px; }
        .caixa { max-width: 28rem; text-align: center; }
        h1 { font-size: 1.5rem; margin-bottom: .75rem; }
        p { color: #d1d5db; font-size: 1.1rem; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="caixa">
        <h1>Não foi possível parear</h1>
        <p>{{ $mensagem }}</p>
        <p>No admin, em <strong>Cozinha → Tablets da Cozinha</strong>, toque em <strong>Parear</strong> e leia o QR novo.</p>
    </div>
</body>
</html>
