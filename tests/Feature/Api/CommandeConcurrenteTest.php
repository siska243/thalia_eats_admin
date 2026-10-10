<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * « Une commande non reglee a la fois » n'etait qu'une lecture suivie d'une
 * ecriture, sans rien entre les deux.
 *
 * Deux appels simultanes a `valide()` lisaient tous deux « aucune commande en
 * attente » et creaient chacun la leur. Le client se retrouvait avec deux
 * commandes au paiement, deux references, deux montants engages aupres de
 * FlexPay, et la regle affichee par les deux applications devenait fausse.
 * Un double-clic sur « Payer » suffit : c'est deja ainsi que la location
 * vendait deux fois le meme vehicule.
 *
 * La reponse est celle que le module de location a deja retenue, verrou de
 * ligne sur la ressource disputee. Ici la ressource disputee n'est pas un
 * vehicule mais le client lui-meme : c'est son unique commande ouverte que
 * les deux requetes se disputent.
 */
class CommandeConcurrenteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([[1, 'En attente', 'en-attente'], [5, 'En attente paiement', 'en-attente-paiement']] as [$id, $titre, $slug]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $titre, 'slug' => $slug]);
        }
    }

    private function enveloppe(User $client): Commande
    {
        $commande = new Commande;
        $commande->user_id = $client->id;
        $commande->refernce = 'CMD-'.uniqid();
        $commande->status_id = Commande::STATUT_ATTENTE_PAIEMENT;
        $commande->town_id = Town::factory()->create()->id;
        $commande->global_price = 10;
        $commande->adresse_delivery = 'Avenue du Commerce 12';

        return $commande;
    }

    public function test_la_reservation_cree_la_commande_quand_le_client_est_libre(): void
    {
        $client = User::factory()->create();

        $this->assertNull(Commande::reserverPour($client, $this->enveloppe($client)));
        $this->assertSame(1, Commande::query()->where('user_id', $client->id)->count());
    }

    public function test_la_reservation_rend_la_commande_qui_s_y_oppose_et_n_ecrit_rien(): void
    {
        $client = User::factory()->create();

        $deja = $this->enveloppe($client);
        $deja->save();

        $conflit = Commande::reserverPour($client, $this->enveloppe($client));

        $this->assertNotNull($conflit);
        $this->assertSame($deja->id, $conflit->id);
        $this->assertSame(1, Commande::query()->where('user_id', $client->id)->count());
    }

    public function test_une_commande_perimee_ne_s_oppose_pas_a_la_reservation(): void
    {
        $client = User::factory()->create();

        $perimee = $this->enveloppe($client);
        $perimee->save();
        Commande::query()->whereKey($perimee->id)->update(['expires_at' => now()->subMinute()]);

        $this->assertNull(Commande::reserverPour($client, $this->enveloppe($client)));
    }

    /**
     * Le verrou tient-il vraiment entre deux sessions MySQL ?
     *
     * Les trois tests precedents prouvent la decision, pas la serialisation :
     * ils se deroulent dans une seule connexion. Celle-ci n'est pas du code
     * PHP, c'est `SELECT ... FOR UPDATE` sur la ligne du client, et elle ne
     * se verifie qu'avec deux sessions ouvertes en meme temps.
     *
     * Decalque de VerrouVehiculeConcurrentTest, a ceci pres que la ressource
     * disputee est l'utilisateur. Les lignes d'essai sont validees, sinon la
     * seconde session n'aurait rien a verrouiller, puis effacees dans le
     * `finally` : la transaction de RefreshDatabase ne les couvre pas.
     */
    public function test_deux_sessions_ne_reservent_pas_le_meme_client_ensemble(): void
    {
        $tenant = $this->sessionMysql();
        $concurrent = $this->sessionMysql();

        // La session concurrente n'attend pas une minute avant de se plaindre.
        $concurrent->exec('SET SESSION innodb_lock_wait_timeout = 1');

        [$premier, $second] = $this->clientsDEssai($tenant);

        try {
            $tenant->beginTransaction();
            $this->verrouiller($tenant, $premier);

            $concurrent->beginTransaction();

            try {
                $this->verrouiller($concurrent, $premier);
                $this->fail('Les deux sessions ont verrouille le meme client : rien ne serialise les deux validations.');
            } catch (PDOException $e) {
                $this->assertStringContainsString('lock wait timeout', mb_strtolower($e->getMessage()));
            }

            $concurrent->rollBack();

            // Un autre client : aucune attente. Le verrou porte sur une ligne,
            // pas sur la table des utilisateurs.
            $concurrent->beginTransaction();
            $this->assertCount(1, $this->verrouiller($concurrent, $second));
            $concurrent->rollBack();
        } finally {
            $tenant->rollBack();
            $tenant->exec('delete from users where id in ('.$premier.', '.$second.')');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function verrouiller(PDO $session, int $client): array
    {
        $requete = $session->prepare('select * from users where id = ? for update');
        $requete->execute([$client]);

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

    /** @return array{0: int, 1: int} deux clients valides en base. */
    private function clientsDEssai(PDO $session): array
    {
        $clients = [];

        foreach (['verrou-un', 'verrou-deux'] as $marqueur) {
            $suffixe = uniqid();
            $session->exec("insert into users (name, slug, email, password, created_at, updated_at)
                values ('{$marqueur}', '{$marqueur}-{$suffixe}', '{$marqueur}-{$suffixe}@thalia.test', 'x', now(), now())");
            $clients[] = (int) $session->lastInsertId();
        }

        return [$clients[0], $clients[1]];
    }
}
