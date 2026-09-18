"""Configuration, entierement par variables d'environnement.

Aucune URL n'est ecrite en dur : le meme conteneur sert la recette et la
production. Les valeurs par defaut visent la production parce que c'est le cas
qui doit marcher sans qu'on y pense.
"""

from __future__ import annotations

import os
from dataclasses import dataclass

API_PAR_DEFAUT = "https://app.thaliaeats.com"

# L'URL publique du connecteur lui-meme. Elle sert a deux choses, toutes deux
# imposees par la RFC 9728 : l'identifiant `resource` du document de metadonnees,
# et le `resource_metadata=` du defi 401. Sans elle, un client ne peut pas
# decouvrir aupres de qui s'authentifier.
URL_PUBLIQUE_PAR_DEFAUT = "http://127.0.0.1:8097"

CHEMIN_MCP = "/mcp"


@dataclass(frozen=True)
class Config:
    url_api: str
    url_publique: str
    delai_http: float
    hote: str
    port: int
    hotes_autorises: tuple[str, ...]

    @property
    def identifiant_ressource(self) -> str:
        """L'identifiant de cette ressource protegee : l'URL de l'endpoint MCP."""
        return self.url_publique + CHEMIN_MCP

    @property
    def url_metadonnees(self) -> str:
        return f"{self.url_publique}/.well-known/oauth-protected-resource{CHEMIN_MCP}"

    def url_endpoint(self, chemin: str) -> str:
        return self.url_api + chemin


def _nettoyer(valeur: str) -> str:
    return valeur.rstrip("/")


def charger(env: dict[str, str] | None = None) -> Config:
    source = os.environ if env is None else env

    hotes = source.get("THALIA_MCP_HOTES_AUTORISES", "").strip()

    return Config(
        url_api=_nettoyer(source.get("THALIA_API_URL", API_PAR_DEFAUT)),
        url_publique=_nettoyer(source.get("THALIA_MCP_URL_PUBLIQUE", URL_PUBLIQUE_PAR_DEFAUT)),
        delai_http=float(source.get("THALIA_MCP_DELAI_HTTP", "15")),
        hote=source.get("THALIA_MCP_HOTE", "0.0.0.0"),
        port=int(source.get("THALIA_MCP_PORT", "8097")),
        hotes_autorises=tuple(h.strip() for h in hotes.split(",") if h.strip()),
    )
