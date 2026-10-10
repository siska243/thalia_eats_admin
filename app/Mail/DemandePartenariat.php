<?php

namespace App\Mail;

use App\Models\Prospect;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * L'alerte envoyee a l'equipe quand une demande de partenariat arrive.
 *
 * Elle ne remplace pas le back-office, qui fait foi : c'est une alerte pour
 * que personne n'attende deux jours un rappel. Le sujet porte donc le metier
 * et le nom, de quoi decider de l'ouvrir sans l'ouvrir.
 */
class DemandePartenariat extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Prospect $prospect) {}

    public function envelope(): Envelope
    {
        $metier = $this->prospect->type === Prospect::TYPE_RESTAURANT ? 'Restaurant' : 'Livreur';

        return new Envelope(
            subject: "Demande de partenariat {$metier} : {$this->prospect->nom}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.demande-partenariat');
    }
}
