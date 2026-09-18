"""Le jeton : relayé tel quel, jamais stocké, jamais journalisé."""

from __future__ import annotations

import logging

import respx
from httpx import Response

from thalia_mcp import jeton
from tests import donnees
from tests.conftest import API, JETON


@respx.mock
async def test_le_jeton_est_relaye_tel_quel_a_l_api(serveur, porteur):
    route = respx.get(f"{API}/api/precommandes").mock(
        return_value=Response(200, json=donnees.PRECOMMANDES)
    )

    await serveur.call_tool("lister_mes_precommandes", {})

    assert route.calls[0].request.headers["Authorization"] == f"Bearer {JETON}"


async def test_rien_n_est_conserve_apres_la_requete():
    with jeton.porte_par(JETON):
        assert jeton.actuel() == JETON

    assert jeton.actuel() is None


@respx.mock
async def test_le_jeton_n_apparait_dans_aucun_journal(serveur, porteur, caplog):
    respx.get(f"{API}/api/products/search").mock(
        return_value=Response(200, json=donnees.RECHERCHE)
    )
    respx.post(f"{API}/api/precommandes").mock(
        return_value=Response(403, json={"message": "Invalid ability provided."})
    )

    with caplog.at_level(logging.DEBUG):
        await serveur.call_tool("chercher_un_plat", {"recherche": "moambe"})

        try:
            await serveur.call_tool(
                "creer_une_precommande",
                {"commune": "gombe", "produits": [{"uid": "a", "quantite": 1}]},
            )
        except Exception:
            pass

    journal = "\n".join(
        [enregistrement.getMessage() for enregistrement in caplog.records]
        + [str(enregistrement.args) for enregistrement in caplog.records]
    )

    assert caplog.records, "le test ne prouve rien si rien n'est journalisé"
    assert JETON not in journal
    # Ni en clair, ni tronqué : aucun fragment significatif.
    assert JETON[:12] not in journal
    assert "Bearer" not in journal
