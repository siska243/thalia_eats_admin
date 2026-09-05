<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payer votre pré-commande</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #faf9f7; color: #1a1a1a; }
        .carte { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; }
        .ligne { display: flex; justify-content: space-between; padding: 6px 0; }
        .total { font-weight: 700; border-top: 1px solid #e5e5e5; margin-top: 12px; padding-top: 12px; }
        input, button { width: 100%; padding: 12px; font-size: 16px; border-radius: 8px; box-sizing: border-box; }
        input { border: 1px solid #ccc; margin-bottom: 12px; }
        button { border: 0; background: #1a1a1a; color: #fff; }
        .erreur { color: #b00020; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="carte">
    <h1>Votre pré-commande</h1>
    <p>Référence {{ $precommande->refernce }} — {{ $precommande->restaurant?->name }}</p>

    @foreach ($precommande->products as $ligne)
        <div class="ligne">
            <span>{{ $ligne->product?->title }} × {{ $ligne->quantity }}</span>
            <span>{{ $ligne->price }} {{ $precommande->currency?->code }}</span>
        </div>
    @endforeach

    <div class="ligne"><span>Livraison</span><span>{{ $precommande->frais_livraison }}</span></div>
    <div class="ligne"><span>Service</span><span>{{ $precommande->service_price }}</span></div>
    <div class="ligne total"><span>Total</span><span>{{ $precommande->total }} {{ $precommande->currency?->code }}</span></div>

    <p>Livraison à {{ $precommande->adresse_delivery }}, pour {{ $precommande->recipient_name }}.</p>

    @foreach ($errors->all() as $erreur)
        <p class="erreur">{{ $erreur }}</p>
    @endforeach

    <form method="POST" action="{{ route('precommande.paiement.initier', ['uid' => $uid]) }}">
        @csrf
        <label for="phone">Numéro mobile money</label>
        <input id="phone" name="phone" inputmode="tel" placeholder="+243…" required>
        <button type="submit">Payer</button>
    </form>
</div>
</body>
</html>
