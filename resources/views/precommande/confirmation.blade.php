<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Validez le paiement sur votre téléphone</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #faf9f7; color: #1a1a1a; }
        .carte { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; }
    </style>
</head>
<body>
<div class="carte">
    <h1>Validez sur votre téléphone</h1>

    {{-- C'est le moment ou l'application laisse le client sans nouvelle : le
         debit mobile money attend une confirmation sur le combine, et rien a
         l'ecran ne le dit. --}}
    <p>Une demande de paiement de <strong>{{ $precommande->total }} {{ $precommande->currency?->code }}</strong>
        vient d'être envoyée au {{ \App\Helpers\CurrentHelpers::masquer($phone) }}.</p>

    <p>Composez votre code sur votre téléphone pour confirmer. Votre commande
        sera préparée dès le paiement reçu.</p>

    <p>Référence {{ $precommande->refernce }}.</p>
</div>
</body>
</html>
