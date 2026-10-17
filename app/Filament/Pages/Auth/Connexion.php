<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * L'ecran de connexion de l'administration.
 *
 * Celui de Filament est une carte blanche posee sur un fond gris, titree
 * « Sign in » : rien n'y disait de quelle maison il s'agit, et il etait en
 * anglais alors que tout le reste du panneau est en francais.
 *
 * Trois choses changent, et rien de plus. Le titre nomme la maison. Le
 * sous-titre dit a qui l'ecran s'adresse — il arrive qu'un restaurateur ou un
 * livreur tombe ici en cherchant son application, et repartir tout de suite
 * vaut mieux que d'essayer trois mots de passe. Et le pied renvoie au site,
 * parce qu'une impasse sans porte de sortie est un defaut d'ecran.
 *
 * La page reste celle de Filament : son formulaire, sa limitation de debit,
 * son enchainement vers le second facteur. Les redefinir aurait voulu dire
 * les maintenir.
 */
class Connexion extends Login
{
    public function getHeading(): string|Htmlable|null
    {
        // Le second facteur garde l'intitule de Filament : c'est un autre
        // moment, et « Connexion » y serait trompeur.
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        return 'Administration Thalia';
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getSubheading();
        }

        return new HtmlString(
            'Réservé à l’équipe. Restaurateurs et livreurs passent par '
            .'l’application Thalia, pas par cet écran.'
        );
    }
}
