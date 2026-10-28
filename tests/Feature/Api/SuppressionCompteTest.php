<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Restaurant;
use App\Models\Status;
use App\Models\Town;
use App\Models\User;
use App\Models\UserAdresse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La suppression de compte, exigee par Google Play.
 *
 * Le proprietaire a tranche : on anonymise, on garde les donnees, on pose un
 * marqueur de suppression, et le titulaire ne peut plus entrer. Une commande
 * en cours retient le compte — « il doit soit annuler sa commande soit
 * attendre que ca se termine ».
 *
 * Le 201 attendu plutot qu'un 200 n'est pas une coquille : toutes les
 * reponses de succes passent par `ApiResponse::SUCCESS_DATA`, qui renvoie
 * 201. L'enveloppe `{title, message, data}` est lue telle quelle par les
 * toasts du mobile et du site ; en fabriquer une seconde pour cette seule
 * route ferait diverger le contrat pour la beaute d'un code HTTP.
 *
 * Ces tests tiennent surtout les deux pieges :
 *   - une commande perimee porte encore son ancien `status_id` ; elle ne doit
 *     retenir personne, sinon un panier oublie en 2024 interdirait a vie de
 *     partir ;
 *   - le compte doit etre ferme aux DEUX portes, la connexion et l'API, et
 *     non seulement masque dans les ecrans.
 */
class SuppressionCompteTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Town $town;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            [Commande::STATUT_EN_ATTENTE, 'En attente', 'en-attente'],
            [Commande::STATUT_EN_COURS, 'En cours', 'en-cours'],
            [Commande::STATUT_LIVREE, 'Livrer', 'livrer'],
            [Commande::STATUT_ANNULEE, 'Annuler', 'annuler'],
            [Commande::STATUT_ATTENTE_PAIEMENT, 'En attente paiement', 'en-attente-paiement'],
        ] as [$id, $titre, $slug]) {
            Status::query()->firstOrCreate(['id' => $id], ['title' => $titre, 'slug' => $slug]);
        }

        $this->town = Town::factory()->create();
        $this->client = User::factory()->create(['password' => 'motdepasse']);
    }

    private function commande(int $statut): Commande
    {
        return Commande::query()->create([
            'user_id' => $this->client->id,
            'status_id' => $statut,
            'town_id' => $this->town->id,
            'refernce' => 'CMD-'.uniqid(),
            'global_price' => 10,
            'adresse_delivery' => 'Avenue du Commerce 12',
        ]);
    }

    public function test_un_compte_sans_engagement_est_anonymise_et_ferme(): void
    {
        $this->client->forceFill([
            'phone' => '+243810000000',
            'principal_adresse' => 'Avenue Kasa-Vubu 4',
            'expo_push_token' => 'ExponentPushToken[abc]',
        ])->save();

        UserAdresse::query()->create([
            'user_id' => $this->client->id,
            'adresse' => 'Avenue Kasa-Vubu 4',
            'slug' => 'adresse-'.uniqid(),
        ]);

        $this->client->createToken('mobile');

        Sanctum::actingAs($this->client, ['*']);

        $this->deleteJson('/api/user/account', ['password' => 'motdepasse'])
            ->assertCreated();

        $this->client->refresh();

        $this->assertNotNull($this->client->account_deleted_at);
        $this->assertNotNull($this->client->blocked_at, 'le compte doit aussi etre bloque');
        $this->assertTrue($this->client->estBloque());

        $this->assertSame('Compte supprimé', $this->client->name);
        $this->assertSame('supprime+'.$this->client->id.'@thaliaeats.invalid', $this->client->email);
        $this->assertNull($this->client->phone);
        $this->assertNull($this->client->principal_adresse);
        $this->assertNull($this->client->expo_push_token);

        $this->assertSame(0, UserAdresse::query()->where('user_id', $this->client->id)->count());
        $this->assertSame(0, $this->client->tokens()->count(), 'les jetons Sanctum doivent avoir disparu');

        /*
         * La porte de l'API, et pas seulement celle de la connexion : le
         * garde RefuserCompteBloque repond 403 a un compte supprime, meme
         * presente comme authentifie. Les jetons ont de toute facon disparu,
         * c'est l'assertion juste au-dessus ; celle-ci tient le cas ou un
         * jeton survivrait quelque part.
         */
        $this->getJson('/api/user/account')->assertForbidden();
    }

    public function test_les_commandes_restent_rattachees_au_compte_anonymise(): void
    {
        $commande = $this->commande(Commande::STATUT_LIVREE);

        Sanctum::actingAs($this->client, ['*']);
        $this->deleteJson('/api/user/account', ['password' => 'motdepasse'])->assertCreated();

        $commande->refresh();

        $this->assertSame($this->client->id, (int) $commande->user_id);
        $this->assertDatabaseHas('users', ['id' => $this->client->id]);
    }

    public function test_une_commande_en_cours_retient_le_compte(): void
    {
        $this->commande(Commande::STATUT_EN_COURS);

        Sanctum::actingAs($this->client, ['*']);

        $this->deleteJson('/api/user/account', ['password' => 'motdepasse'])
            ->assertStatus(400);

        $this->client->refresh();

        $this->assertNull($this->client->account_deleted_at);
        $this->assertNull($this->client->blocked_at);
        $this->assertNotSame('Compte supprimé', $this->client->name);
    }

    public function test_une_commande_perimee_ne_retient_pas_le_compte(): void
    {
        $this->commande(Commande::STATUT_EN_ATTENTE)
            ->forceFill(['expires_at' => now()->subMinute()])
            ->saveQuietly();

        Sanctum::actingAs($this->client, ['*']);

        $this->deleteJson('/api/user/account', ['password' => 'motdepasse'])->assertCreated();

        $this->assertNotNull($this->client->refresh()->account_deleted_at);
    }

    public function test_une_commande_annulee_ne_retient_pas_le_compte(): void
    {
        $this->commande(Commande::STATUT_EN_COURS)
            ->forceFill(['cancel_at' => now()])
            ->saveQuietly();

        Sanctum::actingAs($this->client, ['*']);

        $this->deleteJson('/api/user/account', ['password' => 'motdepasse'])->assertCreated();

        $this->assertNotNull($this->client->refresh()->account_deleted_at);
    }

    public function test_un_mot_de_passe_errone_ne_supprime_rien(): void
    {
        Sanctum::actingAs($this->client, ['*']);

        $this->deleteJson('/api/user/account', ['password' => 'pas-le-bon'])
            ->assertStatus(400);

        $this->assertNull($this->client->refresh()->account_deleted_at);
    }

    public function test_un_compte_google_supprime_sans_mot_de_passe(): void
    {
        $this->client->forceFill(['google_id' => '1234567890'])->save();

        Sanctum::actingAs($this->client, ['*']);

        $this->deleteJson('/api/user/account')->assertCreated();

        $this->assertNotNull($this->client->refresh()->account_deleted_at);
    }

    public function test_le_restaurant_survit_et_son_gerant_s_en_detache(): void
    {
        $restaurant = Restaurant::factory()->create(['user_id' => $this->client->id]);

        Sanctum::actingAs($this->client, ['*']);
        $this->deleteJson('/api/user/account', ['password' => 'motdepasse'])->assertCreated();

        $restaurant->refresh();

        $this->assertDatabaseHas('restaurants', ['id' => $restaurant->id]);
        $this->assertNull($restaurant->user_id, 'le gerant se detache, l enseigne reste');
    }
}
