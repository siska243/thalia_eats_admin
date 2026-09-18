"""Deux familles d'echec, et la distinction compte.

Un modele qui ne sait pas separer « Thalia n'a pas repondu » de « cette
commande ne peut pas etre creee » reessaie une erreur metier en boucle. Le
message porte donc explicitement s'il est utile de reessayer.
"""

from __future__ import annotations


class ErreurThalia(Exception):
    """Echec d'un appel a l'API Thalia."""

    reessayable = False

    def __init__(self, message: str) -> None:
        super().__init__(message)
        self.message = message


class ErreurMetier(ErreurThalia):
    """Refus definitif : reessayer a l'identique redonnera le meme refus."""

    reessayable = False


class ErreurTechnique(ErreurThalia):
    """Panne passagere : le meme appel a des chances d'aboutir plus tard."""

    reessayable = True
