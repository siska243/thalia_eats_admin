<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use SensitiveParameter;

/**
 * Le code de connexion a usage unique, quand le second facteur est le
 * courriel.
 *
 * Distinct de `WelcomeOtpMail`, qui confirme une adresse a l'inscription :
 * ce sont deux moments et deux messages, et les confondre afficherait
 * « bienvenue » a quelqu'un qui se connecte pour la centieme fois.
 */
class CodeDoubleAuthentificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        #[SensitiveParameter] private readonly string $code,
        private readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre code de connexion');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.code_double_authentification',
            with: [
                'code' => $this->code,
                'minutes' => $this->minutes,
            ],
        );
    }
}
