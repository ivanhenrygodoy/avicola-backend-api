<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo con las credenciales iniciales. No implementa ShouldQueue a propósito: la cola
 * es del job que lo envía, y así la contraseña en claro no se serializa en ningún payload.
 */
class CredencialesIniciales extends Mailable
{
    public function __construct(
        public User $usuario,
        public string $passwordTemporal,
        public string $urlSistema,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tus credenciales de acceso al sistema avícola');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.credenciales-iniciales');
    }
}
