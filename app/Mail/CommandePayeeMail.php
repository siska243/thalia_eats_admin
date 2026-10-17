<?php

namespace App\Mail;

use App\Models\Commande;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Le courriel d'une commande payee.
 *
 * UN SEUL message pour les trois destinataires, et non trois classes : le
 * contenu est le meme — qui a commande quoi, pour combien, ou. Seuls
 * l'objet et la premiere phrase changent, parce qu'on ne dit pas la meme
 * chose a celui qui a commande, a celui qui doit cuisiner et a celui qui
 * suit le commerce.
 *
 * Trois classes auraient voulu dire trois gabarits a tenir d'accord, et le
 * jour ou une ligne du recapitulatif change, deux sur trois l'oublieraient.
 */
class CommandePayeeMail extends Mailable
{
    use Queueable, SerializesModels;

    public const POUR_ADMIN = 'admin';

    public const POUR_CLIENT = 'client';

    public const POUR_RESTAURANT = 'restaurant';

    public function __construct(
        private readonly Commande $commande,
        private readonly string $pour,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->pour) {
            self::POUR_CLIENT => "Votre commande {$this->commande->refernce}",
            self::POUR_RESTAURANT => "Nouvelle commande {$this->commande->refernce}",
            default => "Commande payée {$this->commande->refernce}",
        });
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.commande_payee',
            with: [
                'commande' => $this->commande,
                'pour' => $this->pour,
                'intro' => match ($this->pour) {
                    self::POUR_CLIENT => 'Merci, votre paiement est bien reçu. Voici le récapitulatif de votre commande.',
                    self::POUR_RESTAURANT => 'Une commande vient d’être payée. Vous pouvez la préparer.',
                    default => 'Une commande vient d’être payée sur la plateforme.',
                },
            ],
        );
    }
}
