<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tus credenciales de acceso</title>
</head>
<body style="margin:0;padding:24px;background:#F5F3EA;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#26332B;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #E4E7DF;border-radius:12px;">
        <tr>
            <td style="padding:24px 28px;border-bottom:1px solid #E4E7DF;">
                <strong style="font-size:18px;color:#244B36;">Sistema de gestión avícola</strong>
            </td>
        </tr>
        <tr>
            <td style="padding:28px;font-size:15px;line-height:1.55;">
                <p style="margin:0 0 16px;">Hola, {{ $usuario->name }}:</p>
                <p style="margin:0 0 16px;">Se creó una cuenta para ti. Estos son tus datos para ingresar:</p>

                <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#F5F3EA;border-radius:8px;margin:0 0 20px;">
                    <tr>
                        <td style="padding:14px 16px;font-size:13px;color:#6F9678;">Correo</td>
                        <td style="padding:14px 16px;font-weight:600;">{{ $usuario->email }}</td>
                    </tr>
                    <tr>
                        <td style="padding:0 16px 14px;font-size:13px;color:#6F9678;">Contraseña temporal</td>
                        <td style="padding:0 16px 14px;font-weight:600;font-family:ui-monospace,Consolas,monospace;letter-spacing:.5px;">{{ $passwordTemporal }}</td>
                    </tr>
                </table>

                <p style="margin:0 0 20px;">
                    <a href="{{ $urlSistema }}" style="display:inline-block;padding:10px 20px;background:#244B36;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">Ingresar al sistema</a>
                </p>

                <p style="margin:0 0 8px;">Al ingresar por primera vez podrás <strong>cambiar tu contraseña</strong> o quedarte con esta temporal, como prefieras.</p>
                <p style="margin:0;font-size:13px;color:#6F9678;">Si no esperabas este mensaje, ignóralo o avisa al administrador.</p>
            </td>
        </tr>
    </table>
</body>
</html>
