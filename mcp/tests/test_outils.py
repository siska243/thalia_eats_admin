"""Le cas nominal de chacun des six outils, à travers le serveur MCP réel."""

from __future__ import annotations

import respx
from httpx import Response

from tests import donnees
from tests.conftest import API, texte


async def test_les_six_outils_sont_enumeres(serveur):
    outils = await serveur.list_tools()

    assert [o.name for o in outils] == [
        "chercher_un_plat",
        "calculer_le_prix_du_panier",
        "que_puis_je_manger_avec_ce_budget",
        "creer_une_precommande",
        "lister_mes_precommandes",
        "voir_une_precommande",
    ]

    # Aucun outil ne supprime ni n'annule : c'est une règle du propriétaire.
    for outil in outils:
        assert outil.description
        assert not (outil.annotations and outil.annotations.destructive_hint)


@respx.mock
async def test_chercher_un_plat(serveur, porteur):
    route = respx.get(f"{API}/api/products/search").mock(
        return_value=Response(200, json=donnees.RECHERCHE)
    )

    resultat = await serveur.call_tool(
        "chercher_un_plat", {"recherche": "moambe", "commune": "gombe"}
    )

    assert route.called
    assert dict(route.calls[0].request.url.params) == {"q": "moambe", "town": "gombe"}

    rendu = texte(resultat)
    assert "Poulet moambe" in rendu
    assert "3 000 CDF" in rendu
    assert "Chez Tante Lina" in rendu
    assert donnees.PRODUIT["uid"] in rendu
    # Le prix du plat seul ne doit jamais passer pour le prix facturé.
    assert "calculer_le_prix_du_panier" in rendu


@respx.mock
async def test_calculer_le_prix_du_panier(serveur, porteur):
    route = respx.post(f"{API}/api/quote").mock(
        return_value=Response(200, json=donnees.DEVIS)
    )

    resultat = await serveur.call_tool(
        "calculer_le_prix_du_panier",
        {"commune": "gombe", "produits": [{"uid": "eyJpdiI6Ing", "quantite": 2}]},
    )

    # Le corps envoyé est bien celui qu'attend QuoteRequest.
    import json

    envoye = json.loads(route.calls[0].request.content)
    assert envoye["town"] == "gombe"
    assert envoye["products"] == [{"uid": "eyJpdiI6Ing", "quantity": 2}]

    assert "5 500 CDF (plat 3 000 + livraison 2 000 + service 500)" in texte(resultat)


@respx.mock
async def test_un_panier_refuse_est_explique(serveur, porteur):
    respx.post(f"{API}/api/quote").mock(
        return_value=Response(200, json=donnees.DEVIS_REFUSE)
    )

    resultat = await serveur.call_tool(
        "calculer_le_prix_du_panier",
        {"commune": "gombe", "produits": [{"uid": "a", "quantite": 1}]},
    )

    assert "un seul restaurant" in texte(resultat)


@respx.mock
async def test_budget_avec_des_options(serveur, porteur):
    respx.post(f"{API}/api/budget-suggestions").mock(
        return_value=Response(200, json=donnees.BUDGET_OK)
    )

    resultat = await serveur.call_tool(
        "que_puis_je_manger_avec_ce_budget",
        {"budget": 10000, "devise": "cdf", "commune": "gombe"},
    )

    rendu = texte(resultat)
    assert "10 000 CDF" in rendu
    assert "Poulet moambe" in rendu
    assert "5 500 CDF (plat 3 000 + livraison 2 000 + service 500)" in rendu
    assert "il vous resterait 4 500 CDF" in rendu


@respx.mock
async def test_creer_une_precommande_met_le_lien_en_evidence(serveur, porteur):
    route = respx.post(f"{API}/api/precommandes").mock(
        return_value=Response(201, json=donnees.PRECOMMANDE_CREEE)
    )

    resultat = await serveur.call_tool(
        "creer_une_precommande",
        {"commune": "gombe", "produits": [{"uid": "eyJpdiI6Ing", "quantite": 1}]},
    )

    import json

    envoye = json.loads(route.calls[0].request.content)
    # L'assistant ne collecte que la commune : aucune adresse, aucun
    # destinataire, aucun téléphone ne doit partir d'ici.
    assert set(envoye) == {"town", "products"}

    rendu = texte(resultat)
    assert "LIEN DE PAIEMENT" in rendu
    assert donnees.LIEN in rendu
    assert "PRE-000123" in rendu
    assert "Votre pré-commande est valable 12 heures." in rendu


@respx.mock
async def test_lister_mes_precommandes(serveur, porteur):
    respx.get(f"{API}/api/precommandes").mock(
        return_value=Response(200, json=donnees.PRECOMMANDES)
    )

    rendu = texte(await serveur.call_tool("lister_mes_precommandes", {}))

    assert "PRE-000123" in rendu
    assert "en attente de paiement" in rendu
    assert "voir_une_precommande" in rendu


@respx.mock
async def test_lister_sans_aucune_precommande(serveur, porteur):
    respx.get(f"{API}/api/precommandes").mock(
        return_value=Response(200, json={"data": []})
    )

    assert "aucune pré-commande" in texte(
        await serveur.call_tool("lister_mes_precommandes", {})
    )


@respx.mock
async def test_voir_une_precommande_rend_son_lien(serveur, porteur):
    respx.get(f"{API}/api/precommandes/eyJpdiI6Ing").mock(
        return_value=Response(200, json=donnees.PRECOMMANDE_VUE)
    )

    rendu = texte(await serveur.call_tool("voir_une_precommande", {"uid": "eyJpdiI6Ing"}))

    assert donnees.LIEN in rendu
    assert "1 × Poulet moambe" in rendu
