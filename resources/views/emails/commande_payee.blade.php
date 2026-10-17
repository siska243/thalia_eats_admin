@php
    /**
     * Le recapitulatif d'une commande payee.
     *
     * Un seul gabarit pour les trois destinataires : le contenu est le meme,
     * seule l'introduction change. Trois gabarits auraient voulu dire trois
     * recapitulatifs a tenir d'accord.
     *
     * L'adresse de livraison et le telephone ne partent PAS au client : il
     * les connait, et un courriel qui traine dans une boite n'a pas a porter
     * le numero de quelqu'un plus longtemps que necessaire.
     */
    $devise = $commande->devise()?->code ?? '';
    $montant = fn ($valeur) => trim(number_format((float) $valeur, 2, ',', ' ') . ' ' . $devise);
    $lignes = $commande->product;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>Commande {{ $commande->refernce }}</title></head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:Arial,Helvetica,sans-serif;color:#1c1917;">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;padding:32px;">

    <h1 style="margin:0 0 8px;font-size:20px;">Commande {{ $commande->refernce }}</h1>

    <p style="margin:0 0 24px;font-size:15px;line-height:24px;color:#57534e;">{{ $intro }}</p>

    <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <tbody>
        @foreach ($lignes as $ligne)
            <tr>
                <td style="padding:8px 0;border-bottom:1px solid #e7e5e4;">
                    {{ $ligne->quantity }} ×
                    {{ $ligne->product?->title ?? 'Plat retiré du catalogue' }}
                </td>
                <td style="padding:8px 0;border-bottom:1px solid #e7e5e4;text-align:right;white-space:nowrap;">
                    {{ $montant((float) $ligne->price * (float) $ligne->quantity) }}
                </td>
            </tr>
        @endforeach

        <tr>
            <td style="padding:8px 0;color:#78716c;">Livraison</td>
            <td style="padding:8px 0;text-align:right;color:#78716c;">{{ $montant($commande->price_delivery) }}</td>
        </tr>

        @if ((float) $commande->price_service > 0)
            <tr>
                <td style="padding:0 0 8px;color:#78716c;">Service</td>
                <td style="padding:0 0 8px;text-align:right;color:#78716c;">{{ $montant($commande->price_service) }}</td>
            </tr>
        @endif

        <tr>
            <td style="padding:12px 0 0;border-top:2px solid #1c1917;font-weight:bold;">Total payé</td>
            <td style="padding:12px 0 0;border-top:2px solid #1c1917;text-align:right;font-weight:bold;">
                {{ $montant($commande->global_price) }}
            </td>
        </tr>
        </tbody>
    </table>

    {{-- L'adresse ne sert qu'a ceux qui doivent s'y rendre ou la suivre. --}}
    @if ($pour !== 'client')
        <div style="margin-top:24px;padding-top:16px;border-top:1px solid #e7e5e4;font-size:14px;line-height:22px;color:#57534e;">
            <p style="margin:0 0 4px;"><strong>Livrer à</strong> : {{ $commande->adresse_delivery ?: '—' }}</p>

            @if ($commande->recipient_name || $commande->recipient_phone)
                <p style="margin:0 0 4px;">
                    <strong>Destinataire</strong> :
                    {{ $commande->recipient_name ?: '—' }}
                    @if ($commande->recipient_phone) · {{ $commande->recipient_phone }} @endif
                </p>
            @endif

            @if ($pour === 'admin' && $commande->user)
                <p style="margin:0;"><strong>Client</strong> : {{ trim($commande->user->name . ' ' . $commande->user->last_name) }} · {{ $commande->user->email }}</p>
            @endif
        </div>
    @endif

    <p style="margin:24px 0 0;font-size:12px;line-height:18px;color:#a8a29e;">
        Message automatique de Thalia Eats. Inutile d’y répondre.
    </p>
</div>
</body>
</html>
