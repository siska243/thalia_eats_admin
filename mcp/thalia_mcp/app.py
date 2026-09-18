"""L'application ASGI : transport MCP « streamable HTTP », plus le strict
nécessaire pour qu'un client découvre où s'authentifier.

Trois responsabilités, et pas une de plus :

1. Relever le jeton de l'en-tête `Authorization` et le déposer dans le contexte
   de la requête, d'où le client HTTP le relaiera à Laravel. Rien n'est stocké.
2. Répondre 401 avec un défi `WWW-Authenticate` quand aucun jeton n'accompagne
   un appel MCP, et servir le document de métadonnées de ressource protégée
   (RFC 9728) qui désigne Thalia comme serveur d'autorisation.
3. Servir un `/healthz` pour le healthcheck du conteneur.

Ce qui n'est délibérément PAS ici : le serveur d'autorisation OAuth lui-même.
Il se construira côté Laravel ; une demi-implémentation ici devrait être
arrachée.
"""

from __future__ import annotations

from mcp.server.mcpserver import MCPServer
from mcp.server.transport_security import TransportSecuritySettings
from starlette.requests import Request
from starlette.responses import JSONResponse, PlainTextResponse
from starlette.types import ASGIApp, Receive, Scope, Send

from . import jeton as porteur
from . import outils
from .config import CHEMIN_MCP, Config, charger

INSTRUCTIONS = (
    "Thalia Eats, commande et livraison de repas en RDC.\n\n"
    "Parcours normal : chercher un plat, puis calculer le prix du panier livré, "
    "puis créer une pré-commande et transmettre au client le lien de paiement.\n\n"
    "Ne demandez jamais l'adresse exacte, le nom du destinataire ni le numéro de "
    "téléphone : le client les saisit lui-même sur la page de paiement. Seule la "
    "commune de livraison est collectée ici.\n\n"
    "N'additionnez jamais des montants vous-même : seul « calculer_le_prix_du_panier » "
    "donne le prix facturé.\n\n"
    "Rien ne peut être supprimé ni annulé depuis ce connecteur."
)


def metadonnees_de_ressource(config: Config) -> dict[str, object]:
    """Document RFC 9728 : à qui un client doit demander un jeton pour ici."""
    return {
        "resource": config.identifiant_ressource,
        "authorization_servers": [config.url_api],
        "bearer_methods_supported": ["header"],
        "scopes_supported": [
            "catalogue:lire",
            "devis:calculer",
            "precommande:creer",
            "precommande:lire",
            "commande:lire",
        ],
        "resource_name": "Thalia Eats",
        "resource_documentation": f"{config.url_api}/api/assistants",
    }


class PorteurDeJeton:
    """Middleware ASGI : jeton en contexte, ou défi 401.

    Il ne valide rien : c'est Laravel qui dit si le jeton vaut quelque chose, et
    avec quelles capacités. Il exige seulement qu'il y en ait un, parce que sans
    jeton il n'y a rien à relayer et qu'un client MCP a besoin du défi pour
    savoir où en obtenir un.
    """

    def __init__(self, app: ASGIApp, config: Config) -> None:
        self.app = app
        self.config = config

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        en_tetes = {
            cle.decode("latin-1").lower(): valeur.decode("latin-1")
            for cle, valeur in scope.get("headers", [])
        }

        jeton = porteur.depuis_en_tete(en_tetes.get("authorization"))
        chemin = scope.get("path", "")
        vise_le_mcp = chemin == CHEMIN_MCP or chemin.startswith(CHEMIN_MCP + "/")

        if jeton is None and vise_le_mcp:
            reponse = JSONResponse(
                {
                    "error": "unauthorized",
                    "error_description": (
                        "Connectez votre compte Thalia Eats : cette ressource exige un "
                        "jeton d'accès."
                    ),
                },
                status_code=401,
                headers={
                    "WWW-Authenticate": (
                        'Bearer realm="Thalia Eats", '
                        f'resource_metadata="{self.config.url_metadonnees}"'
                    )
                },
            )
            await reponse(scope, receive, send)
            return

        with porteur.porte_par(jeton):
            await self.app(scope, receive, send)


def construire_serveur(config: Config | None = None) -> MCPServer:
    config = config or charger()

    serveur = MCPServer(
        name="thalia-eats",
        title="Thalia Eats",
        instructions=INSTRUCTIONS,
        version="0.1.0",
        website_url=config.url_api,
    )

    outils.enregistrer(serveur)

    @serveur.custom_route("/.well-known/oauth-protected-resource", methods=["GET"])
    async def metadonnees(request: Request) -> JSONResponse:
        return JSONResponse(metadonnees_de_ressource(config))

    # Même document au chemin dérivé de celui de la ressource : la RFC 9728
    # insère le chemin de la ressource après le `.well-known`, et les clients
    # n'interrogent pas tous la même des deux formes.
    @serveur.custom_route(
        f"/.well-known/oauth-protected-resource{CHEMIN_MCP}", methods=["GET"]
    )
    async def metadonnees_avec_chemin(request: Request) -> JSONResponse:
        return JSONResponse(metadonnees_de_ressource(config))

    @serveur.custom_route("/healthz", methods=["GET"])
    async def sante(request: Request) -> PlainTextResponse:
        return PlainTextResponse("ok")

    return serveur


def construire_app(config: Config | None = None) -> ASGIApp:
    config = config or charger()

    serveur = construire_serveur(config)

    # Derrière Apache, l'en-tête Host est celui du domaine public : la protection
    # anti-DNS-rebinding du SDK ne s'active donc que si on lui donne la liste des
    # hôtes attendus (THALIA_MCP_HOTES_AUTORISES). Sans liste, on la désactive
    # explicitement plutôt que de laisser le connecteur rejeter tout le trafic.
    securite = TransportSecuritySettings(
        enable_dns_rebinding_protection=bool(config.hotes_autorises),
        allowed_hosts=list(config.hotes_autorises),
        allowed_origins=list(config.hotes_autorises),
    )

    app = serveur.streamable_http_app(
        streamable_http_path=CHEMIN_MCP,
        transport_security=securite,
    )

    return PorteurDeJeton(app, config)
