"""Ce que le connecteur raconte ne doit renseigner personne sur la machine.

Rien de ce qui suit n'est une faille en soi : nommer un sous-domaine n'ouvre
aucune porte. Mais tout ce que le connecteur dit s'affiche aussi a qui cherche
ou frapper, et une phrase serviable qui designe un panneau d'administration lui
fait gagner la moitie du travail de reconnaissance.

Le piege est particulier ici : l'assistant COMPOSE ses phrases. On ne peut donc
pas se contenter de relire les chaines du depot — il faut lui dire de se taire,
et verifier qu'on le lui a bien dit.
"""

from __future__ import annotations

import pytest

from thalia_mcp.app import INSTRUCTIONS
from thalia_mcp import outils

# Ce qu'un client n'a aucune raison de lire, et qu'un attaquant a tout interet
# a apprendre.
TERMES_INTERNES = [
    "app.thaliaeats",
    "filament",
    "back-office",
    "backoffice",
    "/admin",
    "phpmyadmin",
    "mysql",
    "127.0.0.1",
    "localhost",
    "docker",
    "nginx",
    "apache",
    "laravel",
    "uvicorn",
]


def textes_vus_par_le_modele() -> dict[str, str]:
    """Tout ce que le connecteur met sous les yeux de l'assistant."""
    textes = {"INSTRUCTIONS": INSTRUCTIONS}

    for nom, description in outils.DESCRIPTIONS.items():
        textes[f"description:{nom}"] = description

    return textes


@pytest.mark.parametrize("terme", TERMES_INTERNES)
def test_aucun_composant_interne_n_est_nomme(terme: str) -> None:
    for origine, texte in textes_vus_par_le_modele().items():
        assert terme not in texte.lower(), (
            f"« {terme} » apparait dans {origine}. Un client n'a pas a le lire, "
            "et l'assistant le repetera."
        )


def test_l_assistant_recoit_la_consigne_de_ne_pas_nommer_l_interne() -> None:
    """Le garde ci-dessus ne couvre que les chaines du depot.

    L'assistant, lui, invente ses phrases : il a produit « passez par
    app.thaliaeats.com ou le back-office admin Filament » alors qu'aucune de
    ces chaines n'existait nulle part. La seule protection est la consigne.
    """
    minuscules = INSTRUCTIONS.lower()

    assert "ne nommez jamais" in minuscules
    assert "back-office" in minuscules or "administration" in minuscules


def test_l_assistant_sait_ou_renvoyer_le_client() -> None:
    """Interdire sans proposer laisse un vide que le modele comblera seul.

    C'est exactement ainsi que la fuite est nee : on lui disait ce qu'il ne
    pouvait pas faire, sans lui dire quoi repondre a la place.
    """
    assert "l'application Thalia Eats" in INSTRUCTIONS
