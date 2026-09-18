"""Le socle des tests : une API Laravel simulée, jamais la production."""

from __future__ import annotations

import pytest

from thalia_mcp import client, jeton
from thalia_mcp.app import construire_serveur
from thalia_mcp.config import charger

API = "http://api.thalia.test"
JETON = "9|jeton-de-test-qui-ne-doit-jamais-etre-journalise"


@pytest.fixture(autouse=True)
def environnement(monkeypatch):
    monkeypatch.setenv("THALIA_API_URL", API)
    monkeypatch.setenv("THALIA_MCP_URL_PUBLIQUE", "https://mcp.thaliaeats.test")
    monkeypatch.setenv("THALIA_MCP_DELAI_HTTP", "2")
    yield
    # Le client HTTP est partagé : on le libère pour que le test suivant
    # reparte d'une configuration propre.
    client._client = None


@pytest.fixture
def config():
    return charger()


@pytest.fixture
def serveur(config):
    return construire_serveur(config)


@pytest.fixture
def porteur():
    """Le jeton de la requête en cours, comme le poserait le middleware ASGI."""
    with jeton.porte_par(JETON):
        yield JETON


def texte(resultat) -> str:
    """Le texte rendu par un outil, tel que le modèle le lira."""
    return "\n".join(
        bloc.text for bloc in resultat.content if getattr(bloc, "type", None) == "text"
    )
