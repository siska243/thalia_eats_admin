<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DemandePartenariat;
use App\Models\Prospect;
use App\Rules\TelephoneValide;
use App\Settings\ProspectionSettings;
use App\Wrappers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Les demandes de partenariat venues du site.
 *
 * Publique, comme le catalogue : exiger un compte pour dire « je voudrais
 * travailler avec vous » perdrait le restaurateur avant qu'il ait une raison
 * de s'inscrire. C'est donc la seule ecriture anonyme de cette API, et elle
 * est limitee en debit pour cette raison.
 *
 * Rien n'est cree pour le demandeur : ni compte, ni role, ni acces. La
 * demande arrive dans l'administration et la societe rappelle.
 */
class ProspectController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', Prospect::TYPES)],
            // Le nom du demandeur. Le formulaire restaurant l'appelle
            // « responsable », le formulaire livreur « votre nom » : c'est la
            // meme personne, celle qu'on rappellera.
            'responsable' => ['required', 'string', 'max:120'],
            // C'est le numero qu'on rappellera : une demande de partenariat
            // avec un numero faux est une demande perdue.
            'telephone' => ['required', 'string', 'max:30', new TelephoneValide],
            'email' => ['nullable', 'email', 'max:160'],
            'commune' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:2000'],

            // Propres au restaurant.
            'etablissement' => ['nullable', 'string', 'max:160'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'cuisine' => ['nullable', 'string', 'max:80'],

            // Propres au livreur.
            'vehicule' => ['nullable', 'string', 'max:80'],
            'permis' => ['nullable', 'string', 'max:40'],
            'disponibilite' => ['nullable', 'string', 'max:80'],
        ]);

        $prospect = Prospect::query()->create([
            'type' => $donnees['type'],
            'nom' => $donnees['responsable'],
            'telephone' => $donnees['telephone'],
            'email' => $donnees['email'] ?? null,
            'commune' => $donnees['commune'] ?? null,
            'message' => $donnees['message'] ?? null,
            'details' => array_filter([
                'etablissement' => $donnees['etablissement'] ?? null,
                'adresse' => $donnees['adresse'] ?? null,
                'cuisine' => $donnees['cuisine'] ?? null,
                'vehicule' => $donnees['vehicule'] ?? null,
                'permis' => $donnees['permis'] ?? null,
                'disponibilite' => $donnees['disponibilite'] ?? null,
            ]),
        ]);

        $this->prevenir($prospect);

        return ApiResponse::SUCCESS_DATA(
            ['uid' => $prospect->id],
            __('Demande envoyée'),
            __('Merci, notre équipe vous rappelle.'),
        );
    }

    /**
     * L'e-mail a l'equipe, qui ne doit jamais faire echouer la demande.
     *
     * Une passerelle d'envoi indisponible ferait perdre le prospect alors que
     * sa demande est deja enregistree : l'echec est journalise, le visiteur
     * voit sa confirmation. C'est le back-office qui fait foi, l'e-mail n'est
     * qu'une alerte.
     */
    private function prevenir(Prospect $prospect): void
    {
        $destinataire = trim((string) ProspectionSettings::destinataire());

        if ($destinataire === '') {
            return;
        }

        try {
            Mail::to($destinataire)->send(new DemandePartenariat($prospect));
        } catch (\Throwable $e) {
            Log::warning('Demande de partenariat : e-mail non envoyé', [
                'prospect_id' => $prospect->id,
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
