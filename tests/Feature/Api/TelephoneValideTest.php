<?php

namespace Tests\Feature\Api;

use App\Rules\TelephoneValide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Un numero de telephone est verifie, pas seulement non vide.
 *
 * Le proprietaire, le 20 octobre : « les gens ecrivent n'importe quoi et tu
 * verifies meme pas si le numero est correct ». Les FormRequest n'avaient
 * aucune regle de format — au mieux `string|max:30`, souvent rien.
 *
 * Deux choses sont verrouillees ici, et la seconde compte autant que la
 * premiere : un numero faux est refuse, et un champ FACULTATIF laisse vide
 * continue de passer. Confondre les deux ferait d'une absence une erreur de
 * format, et arreterait des clients qui marchent aujourd'hui.
 */
class TelephoneValideTest extends TestCase
{
    use RefreshDatabase;

    private function juge(mixed $valeur): bool
    {
        return Validator::make(
            ['telephone' => $valeur],
            ['telephone' => [new TelephoneValide]],
        )->passes();
    }

    public function test_la_regle_refuse_ce_qui_ne_se_compose_pas(): void
    {
        $this->assertFalse($this->juge('0000'));
        $this->assertFalse($this->juge('abc'));
        $this->assertFalse($this->juge('12'));
        $this->assertFalse($this->juge('+243'));
        $this->assertFalse($this->juge('081000'));
    }

    public function test_la_regle_accepte_un_numero_congolais(): void
    {
        // Les trois formes que les ecrans produisent : national, international
        // avec le plus, et international sans le plus.
        $this->assertTrue($this->juge('0810000000'));
        $this->assertTrue($this->juge('+243810000000'));
        $this->assertTrue($this->juge('243810000000'));
    }

    /** Un numero etranger reste acceptable, ecrit au format international. */
    public function test_la_regle_accepte_un_numero_etranger_international(): void
    {
        $this->assertTrue($this->juge('+33612345678'));
    }

    /**
     * La regle juge le FORMAT, jamais la presence.
     *
     * `nullable` ou `required` le disent a cote. Sans cette separation, un
     * champ facultatif vide produirait « ce numero n'est pas valide », un
     * message qui ne veut rien dire.
     */
    public function test_la_regle_laisse_passer_un_champ_vide(): void
    {
        $this->assertTrue($this->juge(null));
        $this->assertTrue($this->juge(''));
    }

    // --- Opposee a l'inscription --------------------------------------------

    /** @param array<string, mixed> $extra */
    private function inscription(array $extra = []): array
    {
        return array_merge([
            'name' => 'Eva',
            'last_name' => 'Mbala',
            'email' => 'eva@example.com',
            'password' => 'motdepasse',
            'password_confirmation' => 'motdepasse',
        ], $extra);
    }

    public function test_l_inscription_refuse_un_numero_faux(): void
    {
        Mail::fake();
        Role::findOrCreate('client', 'web');

        $this->postJson('/api/register', $this->inscription(['phone' => '0000']))
            ->assertStatus(400);

        $this->assertDatabaseMissing('users', ['email' => 'eva@example.com']);
    }

    public function test_l_inscription_accepte_un_numero_valide(): void
    {
        Mail::fake();
        Role::findOrCreate('client', 'web');

        $this->postJson('/api/register', $this->inscription(['phone' => '+243810000000']))
            ->assertSuccessful();
    }

    /**
     * Sans numero, l'inscription passe toujours.
     *
     * Non-regression du parc installe : certains clients n'envoient pas ce
     * champ, et l'exiger les arreterait tous d'un coup.
     */
    public function test_l_inscription_sans_numero_passe_comme_avant(): void
    {
        Mail::fake();
        Role::findOrCreate('client', 'web');

        $this->postJson('/api/register', $this->inscription())->assertSuccessful();
    }

    // --- Opposee au formulaire partenaire ------------------------------------

    public function test_une_demande_de_partenariat_refuse_un_numero_faux(): void
    {
        Mail::fake();

        $this->postJson('/api/prospects', [
            'type' => 'livreur',
            'responsable' => 'Eva Mbala',
            'telephone' => 'je-rappelle-jamais',
        ])->assertStatus(422);
    }
}
