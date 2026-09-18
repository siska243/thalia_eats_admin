"""Le seul endroit qui parle a l'API Laravel.

Il relaie, il ne decide pas : aucun calcul de prix, aucune regle de livraison,
aucune validation de panier. Le prix facture est celui que rend
`QuotationService` cote Laravel, et une seconde implementation divergerait.

Il traduit en revanche les codes HTTP en refus lisibles, et separe le
reessayable du definitif.
"""

from __future__ import annotations

import logging
from typing import Any

import httpx

from . import jeton as porteur
from .config import Config, charger
from .erreurs import ErreurMetier, ErreurTechnique

logger = logging.getLogger("thalia_mcp.client")

_client: httpx.AsyncClient | None = None


def client_http(config: Config) -> httpx.AsyncClient:
    """Un client partage, pour garder les connexions ouvertes entre deux outils."""
    global _client

    if _client is None or _client.is_closed:
        _client = httpx.AsyncClient(timeout=httpx.Timeout(config.delai_http))

    return _client


async def fermer() -> None:
    global _client

    if _client is not None and not _client.is_closed:
        await _client.aclose()

    _client = None


def _message_du_corps(reponse: httpx.Response) -> str | None:
    """Le message francais deja redige par `App\\Wrappers\\ApiResponse`."""
    try:
        corps = reponse.json()
    except ValueError:
        return None

    if not isinstance(corps, dict):
        return None

    message = corps.get("message")

    return message if isinstance(message, str) and message.strip() else None


def _detail_de_validation(reponse: httpx.Response) -> str:
    """Quel champ, et pourquoi. Laravel les redige deja en francais."""
    try:
        corps = reponse.json()
    except ValueError:
        return ""

    erreurs = corps.get("errors") if isinstance(corps, dict) else None

    if not isinstance(erreurs, dict):
        return ""

    lignes = []

    for champ, messages in erreurs.items():
        if isinstance(messages, list):
            for m in messages:
                lignes.append(f"- {champ} : {m}")
        else:
            lignes.append(f"- {champ} : {messages}")

    return "\n".join(lignes)


def _traduire(reponse: httpx.Response, capacite: str | None) -> None:
    """Transforme une reponse d'erreur en refus lisible et actionnable."""
    code = reponse.status_code
    message = _message_du_corps(reponse)

    if code == 401:
        raise ErreurMetier(
            "Votre connexion Thalia n'est plus valide (jeton expiré ou révoqué). "
            "Recréez une connexion depuis votre compte Thalia, puis reconnectez "
            "le connecteur."
        )

    if code == 403:
        precision = f" Il lui manque la capacité « {capacite} »." if capacite else ""
        raise ErreurMetier(
            "Votre connexion Thalia n'a pas le droit d'effectuer cette action."
            + precision
            + " Recréez une connexion portant cette capacité ; ce connecteur ne peut "
            "pas se l'accorder lui-même."
        )

    if code == 429:
        attente = reponse.headers.get("Retry-After")
        delai = f" Patientez {attente} secondes." if attente else " Patientez un instant."
        raise ErreurTechnique(
            "Trop de demandes envoyées à Thalia sur cette connexion." + delai
        )

    if code == 422:
        detail = _detail_de_validation(reponse)
        raise ErreurMetier(
            (message or "La demande est incomplète ou mal formée.")
            + (f"\n{detail}" if detail else "")
        )

    if code in (400, 404, 409):
        raise ErreurMetier(message or "Thalia a refusé cette demande.")

    if code >= 500:
        raise ErreurTechnique(
            f"Thalia n'a pas répondu correctement (erreur {code}). "
            "C'est un incident passager : réessayez dans un instant."
        )

    raise ErreurMetier(message or f"Thalia a répondu un code inattendu ({code}).")


async def appeler(
    methode: str,
    chemin: str,
    *,
    capacite: str | None = None,
    params: dict[str, Any] | None = None,
    corps: dict[str, Any] | None = None,
    config: Config | None = None,
) -> Any:
    """Relaie un appel a l'API Thalia avec le jeton de la requete en cours.

    `capacite` sert uniquement a rediger le message d'un 403 : le controle lui
    meme appartient a Laravel, qui le fait deja.
    """
    config = config or charger()
    url = config.url_endpoint(chemin)

    # Le jeton n'apparait jamais dans un journal : on ne journalise que la
    # methode, le chemin et le code de retour.
    logger.info("appel %s %s", methode, chemin)

    en_tetes = {
        "Authorization": f"Bearer {porteur.exige()}",
        "Accept": "application/json",
    }

    try:
        reponse = await client_http(config).request(
            methode,
            url,
            params={c: v for c, v in (params or {}).items() if v is not None},
            json=corps,
            headers=en_tetes,
        )
    except httpx.TimeoutException:
        raise ErreurTechnique(
            f"Thalia n'a pas répondu dans le délai imparti ({config.delai_http:g} s). "
            "Réessayez dans un instant."
        ) from None
    except httpx.RequestError:
        raise ErreurTechnique(
            "Thalia est injoignable pour le moment (problème réseau). "
            "Réessayez dans un instant."
        ) from None

    logger.info("reponse %s %s -> %s", methode, chemin, reponse.status_code)

    if reponse.status_code >= 400:
        _traduire(reponse, capacite)

    try:
        return reponse.json()
    except ValueError:
        raise ErreurTechnique(
            "Thalia a renvoyé une réponse illisible. Réessayez dans un instant."
        ) from None
