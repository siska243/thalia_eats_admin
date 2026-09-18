<?php

namespace Tests\Feature;

use App\Helpers\ImageUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * L'URL d'une image doit suivre le schema de la requete.
 *
 * Les Resources ecrivaient `'https://' . $host` en dur : en local, ou le
 * serveur de developpement parle HTTP, le navigateur demandait une URL HTTPS,
 * la connexion etait fermee et aucune photo n'apparaissait. En production, ou
 * tout est en HTTPS, le defaut etait invisible.
 */
class UrlImageTest extends TestCase
{
    public function test_elle_suit_le_schema_http_en_local(): void
    {
        $requete = Request::create('http://127.0.0.1:8000/api/default/preview');

        $this->assertSame(
            'http://127.0.0.1:8000/images/plat.jpg',
            ImageUrl::make($requete, 'plat.jpg')
        );
    }

    public function test_elle_reste_en_https_en_production(): void
    {
        $requete = Request::create('https://app.thaliaeats.com/api/default/preview');

        // Meme resultat qu'avant la correction : aucune URL ne change pour les
        // clients mobiles deja installes.
        $this->assertSame(
            'https://app.thaliaeats.com/images/plat.jpg',
            ImageUrl::make($requete, 'plat.jpg')
        );
    }

    public function test_elle_reste_en_https_derriere_le_proxy_de_production(): void
    {
        /*
         * En production, nginx tourne derriere le TLS d'Apache : la requete
         * arrive en clair sur le conteneur. TrustProxies a `$proxies` a null,
         * donc Laravel n'accorde aucune confiance a X-Forwarded-Proto — et
         * construirait tout en http://.
         *
         * C'est deploy/nginx-forwarded.conf qui repond a ca : il traduit
         * l'en-tete d'Apache en `HTTPS=on` et `SERVER_PORT=443`, deux
         * variables serveur que Symfony lit avant toute notion de proxy. Ce
         * test verifie que ce mecanisme suffit, puisque c'est de lui que
         * depend le schema des URL d'images en production.
         */
        $requete = Request::create('http://app.thaliaeats.com/api/default/preview');
        $requete->server->set('HTTPS', 'on');
        $requete->server->set('SERVER_PORT', 443);

        $this->assertSame(
            'https://app.thaliaeats.com/images/plat.jpg',
            ImageUrl::make($requete, 'plat.jpg')
        );
    }
}
