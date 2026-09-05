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
        h2 { font-size: 17px; margin: 24px 0 8px; }
        label { display: block; font-size: 14px; margin-bottom: 4px; }
        input[type=text], input[type=tel], button { width: 100%; padding: 12px; font-size: 16px; border-radius: 8px; box-sizing: border-box; }
        input[type=text], input[type=tel] { border: 1px solid #ccc; margin-bottom: 12px; }
        button { border: 0; background: #1a1a1a; color: #fff; }
        .erreur { color: #b00020; margin-bottom: 12px; }
        .facultatif { color: #777; font-weight: 400; }
        .choix { display: flex; gap: 12px; margin-bottom: 12px; }
        .choix label { display: flex; align-items: center; gap: 6px; border: 1px solid #ccc; border-radius: 8px; padding: 12px; flex: 1; margin: 0; }
        .case { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-size: 14px; }
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

    @foreach ($errors->all() as $erreur)
        <p class="erreur">{{ $erreur }}</p>
    @endforeach

    <form method="POST" action="{{ $action }}">
        @csrf

        @if ($precommande->coordonneesCompletes())
            {{-- Ce lien signe peut avoir ete transfere, journalise par un proxy ou
                 laisse dans un historique de navigation. Le client reconnait sa
                 propre adresse aux premiers caracteres ; un tiers n'apprend ni ou ni
                 chez qui livrer. Les plats et le total restent lisibles : le payeur
                 doit savoir ce qu'il paie. --}}
            <p>Livraison à {{ \App\Helpers\CurrentHelpers::masquer($precommande->adresse_delivery) }}, pour {{ \App\Helpers\CurrentHelpers::masquer($precommande->recipient_name) }}.</p>
        @else
            <h2>Où livrer ?</h2>

            {{-- La commune a servi a calculer les frais de livraison, donc le
                 total figé : on l'affiche, on ne la fait pas ressaisir, et
                 aucune valeur de commune venue du formulaire n'est acceptée. --}}
            <p>Commune : <strong>{{ $precommande->town?->title }}</strong></p>

            <label for="adresse">Adresse</label>
            <input type="text" id="adresse" name="adresse" maxlength="255" value="{{ old('adresse') }}" required>

            <label for="street">Avenue / rue <span class="facultatif">(facultatif)</span></label>
            <input type="text" id="street" name="street" maxlength="255" value="{{ old('street') }}">

            <label for="number_street">Numéro <span class="facultatif">(facultatif)</span></label>
            <input type="text" id="number_street" name="number_street" maxlength="50" value="{{ old('number_street') }}">

            <label for="reference">Repère <span class="facultatif">(facultatif, aide le livreur à vous trouver)</span></label>
            <input type="text" id="reference" name="reference" maxlength="255" value="{{ old('reference') }}">

            <label for="recipient_name">Nom de la personne à livrer</label>
            <input type="text" id="recipient_name" name="recipient_name" maxlength="120" value="{{ old('recipient_name') }}" required>

            <label for="recipient_phone">Numéro que le livreur appellera</label>
            <input type="tel" id="recipient_phone" name="recipient_phone" maxlength="30" placeholder="+243…" value="{{ old('recipient_phone') }}" required>
        @endif

        <h2>Comment payer ?</h2>

        <div class="choix">
            <label><input type="radio" name="method" value="mobile" onchange="basculerMethode()" @checked(old('method', 'mobile') === 'mobile')> Mobile money</label>
            <label><input type="radio" name="method" value="cart" onchange="basculerMethode()" @checked(old('method') === 'cart')> Carte bancaire</label>
        </div>

        {{-- Le numéro du destinataire et celui du payeur ne sont pas la même
             chose : on peut se faire livrer chez sa mère et payer soi-même. La
             case couvre le cas courant sans confondre les deux. --}}
        <div id="bloc-mobile">
            <div class="case">
                {{-- Une case decochee n'est pas envoyee : sans ce champ cache,
                     old() ne saurait pas la distinguer d'un premier affichage
                     et la recocherait apres chaque erreur de saisie. --}}
                <input type="hidden" name="meme_numero" value="0">
                <input type="checkbox" id="meme_numero" name="meme_numero" value="1" onchange="basculerNumero()" @checked(old('meme_numero', '1') === '1')>
                <label for="meme_numero" style="margin:0">C'est le même numéro que celui du destinataire</label>
            </div>

            <div id="bloc-numero-payeur">
                <label for="phone">Numéro qui paie</label>
                <input type="tel" id="phone" name="phone" maxlength="30" placeholder="+243…" value="{{ old('phone') }}">
            </div>
        </div>

        <button type="submit">Payer</button>
    </form>
</div>

<script>
    function basculerMethode() {
        var mobile = document.querySelector('input[name=method]:checked').value === 'mobile';
        document.getElementById('bloc-mobile').hidden = !mobile;
    }

    function basculerNumero() {
        document.getElementById('bloc-numero-payeur').hidden = document.getElementById('meme_numero').checked;
    }

    basculerMethode();
    basculerNumero();
</script>
</body>
</html>
