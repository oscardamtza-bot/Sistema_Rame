# Rame TI

Plataforma interna para inventario de computadoras y celulares, usuarios, licencias, credenciales, cartas responsivas y mantenimientos preventivos.

## Requisitos

- XAMPP con PHP 8.1 o posterior, Apache y MySQL/MariaDB.
- Extensiones PHP `pdo_mysql`, `openssl` y `mbstring` habilitadas.
- Composer para habilitar el envío SMTP con PHPMailer.

## Instalación en XAMPP

1. Inicia Apache y MySQL desde el panel de XAMPP.
2. Abre phpMyAdmin, importa `database/schema.sql` y confirma que se creó la base `rame_it`.
   Si ya tenías la plataforma instalada antes de septiembre de 2026, aplica en orden las migraciones `20260926_phone_format_status.sql`, `20260926_phone_status_active.sql` y `20260926_asset_category_and_history.sql` de `database/migrations/` a la base `rame_it`.
3. Copia `app/config.local.example.php` como `app/config.local.php`. Este archivo está excluido de Git.
4. Genera una clave maestra desde PowerShell:

   ```powershell
   & 'C:\xampp\php\php.exe' -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
   ```

   Pega el resultado en `encryption_key` dentro de `app/config.local.php`. No pierdas esta clave: las contraseñas y secretos cifrados no se podrán recuperar sin ella. Guarda una copia protegida por separado de la base de datos.
5. Ajusta host, usuario y contraseña MySQL en el mismo archivo si tu instalación no usa `root` sin contraseña.
6. Desde la carpeta del proyecto instala PHPMailer ejecutando `composer install`.
7. Completa los valores SMTP (`host`, `port`, `username`, `password`, `from_email`, `encryption`) en `app/config.local.php`. Sin SMTP configurado, el sistema agenda el mantenimiento, avisa que el correo no se envió y permite copiar un enlace seguro para compartirlo manualmente.
8. Abre `http://localhost/Rame/` y crea la primera cuenta administradora. Usa una contraseña de al menos 12 caracteres.
9. Desde **Usuarios**, agrega cuentas de técnicos y usuarios finales. Registra los activos antes de asignarlos, y luego crea licencias, mantenimientos y cartas responsivas.

## Funciones incluidas

- Panel de operación con activos y próximos mantenimientos.
- Gráficas del inventario por estado y tipo, licencias por vigencia y tendencia mensual de mantenimientos.
- Inventario de equipos, usuarios con roles, licencias y control de activaciones.
- Estado del celular: **Activo**, **Formateado** o **Por formatear**, separado de su estado general de inventario.
- Inventario separado entre equipos de cómputo y celulares administrativos/operacionales, con historial cronológico de fallas, piezas y reparaciones por equipo.
- Bóveda de credenciales cifradas con AES-256-GCM; solo administración puede revelarlas.
- Agenda de mantenimiento con enlace individual para confirmar o proponer otro horario.
- Cierre del servicio con firma capturada, nombre, fecha e IP, y constancia imprimible/guardable como PDF desde el navegador.
- Formatos digitales F-SIS-01 Orden de Servicio y F-SIS-02 Solicitud de Mantenimiento Correctivo, con captura estructurada e impresión/guardado como PDF.
- Los formatos de servicio registran las firmas del usuario y de quien realizó el trabajo, junto con fecha e IP; las cartas responsivas anteriores siguen disponibles.
- Invitaciones tokenizadas; solo se almacena el hash del token en la base de datos.

## Roles

- **Administrador:** configura el espacio, gestiona usuarios y credenciales y opera todos los módulos.
- **Técnico:** registra equipos, licencias, documentos y mantenimientos.
- **Usuario:** consulta sus equipos y servicios, confirma horarios y firma sus documentos pendientes.

## Seguridad y alcance

Configura HTTPS antes de usarlo fuera de una red local. Usa contraseñas individuales y transmite las contraseñas temporales por un canal seguro. La clave AES no debe guardarse junto al respaldo de MySQL. `app/config.local.php` y `vendor/` no se publican en Git.

La firma incluida es una evidencia electrónica interna capturada en pantalla y asociada al registro del sistema; no es una firma electrónica avanzada ni legalmente certificada. Para instalaciones existentes, aplica `database/migrations/20260930_digital_service_forms.sql` antes de usar los nuevos formatos. Ajusta el texto de los documentos a las políticas de la organización y consulta asesoría legal antes de usarlos como evidencia contractual.

## Comprobaciones locales

```powershell
& 'C:\xampp\php\php.exe' -l public\api.php
node --check public\assets\app.js
node --check public\assets\respond.js
```