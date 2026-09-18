"""Les échecs. Le point qui compte : le modèle doit savoir s'il peut réessayer."""

from __future__ import annotations

import httpx
import pytest
import respx
from httpx import Response
from mcp.server.mcpserver.exceptions import ToolError

from tests.conftest import API


@respx.mock
async def test_un_403_devient_un_message_de_capacite_manquante(serveur, porteur):
    respx.post(f"{API}/api/precommandes").mock(
        return_value=Response(403, json={"message": "Invalid ability provided."})
    )

    with pytest.raises(ToolError) as echec:
        await serveur.call_tool(
            "creer_une_precommande",
            {"commune": "gombe", "produits": [{"uid": "a", "quantite": 1}]},
        )

    message = str(echec.value)
    assert "precommande:creer" in message
    assert "n'a pas le droit" in message
    assert "Refus définitif" in message


@respx.mock
async def test_un_401_dit_de_reconnecter_le_connecteur(serveur, porteur):
    respx.get(f"{API}/api/precommandes").mock(
        return_value=Response(401, json={"message": "Unauthenticated."})
    )

    with pytest.raises(ToolError) as echec:
        await serveur.call_tool("lister_mes_precommandes", {})

    assert "n'est plus valide" in str(echec.value)


@respx.mock
async def test_un_422_nomme_le_champ_et_la_raison(serveur, porteur):
    respx.post(f"{API}/api/quote").mock(
        return_value=Response(
            422,
            json={
                "message": "La ville de livraison est obligatoire.",
                "errors": {"town": ["La ville de livraison est obligatoire."]},
            },
        )
    )

    with pytest.raises(ToolError) as echec:
        await serveur.call_tool(
            "calculer_le_prix_du_panier",
            {"commune": "", "produits": [{"uid": "a", "quantite": 1}]},
        )

    message = str(echec.value)
    # Le message français de l'API est repris tel quel, pas réécrit.
    assert "La ville de livraison est obligatoire." in message
    assert "- town :" in message


@respx.mock
async def test_un_400_metier_remonte_le_message_de_l_api(serveur, porteur):
    respx.post(f"{API}/api/precommandes").mock(
        return_value=Response(
            400,
            json={
                "title": "Oups",
                "message": "Nous ne livrons pas encore dans cette zone.",
                "error": "aucun_tarif_livraison",
            },
        )
    )

    with pytest.raises(ToolError) as echec:
        await serveur.call_tool(
            "creer_une_precommande",
            {"commune": "kimbanseke", "produits": [{"uid": "a", "quantite": 1}]},
        )

    message = str(echec.value)
    assert "Nous ne livrons pas encore dans cette zone." in message
    assert "Refus définitif" in message


@respx.mock
async def test_un_delai_depasse_rend_une_erreur_reessayable(serveur, porteur):
    respx.get(f"{API}/api/products/search").mock(
        side_effect=httpx.ReadTimeout("trop long")
    )

    with pytest.raises(ToolError) as echec:
        await serveur.call_tool("chercher_un_plat", {"recherche": "moambe"})

    message = str(echec.value)
    assert "n'a pas répondu dans le délai imparti (2 s)" in message
    assert "Incident technique" in message


@respx.mock
async def test_un_500_est_reessayable(serveur, porteur):
    respx.get(f"{API}/api/products/search").mock(return_value=Response(500, text="boom"))

    with pytest.raises(ToolError) as echec:
        await serveur.call_tool("chercher_un_plat", {"recherche": "moambe"})

    assert "Incident technique" in str(echec.value)


@respx.mock
async def test_un_429_demande_de_patienter(serveur, porteur):
    respx.get(f"{API}/api/products/search").mock(
        return_value=Response(429, json={"message": "Too Many Attempts."}, headers={"Retry-After": "30"})
    )

    with pytest.raises(ToolError) as echec:
        await serveur.call_tool("chercher_un_plat", {"recherche": "moambe"})

    message = str(echec.value)
    assert "Patientez 30 secondes" in message
    assert "Incident technique" in message


async def test_sans_jeton_l_outil_dit_de_connecter_thalia(serveur):
    with pytest.raises(ToolError) as echec:
        await serveur.call_tool("lister_mes_precommandes", {})

    assert "Aucune connexion Thalia" in str(echec.value)
