<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Connecter un assistant à Thalia</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #faf9f7; color: #1a1a1a; }
        .carte { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        h2 { font-size: 15px; margin: 20px 0 8px; }
        p { line-height: 1.5; }
        label { display: block; font-size: 14px; margin-bottom: 4px; }
        input[type=email], input[type=password], button { width: 100%; padding: 12px; font-size: 16px; border-radius: 8px; box-sizing: border-box; }
        input[type=email], input[type=password] { border: 1px solid #ccc; margin-bottom: 12px; }
        button { border: 0; cursor: pointer; }
        .primaire { background: #1a1a1a; color: #fff; margin-bottom: 8px; }
        .secondaire { background: #fff; color: #1a1a1a; border: 1px solid #ccc; }
        .erreur { color: #b00020; margin-bottom: 12px; }
        .bloc { background: #faf9f7; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
        ul { list-style: none; margin: 0; padding: 0; }
        li { font-size: 14px; padding: 3px 0; }
        .oui::before { content: "✓ "; color: #2e7d32; font-weight: 700; }
        .non::before { content: "✕ "; color: #c62828; font-weight: 700; }
        .discret { color: #777; font-size: 13px; }
    </style>
</head>
<body>
<div class="carte">
    <h1>{{ $client->client_name }} demande l'accès à votre compte Thalia</h1>

    <p>
        Connectez-vous pour autoriser cet assistant. Il pourra chercher un plat et préparer
        une commande à votre place — vous gardez la main sur le paiement.
    </p>

    <div class="bloc">
        <strong style="font-size:14px">Ce qu'un assistant peut faire</strong>
        <ul>
            @foreach ($autorise as $ligne)
                <li class="oui">{{ $ligne }}</li>
            @endforeach
        </ul>

        <strong style="font-size:14px; display:block; margin-top:12px">Ce qu'il ne pourra jamais faire</strong>
        <ul>
            @foreach ($jamais as $ligne)
                <li class="non">{{ $ligne }}</li>
            @endforeach
        </ul>
    </div>

    <p class="discret">
        Vous pourrez retirer cet accès à tout moment depuis « Assistants connectés », dans
        votre compte.
    </p>

    @if ($erreur)
        <p class="erreur">{{ $erreur }}</p>
    @endif

    <form method="POST" action="{{ route('oauth.authorize.store') }}">
        @csrf

        {{-- Les paramètres du protocole repartent tels qu'ils sont arrivés. Ils
             sont revérifiés à la réception : rien ici n'est digne de confiance. --}}
        @foreach ($parametres as $nom => $valeur)
            @if ($valeur !== null && $valeur !== '')
                <input type="hidden" name="{{ $nom }}" value="{{ $valeur }}">
            @endif
        @endforeach

        <h2>Votre compte Thalia</h2>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" autocomplete="username" value="{{ $email }}" required>

        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>

        <button type="submit" class="primaire" name="decision" value="autoriser">Autoriser {{ $client->client_name }}</button>
        <button type="submit" class="secondaire" name="decision" value="refuser" formnovalidate>Refuser</button>
    </form>
</div>
</body>
</html>
