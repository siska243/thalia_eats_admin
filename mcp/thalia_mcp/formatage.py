"""Mise en forme des réponses de l'API en texte lisible.

Ce module **n'additionne rien**. Tous les montants affichés sortent tels quels
de l'API : `sous_total`, `frais_livraison`, `service_price` et `total` sont
calculés par `QuotationService` côté Laravel. Mettre bout à bout des chiffres
déjà calculés est de la présentation ; en recalculer un serait recréer le
moteur de devis.
"""

from __future__ import annotations

from typing import Any

# Les raisons de refus d'un devis (`App\Services\QuotationService`). L'API rend
# un code stable, pas une phrase : c'est ici qu'il devient lisible.
RAISONS_DEVIS = {
    "panier_vide": "le panier est vide.",
    "quantite_invalide": "une quantité est invalide : elle doit valoir au moins 1.",
    "multi_restaurant": "une commande ne peut contenir que des plats d'un seul restaurant.",
    "devises_melangees": "tous les plats doivent être dans la même devise.",
    "restaurant_inattendu": "ces plats ne viennent pas du restaurant indiqué.",
}

# Les raisons d'un budget sans suggestion (`App\Services\BudgetSuggestionService`).
RAISONS_BUDGET = {
    "aucun_produit_correspondant": (
        "aucun plat ne correspond à votre recherche dans cette commune. "
        "Élargissez la recherche ou retirez le filtre de catégorie."
    ),
    "aucun_produit_dans_cette_devise": (
        "aucun plat n'est proposé dans cette devise pour cette commune. "
        "Essayez l'autre devise."
    ),
    "aucun_produit_disponible": (
        "aucun plat n'est disponible en ce moment dans cette commune."
    ),
    "aucun_restaurant_dans_cette_zone": (
        "aucun restaurant ne livre dans cette zone pour le moment."
    ),
}


def nombre(valeur: Any) -> str:
    """Un montant à la française : « 5 500 », « 1 250,50 »."""
    if valeur is None:
        return "?"

    try:
        v = float(valeur)
    except (TypeError, ValueError):
        return str(valeur)

    if abs(v - round(v)) < 0.005:
        texte = f"{round(v):,}".replace(",", " ")
    else:
        texte = f"{v:,.2f}".replace(",", " ").replace(".", ",")

    return texte


def code_devise(devise: Any) -> str:
    """Le code affichable d'une devise, quelle que soit la forme reçue."""
    if isinstance(devise, dict):
        for cle in ("code", "slug", "title"):
            valeur = devise.get(cle)
            if isinstance(valeur, str) and valeur.strip():
                return valeur.strip()
        return ""

    return devise.strip() if isinstance(devise, str) else ""


def montant(valeur: Any, devise: Any = None) -> str:
    code = code_devise(devise)

    return f"{nombre(valeur)} {code}".strip()


def detail_du_total(chiffrage: dict[str, Any]) -> str:
    """« 5 500 CDF (plat 3 000 + livraison 2 000 + service 500) ».

    Les quatre nombres viennent de l'API ; on les juxtapose, on n'en calcule
    aucun.
    """
    devise = chiffrage.get("currency")

    return (
        f"{montant(chiffrage.get('total'), devise)} "
        f"(plat {nombre(chiffrage.get('sous_total'))}"
        f" + livraison {nombre(chiffrage.get('frais_livraison'))}"
        f" + service {nombre(chiffrage.get('service_price'))})"
    )


def devis(chiffrage: dict[str, Any]) -> str:
    if not chiffrage.get("disponible"):
        raison = chiffrage.get("raison") or ""
        explication = RAISONS_DEVIS.get(raison, "ce panier ne peut pas être livré tel quel.")

        return f"Ce panier ne peut pas être chiffré : {explication}"

    return (
        f"Total : {detail_du_total(chiffrage)}\n"
        "Ce prix est celui que Thalia facturera si la pré-commande est créée maintenant."
    )


def _prix_du_plat(produit: dict[str, Any]) -> str:
    devise = produit.get("currency")
    texte = montant(produit.get("price"), devise)

    if produit.get("is_promotional") and produit.get("promotionnalPrice") is not None:
        texte += f" (en promotion : {montant(produit.get('promotionnalPrice'), devise)})"

    return texte


def resultats_de_recherche(reponse: dict[str, Any]) -> str:
    lignes_brutes = reponse.get("data") or []
    meta = reponse.get("meta") or {}

    if not lignes_brutes:
        return (
            "Aucun plat ne correspond à cette recherche. "
            "Essayez d'autres mots, une autre commune, ou retirez le filtre de prix."
        )

    lignes = []

    for entree in lignes_brutes:
        produit = entree.get("product") or {}
        restaurant = produit.get("restaurant") or {}
        distance = entree.get("distance_km")

        morceaux = [
            f"- {produit.get('title') or 'Plat sans titre'} — {_prix_du_plat(produit)}",
            f"  restaurant : {restaurant.get('name') or 'inconnu'}",
            f"  identifiant (uid) : {produit.get('uid')}",
        ]

        if distance is not None:
            morceaux.append(f"  distance : {nombre(distance)} km")

        lignes.append("\n".join(morceaux))

    total = meta.get("total")
    page = meta.get("current_page")
    dernieres = meta.get("last_page")

    entete = f"{len(lignes_brutes)} plat(s) affiché(s)"

    if total is not None:
        entete += f" sur {total} trouvé(s)"

    if page is not None and dernieres is not None:
        entete += f" — page {page} sur {dernieres}"

    return (
        entete
        + " :\n\n"
        + "\n\n".join(lignes)
        + "\n\nLe prix indiqué est celui du plat seul. Le prix réellement facturé, "
        "livraison et service compris, s'obtient avec « calculer_le_prix_du_panier »."
    )


def suggestions_de_budget(reponse: dict[str, Any]) -> str:
    budget = montant(reponse.get("budget"), reponse.get("currency"))
    suggestions = reponse.get("suggestions") or []

    if reponse.get("disponible") and suggestions:
        lignes = []

        for s in suggestions:
            produit = s.get("produit") or {}
            restaurant = s.get("restaurant") or {}
            devise = reponse.get("currency")

            bloc = [
                f"- {produit.get('title') or 'Plat sans titre'} "
                f"chez {restaurant.get('name') or 'restaurant inconnu'}",
                f"  {detail_du_total({**s, 'currency': devise})}",
                f"  il vous resterait {montant(s.get('reste'), devise)}",
                f"  identifiant (uid) : {produit.get('uid')}",
            ]

            if s.get("distance_km") is not None:
                bloc.append(f"  distance : {nombre(s['distance_km'])} km")

            lignes.append("\n".join(bloc))

        return (
            f"Avec {budget}, livraison et service compris, {len(suggestions)} "
            "possibilité(s) :\n\n" + "\n\n".join(lignes)
        )

    # Un budget qui n'achète rien doit être dit, jamais rendu par une liste vide.
    raison = reponse.get("raison") or ""
    option = reponse.get("option_la_moins_chere")

    if raison == "budget_insuffisant" and option:
        produit = option.get("produit") or {}
        restaurant = option.get("restaurant") or {}
        devise = reponse.get("currency")

        return (
            f"Avec {budget}, vous ne pouvez rien commander : le budget doit couvrir "
            "le plat, la livraison et le service.\n"
            f"L'option la moins chère est « {produit.get('title')} » chez "
            f"{restaurant.get('name') or 'un restaurant de la zone'}, à "
            f"{detail_du_total({**option, 'currency': devise})}.\n"
            f"Il vous manque {montant(option.get('manque'), devise)}."
        )

    explication = RAISONS_BUDGET.get(
        raison, "aucune option n'a pu être proposée pour cette demande."
    )

    return f"Avec {budget}, aucune commande n'est possible : {explication}"


def _lignes_de_produits(precommande: dict[str, Any]) -> list[str]:
    produits = precommande.get("produits")

    if not produits:
        return []

    devise = precommande.get("currency")

    return [
        f"  - {p.get('quantity')} × {p.get('title') or 'plat'} "
        f"({montant(p.get('price'), devise)} l'unité)"
        for p in produits
    ]


STATUTS = {
    "en_attente": "en attente de paiement",
    "payee": "payée",
    "expiree": "expirée",
}


def precommande(donnees: dict[str, Any], *, detaillee: bool = True) -> str:
    restaurant = donnees.get("restaurant") or {}
    statut = STATUTS.get(donnees.get("statut") or "", donnees.get("statut") or "inconnu")

    lignes = [
        f"Pré-commande {donnees.get('reference')} — {statut}",
        f"  restaurant : {restaurant.get('name') or 'inconnu'}",
        f"  {detail_du_total(donnees)}",
    ]

    if detaillee:
        lignes.extend(_lignes_de_produits(donnees))

    if donnees.get("expires_at"):
        lignes.append(f"  prix figé jusqu'au : {donnees['expires_at']}")

    lignes.append(f"  identifiant (uid) : {donnees.get('uid')}")

    commande = donnees.get("commande")

    if commande:
        lignes.append(f"  convertie en commande {commande.get('uid')}")

    lien = donnees.get("lien_paiement")

    if lien:
        lignes.append("")
        lignes.append(f"LIEN DE PAIEMENT : {lien}")
        lignes.append(
            "  C'est sur cette page que le client saisit lui-même son adresse exacte, "
            "le nom du destinataire et son téléphone. Ne lui demandez pas ces "
            "informations : transmettez-lui simplement le lien. Il expire avec la "
            "pré-commande."
        )
    elif detaillee and donnees.get("statut") == "expiree":
        lignes.append("")
        lignes.append(
            "Aucun lien de paiement : cette pré-commande a expiré. "
            "Recréez-en une pour obtenir un nouveau prix et un nouveau lien."
        )

    return "\n".join(lignes)


def liste_de_precommandes(precommandes: list[dict[str, Any]]) -> str:
    if not precommandes:
        return (
            "Vous n'avez aucune pré-commande récente. "
            "Créez-en une avec « creer_une_precommande »."
        )

    blocs = [precommande(p, detaillee=False) for p in precommandes]

    return (
        f"{len(precommandes)} pré-commande(s) récente(s) :\n\n"
        + "\n\n".join(blocs)
        + "\n\nPour obtenir le lien de paiement de l'une d'elles, appelez "
        "« voir_une_precommande » avec son identifiant."
    )
