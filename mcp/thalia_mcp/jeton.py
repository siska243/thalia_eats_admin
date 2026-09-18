"""Le jeton du client, porte par la requete en cours et rien de plus.

Le connecteur ne stocke aucun jeton : il le lit dans l'en-tete Authorization,
le depose dans un contextvar le temps de la requete ASGI, et le relaie tel quel
a Laravel. Un contextvar plutot qu'une globale parce que plusieurs sessions se
chevauchent dans la meme boucle d'evenements.

Ce jeton ne doit apparaitre dans AUCUN journal : ni en clair, ni tronque, ni
hache. Il n'y a donc dans ce module ni __repr__, ni fonction d'affichage.
"""

from __future__ import annotations

from contextlib import contextmanager
from contextvars import ContextVar
from typing import Iterator

from .erreurs import ErreurMetier

_jeton: ContextVar[str | None] = ContextVar("thalia_jeton", default=None)


@contextmanager
def porte_par(jeton: str | None) -> Iterator[None]:
    marque = _jeton.set(jeton)
    try:
        yield
    finally:
        _jeton.reset(marque)


def actuel() -> str | None:
    return _jeton.get()


def exige() -> str:
    """Le jeton de la requete, ou un refus lisible s'il n'y en a pas."""
    jeton = _jeton.get()

    if not jeton:
        raise ErreurMetier(
            "Aucune connexion Thalia n'accompagne cette demande. "
            "Connectez Thalia Eats dans votre client (le jeton s'obtient depuis "
            "votre compte Thalia), puis réessayez."
        )

    return jeton


def depuis_en_tete(valeur: str | None) -> str | None:
    """Extrait le jeton d'un en-tete Authorization, sans jamais le journaliser."""
    if not valeur:
        return None

    parties = valeur.split(None, 1)

    if len(parties) != 2 or parties[0].lower() != "bearer":
        return None

    jeton = parties[1].strip()

    return jeton or None
