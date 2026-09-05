<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pré-commande indisponible</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #faf9f7; color: #1a1a1a; }
        .carte { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; }
    </style>
</head>
<body>
<div class="carte">
    <h1>Ce lien n'est plus valable</h1>
    @if ($precommande->status === \App\Models\Precommande::STATUT_PAYEE)
        <p>Cette pré-commande a déjà été payée. Vous pouvez suivre votre commande dans l'application.</p>
    @else
        <p>Cette pré-commande a expiré. Demandez à votre assistant de vous en refaire une, à l'identique.</p>
    @endif
    <p>Référence {{ $precommande->refernce }}.</p>
</div>
</body>
</html>
