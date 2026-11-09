# Rame TI

Plataforma interna para inventario de PC y laptops, usuarios, credenciales, cartas responsivas y mantenimientos preventivos.

## Requisitos

- XAMPP con PHP 8.1 o posterior, Apache y MySQL/MariaDB.
- Extensiones PHP `pdo_mysql`, `openssl` y `mbstring` habilitadas.
- Composer para habilitar el envío SMTP con PHPMailer.

## Instalación en XAMPP

1. Inicia Apache y MySQL desde el panel de XAMPP.
2. Abre phpMyAdmin, importa `database/schema.sql` y confirma que se creó la base `rame_it`.
   Si ya tienes la plataforma instalada, aplica las migraciones pendientes de `database/migrations/` a la base `rame_it`; para los campos de inventario y contraseña, aplica primero `20261015_asset_inventory_details.sql`, después `20261015_asset_windows_password.sql` y finalmente `20261015_asset_windows_password_plaintext.sql`. Para cartas escaneadas, aplica `20261021_user_responsibility_letters.sql` y después `20261021_responsibility_letters_by_asset.sql`. Aplica `20261022_asset_shared_users.sql` para compartir equipos con hasta cinco cuentas, `20261022_inventory_import_and_license_assignments.sql` para antivirus/licencias y `20261022_asset_assignees_and_responsibility_letters.sql` para responsables compartidos sin cuenta y su carta individual.
3. Copia `app/config.local.example.php` como `app/config.local.php`. Este archivo está excluido de Git.
4. Genera una clave maestra desde PowerShell:

   ```powershell
   & 'C:\xampp\php\php.exe' -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
   ```

   Pega el resultado en `encryption_key` dentro de `app/config.local.php`. No pierdas esta clave: las contraseñas y secretos cifrados no se podrán recuperar sin ella. Guarda una copia protegida por separado de la base de datos.
5. Ajusta host, usuario y contraseña MySQL en el mismo archivo si tu instalación no usa `root` sin contraseña.
6. Desde la carpeta del proyecto instala PHPMailer ejecutando `composer install`.
7. Completa los valores SMTP (`host`, `port`, `username`, `password`, `from_email`, `encryption`) en `app/config.local.php`. Sin SMTP configurado, el sistema agenda el mantenimiento, avisa que el correo no se envió y permite copiar un enlace seguro para compartirlo manualmente.
8. Abre `http://localhost/Rame/` y crea la primera cuenta administradora. Usa una contraseña de al menos 8 caracteres.
9. Desde **Usuarios**, agrega cuentas de técnicos y usuarios finales. Registra los activos antes de asignarlos; las cartas escaneadas se gestionan desde las acciones de cada computadora o celular.
10. Para importar `acomodo.xlsx`, ejecuta `database\import_acomodo.ps1` en PowerShell para simular y revisar los conteos. Ejecuta `database\import_acomodo.ps1 -Apply` para aplicar la carga. El importador copia las contraseñas de Windows a la ficha de cada activo; se guardan sin cifrar en la columna `windows_password`. Las claves ESET se cifran y las asignaciones sin equipo encontrado quedan pendientes.

## Funciones incluidas

- Panel de operación con activos y próximos mantenimientos.
- Gráficas del inventario por estado y tipo, licencias por vigencia y tendencia mensual de mantenimientos.
- Inventario de PC y laptops con hasta cinco responsables por equipo. Cada responsable tiene su propia carta; necesita una cuenta con correo solo si debe iniciar sesión en la plataforma.
- Las licencias por vencer se calculan usando su fecha de vencimiento. Las asignaciones importadas no se consideran capacidad contratada hasta que se confirme en el módulo de licencias.
- Ficha de equipo con usuario y contraseña de Windows/RDP, asignación, departamento, puesto, garantía, terminal, monitor, mouse y host RDP. La contraseña de equipo se guarda sin cifrar en la base de datos y solo administración puede revelarla; restringe el acceso a MySQL y protege los respaldos. Las credenciales de la bóveda y las claves de licencia siguen cifradas con AES-256-GCM.
- Estado del celular: **Activo**, **Formateado** o **Por formatear**, separado de su estado general de inventario.
- Inventario separado entre equipos de cómputo y celulares administrativos/operacionales, con historial cronológico de fallas, piezas y reparaciones por equipo.
- Bóveda de credenciales cifradas con AES-256-GCM; solo administración puede revelarlas.
- Agenda de mantenimiento con enlace individual para confirmar o proponer otro horario.
<<<<<<< HEAD
- Máximo de dos mantenimientos no cancelados por equipo en cada año calendario; también se valida al aprobar cambios de fecha.
=======
- Meta anual de dos mantenimientos preventivos completados por equipo. El avance se calcula por año calendario desde el historial real; pendientes, programados y cancelados no cuentan.
- Agenda visual mensual con vistas semanal y diaria. Las reservas usan intervalos de 30 minutos entre 8:30 a. m. y 5:00 p. m., con unicidad en MySQL para evitar dobles reservas simultáneas.
- El personal autorizado puede reprogramar o cancelar servicios. La reprogramación registra la fecha anterior y la nueva en el historial del activo y libera el horario anterior.
>>>>>>> f9532b313fc86773e4bfdaa276e83f4d291c493e
- Filtro de mantenimientos por rango de fechas programadas.
- Cierre del mantenimiento desde su acción con la Orden de Servicio F-SIS-01 firmada por el usuario y quien realizó el trabajo; documento y cierre se guardan juntos.
- Constancia de mantenimiento imprimible/guardable como PDF desde el navegador.
- Formatos digitales F-SIS-01 Orden de Servicio y F-SIS-02 Solicitud de Mantenimiento Correctivo, con captura estructurada e impresión/guardado como PDF.
- Los formatos de servicio registran las firmas del usuario y de quien realizó el trabajo, junto con fecha e IP; las cartas responsivas digitales anteriores siguen disponibles.
- Cartas responsivas escaneadas en PDF, JPG o PNG disponibles únicamente desde los activos de computadoras y celulares; cada carta se vincula a una persona y a su equipo compartido.
- Invitaciones tokenizadas; solo se almacena el hash del token en la base de datos.

## Roles

- **Administrador:** configura el espacio, gestiona usuarios y credenciales y opera todos los módulos.
- **Técnico:** registra equipos, licencias, documentos y mantenimientos.
- **Usuario:** consulta sus equipos y servicios, confirma horarios y firma sus documentos pendientes.

## Seguridad y alcance

Configura HTTPS antes de usarlo fuera de una red local. Usa contraseñas individuales y transmite las contraseñas temporales por un canal seguro. La clave AES no debe guardarse junto al respaldo de MySQL. Incluye `app/uploads/responsibility-letters/` en los respaldos protegidos: ahí se conservan los archivos escaneados, fuera del directorio público. `app/config.local.php` y `vendor/` no se publican en Git.

Los datos de texto ingresados se guardan en mayúsculas. Los correos, contraseñas y secretos de la bóveda conservan su escritura original.

La firma incluida es una evidencia electrónica interna capturada en pantalla y asociada al registro del sistema; no es una firma electrónica avanzada ni legalmente certificada. Para instalaciones existentes, aplica `database/migrations/20260930_digital_service_forms.sql` antes de usar los nuevos formatos. Ajusta el texto de los documentos a las políticas de la organización y consulta asesoría legal antes de usarlos como evidencia contractual.

## Comprobaciones locales

```powershell
& 'C:\xampp\php\php.exe' -l public\api.php
node --check public\assets\app.js
node --check public\assets\respond.js
```
