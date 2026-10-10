"""Point d'entrée : `python -m thalia_mcp`."""

from __future__ import annotations

import logging

import uvicorn

from .app import construire_app
from .config import charger


def main() -> None:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s %(message)s",
    )

    config = charger()

    uvicorn.run(
        construire_app(config),
        host=config.hote,
        port=config.port,
        # Les journaux d'accès répètent l'URL, jamais les en-têtes : aucun jeton
        # n'y transite.
        access_log=True,
        # Pas d'en-tête « Server: uvicorn ». Il ne protège de rien a lui seul —
        # cacher le nom d'un serveur n'empeche aucune attaque — mais il nomme
        # gratuitement la pile a qui cherche ou frapper, et le supprimer a la
        # source vaut mieux que de le maquiller dans Apache : ici il n'est
        # jamais emis, meme si quelqu'un joint le conteneur sans passer par le
        # proxy.
        server_header=False,
        # Meme raison pour la date : elle n'apprend rien d'utile a un client
        # MCP et Apache pose deja la sienne.
        date_header=True,
    )


if __name__ == "__main__":
    main()
