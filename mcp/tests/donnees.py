"""Des réponses calquées sur celles de l'API Laravel (ApiResponse + Resources)."""

DEVISE = {"code": "CDF", "slug": "cdf"}

PRODUIT = {
    "uid": "eyJpdiI6Ing",
    "title": "Poulet moambe",
    "description": "Poulet mijoté à l'huile de palme",
    "price": 3000,
    "currency": DEVISE,
    "promotionnalPrice": None,
    "slug": "poulet-moambe",
    "picture": "https://app.thaliaeats.com/images/poulet.jpg",
    "is_promotional": False,
    "is_in_forward": False,
    "restaurant": {
        "name": "Chez Tante Lina",
        "slug": "chez-tante-lina",
        "adresse": "12 av. de la Justice",
        "commune": {"slug": "gombe", "title": "Gombe"},
    },
}

RECHERCHE = {
    "data": [{"product": PRODUIT, "distance_km": 2.4}],
    "meta": {"current_page": 1, "last_page": 1, "per_page": 15, "total": 1},
}

DEVIS = {
    "disponible": True,
    "sous_total": 3000,
    "frais_livraison": 2000,
    "service_price": 500,
    "total": 5500,
    "currency": DEVISE,
    "raison": None,
}

DEVIS_REFUSE = {
    "disponible": False,
    "sous_total": 0,
    "frais_livraison": 0,
    "service_price": 0,
    "total": 0,
    "currency": None,
    "raison": "multi_restaurant",
}

BUDGET_OK = {
    "suggestions": [
        {
            "restaurant": {"name": "Chez Tante Lina", "slug": "chez-tante-lina"},
            "produit": {
                "uid": "eyJpdiI6Ing",
                "title": "Poulet moambe",
                "slug": "poulet-moambe",
                "price": 3000.0,
            },
            "distance_km": 2.4,
            "sous_total": 3000,
            "frais_livraison": 2000,
            "service_price": 500,
            "total": 5500,
            "reste": 4500.0,
        }
    ],
    "disponible": True,
    "raison": None,
    "option_la_moins_chere": None,
    "budget": 10000.0,
    "currency": DEVISE,
}

BUDGET_INSUFFISANT = {
    "suggestions": [],
    "disponible": False,
    "raison": "budget_insuffisant",
    "option_la_moins_chere": {
        "restaurant": {"name": "Chez Tante Lina", "slug": "chez-tante-lina"},
        "produit": {
            "uid": "eyJpdiI6Ing",
            "title": "Poulet moambe",
            "slug": "poulet-moambe",
            "price": 3000.0,
        },
        "distance_km": None,
        "sous_total": 3000,
        "frais_livraison": 2000,
        "service_price": 500,
        "total": 5500,
        "manque": 5000.0,
    },
    "budget": 500.0,
    "currency": DEVISE,
}

BUDGET_SANS_RESTAURANT = {
    "suggestions": [],
    "disponible": False,
    "raison": "aucun_restaurant_dans_cette_zone",
    "option_la_moins_chere": None,
    "budget": 500.0,
    "currency": DEVISE,
}

LIEN = (
    "https://app.thaliaeats.com/precommande/eyJpdiI6Ing/paiement"
    "?expires=1789000000&signature=ab12cd34"
)

PRECOMMANDE = {
    "uid": "eyJpdiI6Ing",
    "reference": "PRE-000123",
    "statut": "en_attente",
    "sous_total": 3000,
    "frais_livraison": 2000,
    "service_price": 500,
    "total": 5500,
    "currency": DEVISE,
    "restaurant": {"name": "Chez Tante Lina", "slug": "chez-tante-lina"},
    "adresse": None,
    "destinataire": {"name": None, "phone": None},
    "produits": [
        {"uid": "eyJpdiI6Ing", "title": "Poulet moambe", "quantity": 1, "price": 3000}
    ],
    "expires_at": "2026-09-19T02:00:00.000000Z",
    "created_at": "2026-09-18T14:00:00.000000Z",
    "commande": None,
}

PRECOMMANDE_CREEE = {
    "data": {**PRECOMMANDE, "lien_paiement": LIEN},
    "title": "Pré-commande créée",
    "message": "Votre pré-commande est valable 12 heures.",
}

PRECOMMANDE_VUE = {"data": {**PRECOMMANDE, "lien_paiement": LIEN}}

PRECOMMANDES = {"data": [PRECOMMANDE]}
