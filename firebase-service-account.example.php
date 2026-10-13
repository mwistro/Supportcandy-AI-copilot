<?php
/**
 * Credencial de la cuenta de servicio de Firebase.
 *
 * Se guarda como archivo PHP -- no como opcion via un campo de formulario, y
 * no como .json en la carpeta del plugin -- para que nunca quede accesible
 * como texto plano via URL directa (un .php pedido directamente sin pasar
 * por WP se detiene en el exit de abajo y no imprime nada).
 *
 * Copia este archivo como firebase-service-account.php (ese nombre esta en
 * .gitignore) y rellena los valores reales de tu proyecto Firebase:
 * Configuracion del proyecto > Cuentas de servicio > Generar nueva clave
 * privada. NUNCA subas el archivo real a un repositorio.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'project_id'   => 'your-project-id',
	'client_email' => 'firebase-adminsdk-xxxxx@your-project-id.iam.gserviceaccount.com',
	'private_key'  => "-----BEGIN PRIVATE KEY-----\nYOUR_PRIVATE_KEY_HERE\n-----END PRIVATE KEY-----\n",
);
