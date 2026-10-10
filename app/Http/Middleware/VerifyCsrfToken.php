<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
        'api/*',

        // Appelées par un logiciel, pas par un navigateur : il n'y a aucune
        // session d'où tirer un jeton CSRF, et rien à protéger contre une
        // requête inter-site puisque ces deux routes ne s'appuient sur aucun
        // cookie. La page d'autorisation, elle, reste protégée par CSRF.
        'oauth/register',
        'oauth/token',
    ];
}
