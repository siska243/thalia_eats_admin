<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Votre code de connexion</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:Arial,Helvetica,sans-serif;color:#1c1917;">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;">
        <h1 style="margin:0 0 16px;font-size:20px;">Votre code de connexion</h1>

        <p style="margin:0 0 24px;font-size:15px;line-height:24px;color:#57534e;">
            Saisissez ce code pour terminer votre connexion. Il est valable
            {{ $minutes }} minutes.
        </p>

        <p style="margin:0 0 24px;font-size:34px;font-weight:bold;letter-spacing:8px;text-align:center;">
            {{ $code }}
        </p>

        {{-- Dire quoi faire si on n'a rien demande : c'est le seul signal
             qu'un client recevra si quelqu'un connait son mot de passe. --}}
        <p style="margin:0;font-size:13px;line-height:20px;color:#78716c;">
            Vous n’avez pas tenté de vous connecter ? Quelqu’un connaît
            peut-être votre mot de passe : changez-le sans tarder. Ne
            communiquez ce code à personne.
        </p>
    </div>
</body>
</html>
