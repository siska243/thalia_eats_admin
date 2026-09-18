"""Les six outils. Un outil = un endpoint de l'API REST Laravel.

Les descriptions sont lues par le modèle **et** par le client final : elles
disent quand utiliser l'outil, dans quelles unités, et ce que l'outil ne fait
pas. Aucun outil n'efface ni n'annule quoi que ce soit — c'est une règle du
propriétaire, pas un oubli.
"""

from __future__ import annotations

from typing import Annotated, Any, Literal

from mcp.server.mcpserver import MCPServer
from mcp.server.mcpserver.exceptions import ToolError
from pydantic import BaseModel, Field

from . import formatage
from .client import appeler
from .erreurs import ErreurThalia


class LigneDePanier(BaseModel):
    """Un plat et sa quantité."""

    uid: Annotated[
        str,
        Field(description="L'identifiant (uid) du plat, tel que rendu par « chercher_un_plat »."),
    ]
    quantite: Annotated[int, Field(ge=1, description="Nombre de portions de ce plat.")]

    def vers_api(self) -> dict[str, Any]:
        return {"uid": self.uid, "quantity": self.quantite}


def _refuser(erreur: ErreurThalia) -> ToolError:
    """Un échec anticipé, dit au modèle avec sa nature.

    Le modèle doit savoir s'il est utile de réessayer : une panne réseau se
    retente, un refus métier non.
    """
    suffixe = (
        "\n(Incident technique : le même appel peut réussir en réessayant.)"
        if erreur.reessayable
        else "\n(Refus définitif : réessayer à l'identique donnera le même résultat.)"
    )

    return ToolError(erreur.message + suffixe)


async def chercher_un_plat(
    recherche: str | None = None,
    commune: str | None = None,
    categorie: str | None = None,
    sous_categorie: str | None = None,
    prix_min: float | None = None,
    prix_max: float | None = None,
    devise: str | None = None,
    latitude: float | None = None,
    longitude: float | None = None,
    rayon_km: float | None = None,
    tri: Literal["prix", "distance"] | None = None,
    par_page: int | None = None,
) -> str:
    try:
        reponse = await appeler(
            "GET",
            "/api/products/search",
            capacite="catalogue:lire",
            params={
                "q": recherche,
                "town": commune,
                "category": categorie,
                "sub_category": sous_categorie,
                "price_min": prix_min,
                "price_max": prix_max,
                "currency": devise,
                "lat": latitude,
                "lng": longitude,
                "radius": rayon_km,
                "sort": tri,
                "per_page": par_page,
            },
        )
    except ErreurThalia as e:
        raise _refuser(e) from None

    return formatage.resultats_de_recherche(reponse)


async def calculer_le_prix_du_panier(
    commune: str,
    produits: list[LigneDePanier],
    restaurant: str | None = None,
) -> str:
    try:
        reponse = await appeler(
            "POST",
            "/api/quote",
            capacite="devis:calculer",
            corps={
                "town": commune,
                "restaurant": restaurant,
                "products": [p.vers_api() for p in produits],
            },
        )
    except ErreurThalia as e:
        raise _refuser(e) from None

    return formatage.devis(reponse)


async def que_puis_je_manger_avec_ce_budget(
    budget: float,
    devise: str,
    commune: str,
    recherche: str | None = None,
    categorie: str | None = None,
    sous_categorie: str | None = None,
    latitude: float | None = None,
    longitude: float | None = None,
    rayon_km: float | None = None,
) -> str:
    try:
        reponse = await appeler(
            "POST",
            "/api/budget-suggestions",
            capacite="devis:calculer",
            corps={
                "budget": budget,
                "currency": devise,
                "town": commune,
                "q": recherche,
                "category": categorie,
                "sub_category": sous_categorie,
                "lat": latitude,
                "lng": longitude,
                "radius": rayon_km,
            },
        )
    except ErreurThalia as e:
        raise _refuser(e) from None

    return formatage.suggestions_de_budget(reponse)


async def creer_une_precommande(
    commune: str,
    produits: list[LigneDePanier],
) -> str:
    try:
        reponse = await appeler(
            "POST",
            "/api/precommandes",
            capacite="precommande:creer",
            corps={
                "town": commune,
                "products": [p.vers_api() for p in produits],
            },
        )
    except ErreurThalia as e:
        raise _refuser(e) from None

    donnees = reponse.get("data") or {}
    message = reponse.get("message")

    texte = formatage.precommande(donnees)

    return f"{texte}\n\n{message}" if message else texte


async def lister_mes_precommandes() -> str:
    try:
        reponse = await appeler(
            "GET",
            "/api/precommandes",
            capacite="precommande:lire",
        )
    except ErreurThalia as e:
        raise _refuser(e) from None

    return formatage.liste_de_precommandes(reponse.get("data") or [])


async def voir_une_precommande(uid: str) -> str:
    try:
        reponse = await appeler(
            "GET",
            f"/api/precommandes/{uid}",
            capacite="precommande:lire",
        )
    except ErreurThalia as e:
        raise _refuser(e) from None

    return formatage.precommande(reponse.get("data") or {})


DESCRIPTIONS = {
    "chercher_un_plat": (
        "Cherche des plats dans le catalogue Thalia Eats (restaurants de RDC).\n\n"
        "À utiliser dès que le client décrit ce qu'il veut manger (« du poulet moambe », "
        "« une pizza »), veut savoir ce qu'un restaurant propose, ou a besoin de "
        "l'identifiant (uid) d'un plat pour la suite.\n\n"
        "Rend pour chaque plat : son titre, le prix du PLAT SEUL dans sa devise "
        "(CDF ou USD), le restaurant, et l'identifiant (uid) à réutiliser tel quel "
        "dans les autres outils.\n\n"
        "Ce que cet outil ne fait PAS : il ne donne ni les frais de livraison, ni les "
        "frais de service, ni le prix final. Pour le prix réellement facturé, appelez "
        "« calculer_le_prix_du_panier ». Il ne commande rien.\n\n"
        "« commune » est l'identifiant court de la commune de livraison (par exemple "
        "« gombe »), pas une adresse."
    ),
    "calculer_le_prix_du_panier": (
        "Calcule le prix exact d'un panier livré : plat + livraison + service.\n\n"
        "À utiliser avant toute pré-commande, et chaque fois que le client demande "
        "« ça fait combien ? ». C'est la SEULE source du prix : ne l'additionnez jamais "
        "vous-même à partir des prix affichés par la recherche, la livraison et le "
        "service dépendent de la commune et du montant du panier.\n\n"
        "« commune » est l'identifiant court de la commune de LIVRAISON (pas celle du "
        "restaurant). Tous les plats doivent venir d'un même restaurant et d'une même "
        "devise, sinon l'outil le dit.\n\n"
        "Ce que cet outil ne fait PAS : il ne réserve rien, ne fige aucun prix et ne "
        "crée aucune commande. Le prix n'est figé qu'à la création d'une pré-commande."
    ),
    "que_puis_je_manger_avec_ce_budget": (
        "Propose ce que le client peut commander pour un budget donné, livraison et "
        "frais de service COMPRIS.\n\n"
        "À utiliser quand le client parle d'une somme : « j'ai 500 francs », « qu'est-ce "
        "que je peux avoir pour 20 dollars ? ».\n\n"
        "« budget » est un nombre, « devise » son identifiant (par exemple « cdf » ou "
        "« usd »), « commune » l'identifiant court de la commune de livraison.\n\n"
        "Quand le budget ne suffit à rien, l'outil le dit explicitement et indique "
        "l'option la moins chère et combien il manque : ne présentez jamais cela comme "
        "« aucun résultat ».\n\n"
        "Ce que cet outil ne fait PAS : il ne commande rien et ne fige aucun prix."
    ),
    "creer_une_precommande": (
        "Crée une pré-commande au prix figé et rend un LIEN DE PAIEMENT à usage unique "
        "qui expire.\n\n"
        "À utiliser une fois que le client a confirmé son panier et la commune de "
        "livraison. Montrez-lui le lien de paiement : c'est l'étape suivante, et la "
        "commande n'existe vraiment qu'une fois le paiement fait.\n\n"
        "NE DEMANDEZ JAMAIS l'adresse exacte, le nom du destinataire ni le numéro de "
        "téléphone. Cet outil ne les accepte pas : le client les saisit lui-même sur la "
        "page de paiement. Une adresse dictée à une machine est une adresse mal "
        "recopiée, et c'est le livreur qui en paie le prix. Seule la commune est "
        "collectée ici.\n\n"
        "Ce que cet outil ne fait PAS : il ne déclenche aucun paiement, ne crée aucune "
        "commande définitive, et rien ne peut être supprimé ni annulé depuis ce "
        "connecteur."
    ),
    "lister_mes_precommandes": (
        "Liste les pré-commandes récentes du client, avec leur statut (en attente de "
        "paiement, payée, expirée) et leur total.\n\n"
        "À utiliser quand le client demande « où en est ma commande ? », « qu'est-ce "
        "que j'avais commandé ? », ou avant d'en recréer une.\n\n"
        "Ce que cet outil ne fait PAS : il ne rend pas les liens de paiement. Pour "
        "obtenir celui d'une pré-commande, appelez « voir_une_precommande » avec son "
        "identifiant."
    ),
    "voir_une_precommande": (
        "Affiche le détail d'une pré-commande et, si elle est encore payable, son LIEN "
        "DE PAIEMENT.\n\n"
        "À utiliser quand le client veut retrouver un lien de paiement, vérifier ce "
        "qu'il avait commandé, ou savoir si sa pré-commande a expiré.\n\n"
        "« uid » est l'identifiant rendu par « creer_une_precommande » ou "
        "« lister_mes_precommandes ».\n\n"
        "Ce que cet outil ne fait PAS : il ne paie pas, n'annule pas et ne supprime "
        "rien."
    ),
}

# Aucune de ces opérations n'écrit sur une commande existante ni n'efface quoi
# que ce soit : `destructive_hint` est donc faux partout. Seule la création de
# pré-commande modifie l'état côté Thalia.
ANNOTATIONS = {
    "chercher_un_plat": {"read_only_hint": True, "idempotent_hint": True, "open_world_hint": True},
    "calculer_le_prix_du_panier": {"read_only_hint": True, "idempotent_hint": True, "open_world_hint": True},
    "que_puis_je_manger_avec_ce_budget": {"read_only_hint": True, "idempotent_hint": True, "open_world_hint": True},
    "creer_une_precommande": {"read_only_hint": False, "destructive_hint": False, "idempotent_hint": False, "open_world_hint": True},
    "lister_mes_precommandes": {"read_only_hint": True, "idempotent_hint": True, "open_world_hint": True},
    "voir_une_precommande": {"read_only_hint": True, "idempotent_hint": True, "open_world_hint": True},
}

TITRES = {
    "chercher_un_plat": "Chercher un plat",
    "calculer_le_prix_du_panier": "Calculer le prix d'un panier",
    "que_puis_je_manger_avec_ce_budget": "Que puis-je manger avec ce budget",
    "creer_une_precommande": "Créer une pré-commande",
    "lister_mes_precommandes": "Lister mes pré-commandes",
    "voir_une_precommande": "Voir une pré-commande",
}

OUTILS = [
    chercher_un_plat,
    calculer_le_prix_du_panier,
    que_puis_je_manger_avec_ce_budget,
    creer_une_precommande,
    lister_mes_precommandes,
    voir_une_precommande,
]


def enregistrer(serveur: MCPServer) -> MCPServer:
    from mcp.types import ToolAnnotations

    for fonction in OUTILS:
        nom = fonction.__name__
        serveur.tool(
            name=nom,
            title=TITRES[nom],
            description=DESCRIPTIONS[nom],
            annotations=ToolAnnotations(title=TITRES[nom], **ANNOTATIONS[nom]),
        )(fonction)

    return serveur
