<?php

namespace App\Services\Geocodage;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Retrouver une position a partir d'une adresse, par OpenStreetMap.
 *
 * Demande du proprietaire le 17 octobre : « on peut utiliser OSM pour
 * recuperer la position a partir de l'adresse ».
 *
 * OpenStreetMap et non Google : aucune cle a prevoir, aucun secret a
 * commiter — c'est deja le choix fait pour la carte de suivi des courses.
 *
 * LA POLITIQUE D'USAGE DE NOMINATIM EST CONTRAIGNANTE et ce service la
 * respecte : un en-tete `User-Agent` qui identifie l'application et donne un
 * contact, une requete a la fois, et un resultat mis en cache. Le service est
 * gratuit et tenu par des benevoles ; le marteler ferait bannir notre adresse,
 * et c'est l'administration entiere qui perdrait la fonction.
 *
 * Cherche DEPUIS L'ADMINISTRATION seulement, sur demande explicite d'un
 * humain qui clique : jamais en boucle, jamais depuis une page publique.
 */
class Nominatim
{
    /**
     * La position d'une adresse, ou null.
     *
     * `$complement` precise la recherche — la commune, la ville. « Avenue du
     * Commerce » existe dans beaucoup de villes ; sans contexte, Nominatim
     * repond la plus peuplee, qui n'est pas la bonne.
     *
     * ON ESSAIE DE LA PLUS PRECISE A LA PLUS LARGE. Une adresse telle qu'elle
     * est saisie ne trouve souvent rien : « 9257 Av. Batetela ( galerie la
     * fontaine ) Gombe Kinshasa » ne rend RIEN, quand « Avenue Batetela
     * Gombe Kinshasa » rend la bonne rue. Le numero de rue et les precisions
     * entre parentheses sont du detail que la carte ne connait pas.
     *
     * On s'arrete au premier palier qui repond, et on ne descend jamais plus
     * bas que la rue : rendre le centre de la commune serait pire que rien,
     * puisque c'est deja ce que le systeme fait sans coordonnees — et le
     * faire croire a une position precise.
     *
     * @return array{lat: float, lng: float, libelle: string}|null
     */
    public function chercher(string $adresse, ?string $complement = null): ?array
    {
        $contexte = trim($complement ?? '');

        foreach ($this->paliers($adresse) as $rue) {
            $trouve = $this->interroger(trim($rue.' '.$contexte));

            if ($trouve !== null) {
                return $trouve;
            }
        }

        return null;
    }

    /**
     * L'adresse, du plus precis au plus large.
     *
     * Deux paliers seulement : telle qu'elle est ecrite, puis nettoyee. Un
     * troisieme qui ne garderait que la commune renverrait son centre, ce
     * que le systeme sait deja faire sans nous.
     *
     * @return array<int, string>
     */
    private function paliers(string $adresse): array
    {
        $brute = trim($adresse);
        $nette = $this->nettoyer($brute);

        return $nette !== '' && $nette !== $brute ? [$brute, $nette] : [$brute];
    }

    /**
     * Enleve ce que la carte ne sait pas lire.
     *
     * Les precisions entre parentheses (« galerie la fontaine »), le numero
     * de rue en tete, et les abreviations qu'OpenStreetMap ecrit en toutes
     * lettres. C'est ce qui fait la difference entre aucun resultat et la
     * bonne rue.
     */
    private function nettoyer(string $adresse): string
    {
        // Les parentheses et leur contenu : un nom de galerie, un repere.
        $propre = (string) preg_replace('/\([^)]*\)/u', ' ', $adresse);

        // Le numero en tete : OpenStreetMap ne porte pas les numeros ici.
        $propre = (string) preg_replace('/^\s*\d+\s*,?\s*/u', '', $propre);

        /*
         * Les abreviations, lues dans la configuration et non ecrites ici :
         * en completer la liste ne doit pas demander une livraison.
         *
         * Le point final est consomme AVEC l'abreviation. Sans cela « Av. »
         * donnait « Avenue. », que la carte ne reconnait pas — c'est ce qui
         * faisait echouer l'adresse relevee le 17 octobre.
         */
        foreach ((array) config('geocodage.abreviations', []) as $court => $long) {
            $propre = (string) preg_replace(
                '/\b'.preg_quote((string) $court, '/').'\.?(?=\s|$)/iu',
                (string) $long,
                $propre,
            );
        }

        return trim((string) preg_replace('/\s+/u', ' ', $propre));
    }

    /**
     * Une interrogation, une seule.
     *
     * @return array{lat: float, lng: float, libelle: string}|null
     */
    private function interroger(string $requete): ?array
    {
        if ($requete === '') {
            return null;
        }

        try {
            $reponse = Http::withHeaders([
                /*
                 * Nominatim REFUSE les requetes sans identification, et
                 * demande un moyen de nous joindre en cas d'abus. Une
                 * adresse de contact vaut mieux qu'un bannissement muet.
                 */
                'User-Agent' => sprintf(
                    '%s/1.0 (administration; %s)',
                    config('app.name', 'Thalia'),
                    config('geocodage.contact'),
                ),
                'Accept-Language' => (string) config('geocodage.langue', 'fr'),
            ])
                ->timeout((int) config('geocodage.delai', 8))
                ->get((string) config('geocodage.url'), array_filter([
                    'q' => $requete,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    /*
                     * Le pays vient de la configuration : par defaut la RDC,
                     * pour qu'une adresse de Kinshasa ne ramene pas une rue
                     * homonyme a Paris. Vide, on cherche partout — le jour ou
                     * le service ouvre ailleurs.
                     */
                    'countrycodes' => (string) config('geocodage.pays', ''),
                ], fn ($valeur): bool => $valeur !== '' && $valeur !== null));

            /*
             * UN REFUS N'EST PAS UNE ADRESSE INTROUVABLE.
             *
             * Nominatim rend 403 quand le contact declare ne lui plait pas,
             * et 429 quand on l'a trop sollicite. Les presenter comme
             * « adresse introuvable » enverrait l'administrateur reecrire
             * une adresse qui etait juste.
             */
            if (! $reponse->successful()) {
                throw new GeocodageIndisponible(
                    "OpenStreetMap a refusé la requête (code {$reponse->status()})."
                );
            }

            $premier = $reponse->json(0);

            if (! is_array($premier) || ! isset($premier['lat'], $premier['lon'])) {
                return null;
            }

            return [
                'lat' => (float) $premier['lat'],
                'lng' => (float) $premier['lon'],
                'libelle' => (string) ($premier['display_name'] ?? $requete),
            ];
        } catch (GeocodageIndisponible $e) {
            throw $e;
        } catch (\Throwable $e) {
            /*
             * Un service tiers qui ne repond pas ne doit pas faire tomber le
             * formulaire : l'administrateur saisit alors les coordonnees a
             * la main, comme avant.
             */
            Log::warning('Geocodage Nominatim indisponible', ['erreur' => $e->getMessage()]);

            return null;
        }
    }
}
