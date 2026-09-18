"""Un budget qui n'achète rien doit être dit, pas rendu par une liste vide."""

from __future__ import annotations

import respx
from httpx import Response

from tests import donnees
from tests.conftest import API, texte


@respx.mock
async def test_un_budget_insuffisant_le_dit_et_chiffre_le_manque(serveur, porteur):
    respx.post(f"{API}/api/budget-suggestions").mock(
        return_value=Response(200, json=donnees.BUDGET_INSUFFISANT)
    )

    rendu = texte(
        await serveur.call_tool(
            "que_puis_je_manger_avec_ce_budget",
            {"budget": 500, "devise": "cdf", "commune": "gombe"},
        )
    )

    assert "vous ne pouvez rien commander" in rendu
    assert "le plat, la livraison et le service" in rendu
    assert "Poulet moambe" in rendu
    assert "5 500 CDF (plat 3 000 + livraison 2 000 + service 500)" in rendu
    assert "Il vous manque 5 000 CDF" in rendu
    # Surtout pas une liste vide déguisée en réponse.
    assert "[]" not in rendu


@respx.mock
async def test_une_zone_sans_restaurant_est_nommee(serveur, porteur):
    respx.post(f"{API}/api/budget-suggestions").mock(
        return_value=Response(200, json=donnees.BUDGET_SANS_RESTAURANT)
    )

    rendu = texte(
        await serveur.call_tool(
            "que_puis_je_manger_avec_ce_budget",
            {"budget": 500, "devise": "cdf", "commune": "maluku"},
        )
    )

    assert "aucun restaurant ne livre dans cette zone" in rendu
