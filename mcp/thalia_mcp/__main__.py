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
    )


if __name__ == "__main__":
    main()
