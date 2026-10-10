<?php

namespace Tests\Feature\Rental;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Le verrou tient-il vraiment entre deux sessions MySQL ?
 *
 * Les autres tests du module se deroulent dans une seule connexion : ils
 * prouvent la decision — le second candidat voit la retenue du premier et est
 * refuse — mais pas que deux requetes HTTP reellement simultanees ne
 * traversent pas la section critique ensemble. Cette serialisation-la n'est
 * pas du code PHP, c'est `SELECT ... FOR UPDATE` sur la ligne du vehicule, et
 * elle ne se verifie qu'avec deux sessions.
 *
 * On en ouvre donc deux, hors de Laravel, comme deux conteneurs php-fpm :
 *
 *   - meme vehicule : la seconde session attend et tombe sur le delai
 *     d'attente de verrou. C'est exactement ce qui empeche les deux de lire
 *     « creneau libre » en meme temps ;
 *   - vehicule different : elle passe sans attendre. Le verrou porte sur une
 *     ligne, pas sur le parc.
 *
 * Les lignes d'essai sont validees — sinon la seconde session n'aurait rien a
 * verrouiller — puis effacees dans le `finally` : la transaction de
 * `RefreshDatabase` ne les couvre pas et ne les emporterait pas.
 */
class VerrouVehiculeConcurrentTest extends TestCase
{
    use RefreshDatabase;

    public function test_deux_sessions_ne_traversent_pas_la_section_critique_ensemble(): void
    {
        $tenant = $this->sessionMysql();
        $concurrent = $this->sessionMysql();

        // La session concurrente n'attend pas une minute avant de se plaindre.
        $concurrent->exec('SET SESSION innodb_lock_wait_timeout = 1');

        [$devise, $premier, $second] = $this->lignesDEssai($tenant);

        try {
            // Le premier client entre dans la section critique et y reste.
            $tenant->beginTransaction();
            $this->verrouiller($tenant, $premier);

            $concurrent->beginTransaction();

            try {
                $this->verrouiller($concurrent, $premier);
                $this->fail('Les deux sessions ont verrouille le meme vehicule : rien ne serialise les deux paiements.');
            } catch (PDOException $e) {
                $this->assertStringContainsString('lock wait timeout', mb_strtolower($e->getMessage()));
            }

            $concurrent->rollBack();

            // Un autre vehicule : aucune attente. Le verrou ne serialise pas
            // le parc entier.
            $concurrent->beginTransaction();
            $this->assertCount(1, $this->verrouiller($concurrent, $second));
            $concurrent->rollBack();
        } finally {
            $tenant->rollBack();
            $tenant->exec('delete from vehicles where id in ('.$premier.', '.$second.')');
            $tenant->exec('delete from currencies where id = '.$devise);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function verrouiller(PDO $session, int $vehicule): array
    {
        $requete = $session->prepare('select * from vehicles where id = ? for update');
        $requete->execute([$vehicule]);

        return $requete->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Une session MySQL a part entiere, hors du cycle de vie de Laravel. */
    private function sessionMysql(): PDO
    {
        $reglages = config('database.connections.'.config('database.default'));

        return new PDO(
            "mysql:host={$reglages['host']};port={$reglages['port']};dbname={$reglages['database']}",
            $reglages['username'],
            $reglages['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /** @return array{0: int, 1: int, 2: int} devise, puis deux vehicules. */
    private function lignesDEssai(PDO $session): array
    {
        $session->exec("insert into currencies (title, code, slug, is_active, created_at, updated_at)
            values ('Dollars', 'VUS', 'verrou-dollars', 1, now(), now())");
        $devise = (int) $session->lastInsertId();

        $vehicules = [];

        foreach (['VER-0001', 'VER-0002'] as $plaque) {
            $session->exec("insert into vehicles
                (brand, model, plate_number, reference, slug, currency_id, hourly_rate, is_active, created_at, updated_at)
                values ('Toyota', 'Land Cruiser', '{$plaque}', '{$plaque}', '".mb_strtolower($plaque)."', {$devise}, 10, 1, now(), now())");
            $vehicules[] = (int) $session->lastInsertId();
        }

        return [$devise, $vehicules[0], $vehicules[1]];
    }
}
