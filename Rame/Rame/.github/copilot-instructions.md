# Rame TI: instrucciones del proyecto

- La aplicación es PHP 8.1+ y MySQL/MariaDB, alojada bajo XAMPP; la interfaz y los mensajes visibles deben permanecer en español.
- Accede a MySQL mediante PDO y consultas preparadas. Valida rol y CSRF en cada mutación.
- No registres secretos en logs ni los devuelvas en endpoints de lista. La bóveda usa AES-256-GCM y la clave vive en `app/config.local.php`, fuera del control de versiones.
- Informa un envío de correo como exitoso solo después de que PHPMailer confirme el envío. Conserva una alternativa manual para invitaciones si SMTP falla.
- Las firmas capturadas son evidencia electrónica interna; evita describirlas como firmas electrónicas avanzadas o legalmente certificadas.