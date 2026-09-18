"""La couche HTTP : découverte de l'autorisation, défi 401, santé."""

from __future__ import annotations

import httpx
import pytest

from thalia_mcp.app import construire_app
from thalia_mcp.config import charger


@pytest.fixture
def appelant():
    app = construire_app(charger())

    return httpx.AsyncClient(
        transport=httpx.ASGITransport(app=app), base_url="http://connecteur.test"
    )


async def test_sans_jeton_le_connecteur_repond_401_avec_le_defi(appelant):
    async with appelant as client:
        reponse = await client.post(
            "/mcp",
            json={"jsonrpc": "2.0", "id": 1, "method": "tools/list"},
            headers={"Accept": "application/json, text/event-stream"},
        )

    assert reponse.status_code == 401

    defi = reponse.headers["WWW-Authenticate"]
    assert defi.startswith("Bearer ")
    assert (
        'resource_metadata="https://mcp.thaliaeats.test/.well-known/'
        'oauth-protected-resource/mcp"' in defi
    )


async def test_un_en_tete_authorization_mal_forme_vaut_absence_de_jeton(appelant):
    async with appelant as client:
        reponse = await client.post(
            "/mcp",
            json={"jsonrpc": "2.0", "id": 1, "method": "tools/list"},
            headers={"Authorization": "Basic YWJjOmRlZg=="},
        )

    assert reponse.status_code == 401


@pytest.mark.parametrize(
    "chemin",
    [
        "/.well-known/oauth-protected-resource",
        "/.well-known/oauth-protected-resource/mcp",
    ],
)
async def test_les_metadonnees_designent_thalia_comme_serveur_d_autorisation(
    appelant, chemin
):
    async with appelant as client:
        reponse = await client.get(chemin)

    assert reponse.status_code == 200

    document = reponse.json()
    assert document["resource"] == "https://mcp.thaliaeats.test/mcp"
    assert document["authorization_servers"] == ["http://api.thalia.test"]
    assert document["bearer_methods_supported"] == ["header"]
    assert "precommande:creer" in document["scopes_supported"]


async def test_les_metadonnees_restent_accessibles_sans_jeton(appelant):
    """Sinon la découverte est impossible : le client n'a pas encore de jeton."""
    async with appelant as client:
        reponse = await client.get("/.well-known/oauth-protected-resource")

    assert reponse.status_code == 200


async def test_healthz(appelant):
    async with appelant as client:
        reponse = await client.get("/healthz")

    assert reponse.status_code == 200
    assert reponse.text == "ok"
