<?php

namespace App\Jobs;

use App\Mail\CredencialesIniciales;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envía por correo las credenciales iniciales de un usuario (R21).
 *
 * El payload lleva SOLO el id del usuario: la contraseña temporal se genera aquí, dentro del
 * worker, y se guarda hasheada. Así nunca queda en `jobs.payload` ni en `failed_jobs`.
 */
class EnviarCredencialesIniciales implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $idUsuario) {}

    /** @return array<int, int> segundos entre reintentos */
    public function backoff(): array
    {
        return [30, 120];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // Dos envíos del mismo usuario nunca corren a la vez: no habría dos temporales válidas.
        return [(new WithoutOverlapping((string) $this->idUsuario))->releaseAfter(10)->expireAfter(60)];
    }

    public function handle(): void
    {
        $usuario = User::find($this->idUsuario);

        if ($usuario === null || ! $usuario->activo) {
            return;
        }

        // Con el mailer `log` o `array` el correo (con la clave en claro) acabaría en un archivo de log.
        if ($this->mailerEscribeEnLog((string) config('mail.default'))) {
            $this->marcar($usuario, 'fallido', 'El servidor de correo no está configurado.');

            return;
        }

        $temporal = Str::password(12, symbols: false);

        // Primero se guarda el hash: la clave del correo es siempre la que realmente sirve.
        $usuario->forceFill(['password' => $temporal, 'debe_cambiar_password' => true])->save();

        Mail::to($usuario->email)->send(
            new CredencialesIniciales($usuario, $temporal, (string) config('app.frontend_url'))
        );

        $this->marcar($usuario, 'enviado', null);
    }

    /**
     * Se agotaron los reintentos. El mensaje es genérico a propósito: nunca la excepción cruda.
     */
    public function failed(?Throwable $exception = null): void
    {
        $usuario = User::find($this->idUsuario);

        if ($usuario !== null) {
            $this->marcar($usuario, 'fallido', 'No se pudo enviar el correo. Usa «Reenviar credenciales».');
        }
    }

    /**
     * ¿El mailer, o alguno al que pueda caer, escribe el correo en un log o lo descarta en memoria?
     * `failover` y `roundrobin` agrupan otros mailers: basta que uno sea `log`/`array` para que la
     * clave en claro pueda acabar en `laravel.log` cuando el SMTP falle.
     *
     * @param  array<int, string>  $visitados  evita ciclos de configuración
     */
    private function mailerEscribeEnLog(string $nombre, array $visitados = []): bool
    {
        if (in_array($nombre, $visitados, true)) {
            return false;
        }
        $visitados[] = $nombre;

        $transporte = config("mail.mailers.$nombre.transport", $nombre);

        if (in_array($transporte, ['log', 'array'], true)) {
            return true;
        }

        if (in_array($transporte, ['failover', 'roundrobin'], true)) {
            foreach ((array) config("mail.mailers.$nombre.mailers", []) as $hijo) {
                if ($this->mailerEscribeEnLog((string) $hijo, $visitados)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function marcar(User $usuario, string $estado, ?string $error): void
    {
        $usuario->forceFill(['credenciales_estado' => $estado, 'credenciales_error' => $error])->save();
    }
}
