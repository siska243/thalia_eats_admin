<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Connexion impossible</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #faf9f7; color: #1a1a1a; }
        .carte { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 8px; color: #b00020; }
        p { line-height: 1.5; }
    </style>
</head>
{{-- Aucun lien de retour, et surtout aucune redirection : l'adresse fournie
     n'a pas pu être vérifiée, donc on ne s'y rend pas et on n'y envoie rien. --}}
<body>
<div class="carte">
    <h1>{{ $titre }}</h1>
    <p>{{ $message }}</p>
</div>
</body>
</html>
