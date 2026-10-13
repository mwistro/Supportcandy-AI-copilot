<?php
/**
 * Plugin Name: SupportCandy AI Copilot
 * Description: Genera borradores de respuesta con IA (OpenAI) para tickets nuevos de SupportCandy, disponibles para revisar y enviar desde el PWA de aprobacion (con notificacion push). Nunca envía nada al cliente automáticamente.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: supportcandy-ai-copilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CAI_OPTION_ENABLED', 'cai_enabled' );
define( 'CAI_OPTION_API_KEY', 'cai_api_key' );
define( 'CAI_OPTION_MODEL', 'cai_model' );

/**
 * Configuracion publica de Firebase (proyecto propio de Firebase que crees).
 * No es secreta -- es el mismo objeto que cualquier app web de Firebase
 * expone en su propio JS, y el PWA la necesita para inicializar Firebase
 * Messaging. Reemplaza estos valores por los de tu propio proyecto Firebase.
 */
define( 'CAI_FIREBASE_API_KEY', 'YOUR_FIREBASE_API_KEY' );
define( 'CAI_FIREBASE_AUTH_DOMAIN', 'your-project.firebaseapp.com' );
define( 'CAI_FIREBASE_PROJECT_ID', 'your-project-id' );
define( 'CAI_FIREBASE_STORAGE_BUCKET', 'your-project.firebasestorage.app' );
define( 'CAI_FIREBASE_SENDER_ID', 'YOUR_SENDER_ID' );
define( 'CAI_FIREBASE_APP_ID', 'YOUR_APP_ID' );
define( 'CAI_FIREBASE_VAPID_KEY', 'YOUR_VAPID_KEY' );

/**
 * URL publica del PWA -- se manda dentro de cada notificacion push como el
 * link que se abre al tocarla.
 */
define( 'CAI_PWA_URL', plugins_url( 'pwa/', __FILE__ ) );

/**
 * Lee la credencial de cuenta de servicio de Firebase (para autenticar el
 * envio de push desde el servidor). Vive en un archivo PHP separado, nunca
 * en una opcion escrita desde un formulario -- ver firebase-service-account.php.
 */
function cai_get_firebase_credentials() {
	$path = __DIR__ . '/firebase-service-account.php';
	if ( ! file_exists( $path ) ) {
		return null;
	}
	return include $path;
}

/**
 * Clave de API y modelo de OpenAI. Si existe openai-config.php (archivo PHP,
 * nunca escrito desde un formulario) se usa eso -- sirve como respaldo
 * mientras la pantalla de Ajustes no pueda guardar (ej. bloqueo 406 del
 * servidor). Si no existe, se usa lo guardado en Ajustes como siempre.
 */
function cai_get_openai_config() {
	$path = __DIR__ . '/openai-config.php';
	if ( file_exists( $path ) ) {
		$config = include $path;
		if ( is_array( $config ) && ! empty( $config['api_key'] ) ) {
			return array(
				'api_key' => $config['api_key'],
				'model'   => ! empty( $config['model'] ) ? $config['model'] : 'gpt-6-sol',
			);
		}
	}
	return array(
		'api_key' => get_option( CAI_OPTION_API_KEY ),
		'model'   => get_option( CAI_OPTION_MODEL, 'gpt-6-sol' ),
	);
}

/**
 * Tabla propia para guardar los 3 borradores de cada ticket y su estado
 * (pending / sent / dismissed). Es el origen de datos que consulta el PWA.
 */
function cai_activate() {
	global $wpdb;
	$table            = $wpdb->prefix . 'cai_drafts';
	$charset_collate  = $wpdb->get_charset_collate();
	$sql              = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		ticket_id BIGINT UNSIGNED NOT NULL,
		subject TEXT NOT NULL,
		description LONGTEXT NULL,
		category VARCHAR(191) NOT NULL,
		priority VARCHAR(191) NOT NULL,
		customer_name VARCHAR(191) NOT NULL DEFAULT '',
		customer_email VARCHAR(191) NOT NULL DEFAULT '',
		phone VARCHAR(191) NOT NULL DEFAULT '',
		company VARCHAR(191) NOT NULL DEFAULT '',
		confidence VARCHAR(20) NOT NULL,
		needs_human TINYINT(1) NOT NULL DEFAULT 0,
		draft_basico LONGTEXT NOT NULL,
		draft_intermedio LONGTEXT NOT NULL,
		draft_tecnico LONGTEXT NOT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		sent_by BIGINT UNSIGNED NULL,
		sent_text LONGTEXT NULL,
		ack_state VARCHAR(20) NOT NULL DEFAULT '',
		ack_sent_at DATETIME NULL,
		ack_sent_by BIGINT UNSIGNED NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY ticket_id (ticket_id),
		KEY status (status)
	) {$charset_collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	$tokens_table = $wpdb->prefix . 'cai_push_tokens';
	$sql_tokens   = "CREATE TABLE {$tokens_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		token VARCHAR(255) NOT NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY token (token),
		KEY user_id (user_id)
	) {$charset_collate};";
	dbDelta( $sql_tokens );
}
register_activation_hook( __FILE__, 'cai_activate' );

/**
 * Version del esquema de la tabla. Subirla fuerza que dbDelta() revise y
 * agregue columnas nuevas en instalaciones que ya tenian la tabla creada
 * (dbDelta es seguro de llamar siempre: solo aplica los cambios que faltan).
 */
define( 'CAI_DB_VERSION', '6' );

/**
 * La tabla se crea o actualiza tambien fuera de la activacion (ej. tras
 * actualizar el plugin via "Reemplazar"), que no siempre dispara
 * register_activation_hook.
 */
function cai_maybe_create_table() {
	if ( get_option( 'cai_db_version' ) !== CAI_DB_VERSION ) {
		cai_activate();
		update_option( 'cai_db_version', CAI_DB_VERSION );
	}
}
add_action( 'plugins_loaded', 'cai_maybe_create_table' );

/**
 * Etapa 1: reglas fijas por palabra clave. Costo cero, sin llamada a la API.
 * Filtrable via 'cai_keyword_templates' para editar sin tocar el codigo.
 */
function cai_get_keyword_templates() {
	$templates = array(
		array(
			'keywords' => array( 'buzon', 'correo lleno', 'mailbox full', 'casilla llena' ),
			'response' => "Hola,\n\nHemos revisado su buzón de correo y liberado espacio eliminando los elementos de la carpeta 'Eliminados' anteriores a la fecha actual. Le recomendamos vaciar periódicamente esa carpeta para evitar que vuelva a llenarse.\n\nSaludos,\nSoporte Técnico",
		),
		array(
			'keywords' => array( 'mensaje de fuera de oficina', 'respuesta automatica de fuera de oficina', 'configurar fuera de oficina', 'out of office' ),
			'response' => "Hola,\n\nPara configurar la respuesta automática de 'Fuera de oficina' en Outlook: Archivo > Respuestas automáticas > Activar > escriba el mensaje > Aceptar. Si usa el correo web, vaya a Configuración > Correo > Respuestas automáticas.\n\nSaludos,\nSoporte Técnico",
		),
	);
	return apply_filters( 'cai_keyword_templates', $templates );
}

function cai_match_keyword_template( $subject, $description ) {
	$text = mb_strtolower( $subject . ' ' . $description );
	foreach ( cai_get_keyword_templates() as $template ) {
		foreach ( $template['keywords'] as $keyword ) {
			if ( strpos( $text, mb_strtolower( $keyword ) ) !== false ) {
				return $template['response'];
			}
		}
	}
	return null;
}

/**
 * Resuelve un valor de campo de SupportCandy: si llega como ID numerico de termino
 * (categoria/prioridad), lo resuelve al nombre. Si ya llega como texto, lo usa directo.
 * Esto cubre los dos comportamientos posibles de get_ticket_fields() sin depender
 * de un unico formato.
 */
function cai_resolve_term_label( $value ) {
	if ( is_numeric( $value ) ) {
		$term = get_term( intval( $value ) );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->name;
		}
	}
	return (string) $value;
}

/**
 * Descripcion original: post_content del primer wpsc_ticket_thread publicado
 * con postmeta ticket_id = el ticket y thread_type = report. Confirmado por
 * el volcado real del ticket 599 (post 4467). historyId puede apuntar a un log.
 */
function cai_get_ticket_description( $ticket_id ) {
	global $wpdb;
	$body = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.post_content FROM {$wpdb->posts} AS p
			WHERE p.post_type = %s AND p.post_status = %s
			AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} AS link
				WHERE link.post_id = p.ID AND link.meta_key = %s AND link.meta_value = %s
			)
			AND EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} AS kind
				WHERE kind.post_id = p.ID AND kind.meta_key = %s AND kind.meta_value = %s
			)
			ORDER BY p.ID ASC LIMIT 1",
			'wpsc_ticket_thread',
			'publish',
			'ticket_id',
			(string) (int) $ticket_id,
			'thread_type',
			'report'
		)
	);
	if ( $body === null ) {
		return '';
	}
	// Quitar primero el HTML conserva texto literal como &lt;equipo&gt; al decodificar.
	return html_entity_decode( wp_strip_all_tags( (string) $body ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Lee un campo personalizado (Telefono, Empresa) directo de wpsc_ticketmeta.
 * Esta tabla es EAV (una fila por campo: ticket_id, meta_key, meta_value) --
 * $wpscfunction->get_ticket_fields() no la lee bien para campos custom, asi
 * que se consulta directo en vez de depender de esa funcion.
 */
function cai_get_custom_field_value( $ticket_id, $meta_key ) {
	global $wpdb;
	$table = $wpdb->prefix . 'wpsc_ticketmeta';
	$value = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT meta_value FROM {$table} WHERE ticket_id = %d AND meta_key = %s",
			$ticket_id,
			$meta_key
		)
	);
	return $value ? (string) $value : '';
}

function cai_call_openai_api( $subject, $description, $category, $priority, $customer_name = '' ) {
	$config  = cai_get_openai_config();
	$api_key = $config['api_key'];
	$model   = $config['model'];

	$system_prompt = "Eres un técnico de soporte de una empresa de servicios IT (España). Redactas borradores de respuesta para tickets que un agente revisa antes de enviarlos.\n\nESTILO\n- Escribe en español, tuteando al cliente, con lenguaje natural y directo.\n- Normalmente 2–4 frases por versión; si una frase basta, úsala. No añadas texto para alcanzar una longitud.\n- Evita saludos, despedidas, agradecimientos automáticos y frases corporativas. Varía la redacción según el ticket.\n- Empieza directamente con la pregunta o la indicación, como en una conversación. No uses encabezados ni dos puntos como puntuación en el texto de las respuestas, tampoco dentro de preguntas para introducir alternativas o enumeraciones. Formula preguntas directas y frases completas; si necesitas separar ideas, usa un punto o termina la pregunta y empieza otra. Conserva los dos puntos solo si forman parte literal de una hora, enlace, ruta o mensaje de error. No repitas el problema ni escribas en primera persona como si fueras el cliente.\n- Usa solo el signo de interrogación final (?). Nunca escribas el signo de apertura (¿), en ninguna de las tres versiones.\n- No afirmes haber realizado acciones, comprobaciones o reparaciones que no consten en el ticket. No solicites contraseñas ni códigos de acceso.\n\nCONTEXTO\n- Usa expresiones como “tu correo”, “los mensajes” o “el buscador”, sin introducir nombres de programas que el cliente no haya mencionado.\n- No preguntes qué programa de correo utiliza por defecto.\n- No supongas una versión, sistema operativo o tipo de cuenta. Si un procedimiento depende de un dato desconocido, pide únicamente la información imprescindible antes de indicar los pasos.\n- Distingue hechos de posibles causas y no preguntes datos que ya aparecen en el ticket.\n\nTRES ALTERNATIVAS\nCada opción debe poder enviarse por separado, con una acción o pregunta concreta. Son tres enfoques sencillos, no niveles de dificultad creciente ni pasos consecutivos. No incluyas títulos ni etiquetas dentro de las respuestas.\n- opcion_1: lenguaje cotidiano, sin tecnicismos. Si el relato es muy breve o vago y faltan detalles esenciales, combina una pregunta concreta para orientar el diagnóstico con una petición natural de que el cliente explique con más detalle lo que ocurre. No des por suficiente una pregunta cerrada en esos casos. Si solo falta un dato concreto, pide ese dato sin añadir una petición genérica. No presupongas que aparece un error. Si hay información suficiente, propone una acción sencilla sin pedir explicaciones adicionales.\n- opcion_2: propone una comprobación sencilla y relevante que el cliente pueda hacer, con nombres de opciones o carpetas cuando ayuden. Si faltan datos para hacerlo, pregunta lo necesario para elegir el siguiente paso.\n- opcion_3: ofrece otra alternativa del mismo nivel sencillo que opcion_2, o un punto intermedio entre aclarar el problema y comprobar algo básico. Puede combinar una pregunta útil con una comprobación fácil, o explorar otro aspecto relevante. No es una respuesta técnica ni debe ser más avanzada o larga que las otras.\n- Evita repetir la misma pregunta o comprobación en las tres. No inventes causas ni pasos solo para que parezcan distintas; si faltan datos, prioriza aclaraciones útiles.\n- Ninguna opción debe proponer procedimientos avanzados, reconstruir índices, modificar el registro, reinstalar programas, borrar datos ni cambiar perfiles o cuentas. Si la incidencia requiere ese tipo de intervención, pide el dato útil que falte o indica que un técnico debe revisarla.\n\nCORREOS QUE NO SE ENCUENTRAN\n- Distingue mensajes ausentes de las carpetas de mensajes que no aparecen en la búsqueda. La opción 1 debe aclarar esa diferencia cuando el ticket no la explique.\n- Las opciones 2 y 3 pueden proponer comprobaciones sencillas distintas sobre el alcance de la búsqueda, los filtros o las carpetas relevantes, o pedir un ejemplo de los mensajes que faltan. Elige según la información disponible.\n- No diagnostiques fallos de indexación ni recomiendes reindexar.\n\nLENTITUD O PROBLEMAS DE CONEXIÓN A INTERNET\n- Si el ticket describe lentitud general o varios servicios afectados a la vez (navegación, FTP, videollamadas, WhatsApp, etc.), una opción puede preguntar si el problema le pasa también a otros compañeros de la oficina, para distinguir una incidencia general de una aislada.\n- No preguntes directamente si la conexión es wifi o cable. Pregunta primero si el equipo afectado es un ordenador de mesa o un portátil, y propone la comprobación según corresponda (revisar que el cable de red esté bien conectado en equipos de mesa, o acercarse al router o reiniciarlo en portátiles).\n- Puedes proponer una prueba de velocidad en una web como fast.com y pedir que compartan el resultado, para distinguir un problema general de conexión de un problema puntual de una aplicación concreta.\n\nEVALUACIÓN Y SALIDA\n- confidence: “alta”, “media” o “baja”, según la información disponible y la adecuación de las propuestas. No marques “alta” si falta información esencial.\n- needs_human: true si hace falta diagnóstico adicional de un técnico, acceso remoto, una acción sensible o una decisión que no pueda resolverse con las indicaciones propuestas.\n- Antes de devolver el JSON, revisa las tres respuestas: reformula cualquier frase que use dos puntos como puntuación y elimina los signos de interrogación de apertura. Esta regla afecta al texto de las respuestas, no a la sintaxis del JSON.\n- Devuelve únicamente el JSON solicitado con confidence, needs_human, opcion_1, opcion_2 y opcion_3.";

	$user_content = "Categoria: {$category}\nPrioridad: {$priority}\nAsunto: {$subject}\nNombre del cliente: {$customer_name}\nDescripción del ticket:\n{$description}";

	$body = array(
		'model'                  => $model,
		// Sin esto, los modelos gpt-6 razonan por defecto (effort "medium")
		// y esos tokens de razonamiento se cobran como output aunque nunca
		// se vean -- para un borrador corto no hace falta, y sin bajarlo el
		// costo por ticket se dispara.
		'reasoning_effort'       => 'low',
		'max_completion_tokens'  => 600,
		'messages'               => array(
			array(
				'role'    => 'system',
				'content' => $system_prompt,
			),
			array(
				'role'    => 'user',
				'content' => $user_content,
			),
		),
		'response_format' => array(
			'type'        => 'json_schema',
			'json_schema' => array(
				'name'   => 'draft_response',
				'strict' => true,
				'schema' => array(
					'type'       => 'object',
					'properties' => array(
						'confidence'       => array(
							'type' => 'string',
							'enum' => array( 'alta', 'media', 'baja' ),
						),
						'needs_human'      => array( 'type' => 'boolean' ),
						'opcion_1'         => array( 'type' => 'string' ),
						'opcion_2'         => array( 'type' => 'string' ),
						'opcion_3'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'confidence', 'needs_human', 'opcion_1', 'opcion_2', 'opcion_3' ),
					'additionalProperties' => false,
				),
			),
		),
	);

	$response = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		cai_log_error( 'Error de red al llamar a la API de OpenAI: ' . $response->get_error_message() );
		return null;
	}

	$status = wp_remote_retrieve_response_code( $response );
	$data   = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status !== 200 || empty( $data['choices'][0]['message']['content'] ) ) {
		cai_log_error( 'Respuesta inesperada de la API (status ' . $status . '): ' . wp_remote_retrieve_body( $response ) );
		return null;
	}

	$text = $data['choices'][0]['message']['content'];

	$draft = json_decode( $text, true );
	if ( ! isset( $draft['opcion_1'], $draft['opcion_2'], $draft['opcion_3'] ) ) {
		cai_log_error( 'JSON de respuesta no reconocido: ' . $text );
		return null;
	}

	// Mantener las columnas y elecciones existentes del PWA. Sus nombres son
	// internos: la IA genera tres opciones sencillas, sin una categoria tecnica.
	$fields = array( 'opcion_1' => 'draft_basico', 'opcion_2' => 'draft_intermedio', 'opcion_3' => 'draft_tecnico' );
	foreach ( $fields as $key => $stored_key ) {
		if ( ! is_string( $draft[ $key ] ) || '' === trim( $draft[ $key ] ) ) {
			cai_log_error( 'La IA devolvio una opcion vacia o no textual.' );
			return null;
		}
		// Aplicar el estilo de preguntas incluso si el modelo ignora esa instruccion.
		$draft[ $stored_key ] = str_replace( '¿', '', $draft[ $key ] );
		unset( $draft[ $key ] );
	}

	return $draft;
}

/**
 * Push notifications via Firebase Cloud Messaging (API HTTP v1).
 * Autenticacion: se firma un JWT con la clave privada de la cuenta de
 * servicio (RS256, sin librerias externas) y se cambia por un access_token
 * de Google OAuth2, cacheado en un transient mientras sea valido.
 */
function cai_base64url( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

function cai_fcm_get_access_token() {
	$cached = get_transient( 'cai_fcm_access_token' );
	if ( $cached ) {
		return $cached;
	}

	$creds = cai_get_firebase_credentials();
	if ( ! $creds ) {
		cai_log_error( 'No se encontro la credencial de Firebase (firebase-service-account.php).' );
		return null;
	}

	$now    = time();
	$header = array(
		'alg' => 'RS256',
		'typ' => 'JWT',
	);
	$claims = array(
		'iss'   => $creds['client_email'],
		'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
		'aud'   => 'https://oauth2.googleapis.com/token',
		'iat'   => $now,
		'exp'   => $now + 3600,
	);

	$segments      = array(
		cai_base64url( wp_json_encode( $header ) ),
		cai_base64url( wp_json_encode( $claims ) ),
	);
	$signing_input = implode( '.', $segments );

	$private_key = openssl_pkey_get_private( $creds['private_key'] );
	if ( ! $private_key ) {
		cai_log_error( 'No se pudo leer la clave privada de Firebase.' );
		return null;
	}

	$signature = '';
	$signed    = openssl_sign( $signing_input, $signature, $private_key, 'SHA256' );
	if ( ! $signed ) {
		cai_log_error( 'Fallo al firmar el JWT de Firebase.' );
		return null;
	}
	$segments[] = cai_base64url( $signature );
	$jwt        = implode( '.', $segments );

	$response = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		cai_log_error( 'Error de red al pedir el token de Firebase: ' . $response->get_error_message() );
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['access_token'] ) ) {
		cai_log_error( 'Respuesta invalida al pedir el token de Firebase: ' . wp_remote_retrieve_body( $response ) );
		return null;
	}

	$ttl = isset( $data['expires_in'] ) ? intval( $data['expires_in'] ) - 60 : 3000;
	set_transient( 'cai_fcm_access_token', $data['access_token'], max( $ttl, 60 ) );

	return $data['access_token'];
}

/**
 * Guarda el token de notificacion push del dispositivo/navegador del agente
 * que esta usando el PWA. Un mismo agente puede tener varios (movil + PC).
 */
function cai_save_push_token( $user_id, $token ) {
	global $wpdb;
	$table = $wpdb->prefix . 'cai_push_tokens';
	$now   = current_time( 'mysql' );

	$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE token = %s", $token ) );
	if ( $existing_id ) {
		$wpdb->update(
			$table,
			array(
				'user_id'    => $user_id,
				'updated_at' => $now,
			),
			array( 'id' => $existing_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		return;
	}

	$wpdb->insert(
		$table,
		array(
			'user_id'    => $user_id,
			'token'      => $token,
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%d', '%s', '%s', '%s' )
	);
}

/**
 * Manda una notificacion push a todos los dispositivos registrados. Se usa
 * solo para avisar que hay un borrador nuevo -- nunca contiene el borrador
 * en si, solo el asunto del ticket, para no exponer datos del cliente en
 * una notificacion que puede aparecer con el telefono bloqueado.
 */
function cai_send_push_to_all( $title, $body, $url ) {
	global $wpdb;
	$token_table = $wpdb->prefix . 'cai_push_tokens';
	$tokens      = $wpdb->get_results( "SELECT id, token FROM {$token_table}", ARRAY_A );
	if ( ! $tokens ) {
		return;
	}

	$access_token = cai_fcm_get_access_token();
	if ( ! $access_token ) {
		return;
	}

	$creds    = cai_get_firebase_credentials();
	$endpoint = 'https://fcm.googleapis.com/v1/projects/' . $creds['project_id'] . '/messages:send';

	foreach ( $tokens as $row ) {
		$payload = array(
			'message' => array(
				'token'        => $row['token'],
				'notification' => array(
					'title' => $title,
					'body'  => $body,
				),
				'webpush'      => array(
					'fcm_options' => array( 'link' => $url ),
				),
			),
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			cai_log_error( 'Error de red al enviar push: ' . $response->get_error_message() );
			continue;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			$error_body   = json_decode( wp_remote_retrieve_body( $response ), true );
			$error_status = $error_body['error']['status'] ?? '';
			// Token vencido o de un dispositivo que ya no existe: se limpia
			// para no seguir intentando enviarle push en el futuro.
			if ( in_array( $error_status, array( 'NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT' ), true ) ) {
				$wpdb->delete( $token_table, array( 'id' => $row['id'] ), array( '%d' ) );
			} else {
				cai_log_error( 'Fallo al enviar push (status ' . $status . '): ' . wp_remote_retrieve_body( $response ) );
			}
		}
	}
}

/**
 * Guarda el ultimo error en una opcion para poder verlo en Ajustes >
 * AI Ticket Copilot sin necesitar acceso a los logs del servidor.
 */
function cai_log_error( $message ) {
	error_log( 'AI Ticket Copilot: ' . $message );
	update_option( 'cai_last_error', current_time( 'mysql' ) . ' -- ' . $message );
}

/**
 * Guarda una fila en cai_drafts para que el PWA pueda listarla y, si el
 * agente elige una opcion, usarla para enviar la respuesta real.
 */
function cai_save_draft_row( $ticket_id, $subject, $description, $category, $priority, $customer_name, $customer_email, $phone, $company, $confidence, $needs_human, $basico, $intermedio, $tecnico ) {
	global $wpdb;
	$table = $wpdb->prefix . 'cai_drafts';
	$now   = current_time( 'mysql' );
	$wpdb->insert(
		$table,
		array(
			'ticket_id'        => $ticket_id,
			'subject'          => $subject,
			'description'      => $description,
			'category'         => $category,
			'priority'         => $priority,
			'customer_name'    => $customer_name,
			'customer_email'   => $customer_email,
			'phone'            => $phone,
			'company'          => $company,
			'confidence'       => $confidence,
			'needs_human'      => $needs_human ? 1 : 0,
			'draft_basico'     => $basico,
			'draft_intermedio' => $intermedio,
			'draft_tecnico'    => $tecnico,
			'status'           => 'pending',
			'created_at'       => $now,
			'updated_at'       => $now,
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
}

function cai_on_ticket_created( $ticket_id ) {
	$openai_config = cai_get_openai_config();
	if ( ! get_option( CAI_OPTION_ENABLED ) || empty( $openai_config['api_key'] ) ) {
		cai_log_error( 'Ticket ' . $ticket_id . ': generacion desactivada o sin API key configurada.' );
		return;
	}

	global $wpscfunction;
	if ( ! isset( $wpscfunction ) ) {
		cai_log_error( 'Ticket ' . $ticket_id . ': $wpscfunction no disponible en el hook wpsc_ticket_created.' );
		return;
	}

	$subject        = $wpscfunction->get_ticket_fields( $ticket_id, 'ticket_subject' );
	$category       = cai_resolve_term_label( $wpscfunction->get_ticket_fields( $ticket_id, 'ticket_category' ) );
	$priority       = cai_resolve_term_label( $wpscfunction->get_ticket_fields( $ticket_id, 'ticket_priority' ) );
	$description    = cai_get_ticket_description( $ticket_id );
	$customer_name  = (string) $wpscfunction->get_ticket_fields( $ticket_id, 'customer_name' );
	$customer_email = (string) $wpscfunction->get_ticket_fields( $ticket_id, 'customer_email' );
	// Slugs confirmados en el formulario de "Nuevo ticket": Telefono = cust_70, Empresa = empresa.
	// Se leen directo de wpsc_ticketmeta (tabla EAV) -- ver cai_get_custom_field_value().
	$phone          = cai_get_custom_field_value( $ticket_id, 'cust_70' );
	$company        = cai_get_custom_field_value( $ticket_id, 'empresa' );

	$keyword_hit = cai_match_keyword_template( $subject, $description );

	if ( $keyword_hit ) {
		cai_save_draft_row( $ticket_id, $subject, $description, $category, $priority, $customer_name, $customer_email, $phone, $company, 'alta', false, $keyword_hit, $keyword_hit, $keyword_hit );
		cai_send_push_to_all(
			'Nuevo ticket: ' . $subject,
			'Borrador listo para revisar (regla fija).',
			CAI_PWA_URL
		);
		return;
	}

	$draft = cai_call_openai_api( $subject, $description, $category, $priority, $customer_name );
	if ( ! $draft ) {
		return;
	}

	cai_save_draft_row( $ticket_id, $subject, $description, $category, $priority, $customer_name, $customer_email, $phone, $company, $draft['confidence'], $draft['needs_human'], $draft['draft_basico'], $draft['draft_intermedio'], $draft['draft_tecnico'] );
	cai_send_push_to_all(
		'Nuevo ticket: ' . $subject,
		'Borrador de IA listo para revisar.',
		CAI_PWA_URL
	);
}
add_action( 'wpsc_ticket_created', 'cai_on_ticket_created', 999, 1 );

/**
 * REST API para el PWA: listar borradores pendientes, enviar la respuesta
 * elegida (esta vez si, como respuesta REAL al cliente, thread_type=reply)
 * y descartar un borrador sin usarlo. Solo agentes/administradores
 * autenticados (ej. via WP Application Passwords) pueden usarla.
 */
function cai_rest_permission_check() {
	return is_user_logged_in() && ( current_user_can( 'wpsc_agent' ) || current_user_can( 'manage_options' ) );
}

function cai_rest_get_pending( $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'cai_drafts';
	$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at ASC", ARRAY_A );
	return rest_ensure_response( $rows );
}

/**
 * Envia la opcion elegida por el agente como respuesta real al ticket.
 * Solo se ejecuta cuando un agente autenticado
 * lo confirma explicitamente desde el PWA.
 */
function cai_rest_send_reply( $request ) {
	global $wpdb;
	$table       = $wpdb->prefix . 'cai_drafts';
	$id          = (int) $request->get_param( 'id' );
	$choice      = $request->get_param( 'choice' );
	$custom_text = $request->get_param( 'text' );

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'", $id ), ARRAY_A );
	if ( ! $row ) {
		return new WP_Error( 'cai_not_found', 'Borrador no encontrado o ya procesado.', array( 'status' => 404 ) );
	}

	if ( 'custom' === $choice ) {
		if ( ! $custom_text ) {
			return new WP_Error( 'cai_missing_text', 'Falta el texto a enviar.', array( 'status' => 400 ) );
		}
		$text = $custom_text;
	} else {
		$field = 'draft_' . $choice;
		if ( ! isset( $row[ $field ] ) ) {
			return new WP_Error( 'cai_invalid_choice', 'Opcion invalida.', array( 'status' => 400 ) );
		}
		$text = $row[ $field ];
	}

	global $wpscfunction;
	if ( ! isset( $wpscfunction ) || ! method_exists( $wpscfunction, 'submit_ticket_thread' ) ) {
		return new WP_Error( 'cai_no_supportcandy', 'SupportCandy no disponible.', array( 'status' => 500 ) );
	}

	// SupportCandy identifica al autor de la respuesta por 'customer_name'/
	// 'customer_email' (asi se llaman esos campos incluso para un agente) --
	// sin esto, la respuesta sale sin nombre. Se usa el agente autenticado
	// en el PWA (via Application Password), no un valor fijo.
	$agent = wp_get_current_user();

	$wpscfunction->submit_ticket_thread(
		array(
			'ticket_id'      => $row['ticket_id'],
			'reply_body'     => nl2br( esc_html( $text ) ),
			'thread_type'    => 'reply',
			'customer_name'  => $agent->display_name,
			'customer_email' => $agent->user_email,
		)
	);

	// submit_ticket_thread() SOLO crea el thread -- nunca toca el status del
	// ticket (confirmado leyendo el codigo fuente de SupportCandy). El cambio
	// de status tras la respuesta del agente ("Ajustes > Estados del Ticket >
	// Estado del ticket despues de la respuesta del agente") solo se aplica
	// desde el panel nativo de agentes, no desde esta funcion PHP de bajo
	// nivel. Como el PWA llama a submit_ticket_thread() directo, replicamos
	// aqui ese mismo ajuste para que el ticket pase a "En espera de la
	// respuesta del cliente" (o el status que este configurado).
	//
	// NOTA: el modelo WPSC_Ticket::save() escribe en la tabla
	// `{$wpdb->prefix}psmsc_tickets`, que NO es la tabla real de tickets
	// (es `{$wpdb->prefix}wpsc_ticket`, la misma que usa submit_ticket_thread()
	// de arriba para date_updated/historyId). Por eso se usa UPDATE directo
	// sobre esa tabla en vez del modelo -- confirmado probando en ticket real
	// (#623) que el modelo no persistia el cambio.
	$status_after_agent_reply = (int) get_option( 'wpsc_ticket_status_after_agent_reply' );
	if ( $status_after_agent_reply ) {
		$wpdb->update(
			$wpdb->prefix . 'wpsc_ticket',
			array( 'ticket_status' => $status_after_agent_reply ),
			array( 'id' => $row['ticket_id'] ),
			array( '%d' ),
			array( '%d' )
		);
	}

	$wpdb->update(
		$table,
		array(
			'status'     => 'sent',
			'sent_by'    => get_current_user_id(),
			'sent_text'  => $text,
			'updated_at' => current_time( 'mysql' ),
		),
		array( 'id' => $id ),
		array( '%s', '%d', '%s', '%s' ),
		array( '%d' )
	);

	return rest_ensure_response( array( 'success' => true ) );
}

/**
 * Historial de respuestas ya enviadas -- para la pestana "Respondidas" del
 * PWA. Incluye el nombre del agente que la envio via un JOIN con wp_users.
 */
function cai_rest_get_history( $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'cai_drafts';
	$rows  = $wpdb->get_results(
		"SELECT d.*, u.display_name AS sent_by_name FROM {$table} d
		LEFT JOIN {$wpdb->users} u ON d.sent_by = u.ID
		WHERE d.status = 'sent'
		ORDER BY d.updated_at DESC
		LIMIT 50",
		ARRAY_A
	);
	return rest_ensure_response( $rows );
}

/**
 * Aviso de llamada desde el PWA. Conserva el pendiente y sus tres sugerencias.
 * La reserva atomica evita repetir el aviso al tocar dos veces o desde otro movil.
 */
function cai_rest_acknowledge( $request ) {
	global $wpdb, $wpscfunction;
	$table = $wpdb->prefix . 'cai_drafts';
	$id = (int) $request->get_param( 'id' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	if ( $wpdb->last_error ) {
		return new WP_Error( 'cai_db_error', 'No se pudo consultar el ticket.', array( 'status' => 500 ) );
	}
	if ( ! $row ) {
		return new WP_Error( 'cai_not_found', 'Borrador no encontrado.', array( 'status' => 404 ) );
	}
	if ( 'sent' === $row['ack_state'] ) {
		return rest_ensure_response( array( 'success' => true, 'ack_state' => 'sent', 'ack_sent_at' => $row['ack_sent_at'] ) );
	}
	if ( 'pending' !== $row['status'] ) {
		return new WP_Error( 'cai_not_pending', 'El borrador ya no esta pendiente.', array( 'status' => 409 ) );
	}
	if ( '' !== $row['ack_state'] ) {
		return new WP_Error( 'cai_ack_busy', 'El aviso ya se esta enviando o no se pudo confirmar. Comprueba el ticket en WordPress antes de enviar otro mensaje.', array( 'status' => 409 ) );
	}
	if ( ! isset( $wpscfunction ) || ! method_exists( $wpscfunction, 'submit_ticket_thread' ) ) {
		return new WP_Error( 'cai_no_supportcandy', 'SupportCandy no disponible.', array( 'status' => 500 ) );
	}
	// Permite comprobar la insercion incluso si esta version de SupportCandy no devuelve ID.
	$last_post_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
	if ( $wpdb->last_error ) {
		return new WP_Error( 'cai_db_error', 'No se pudo preparar el envio. Intenta de nuevo.', array( 'status' => 500 ) );
	}
	$claimed = $wpdb->update( $table, array( 'ack_state' => 'sending' ),
		array( 'id' => $id, 'status' => 'pending', 'ack_state' => '' ),
		array( '%s' ), array( '%d', '%s', '%s' ) );
	if ( 1 !== $claimed ) {
		return new WP_Error( 'cai_ack_unavailable', 'No se pudo iniciar el aviso. Actualiza el PWA para comprobar su estado.', array( 'status' => 409 ) );
	}
	$text = 'Te llamaremos en un rato.';
	$agent = wp_get_current_user();
	try {
		$wpscfunction->submit_ticket_thread( array(
			'ticket_id' => $row['ticket_id'],
			'reply_body' => esc_html( $text ),
			'thread_type' => 'reply',
			'customer_name' => $agent->display_name,
			'customer_email' => $agent->user_email,
		) );
	} catch ( Throwable $error ) {
		cai_log_error( 'Aviso de llamada del ticket ' . $row['ticket_id'] . ': ' . $error->getMessage() );
	}
	// Confirmar la respuesta guardada, no confundir una llamada sin error con un envio.
	$thread_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} AS p
		WHERE p.ID > %d AND p.post_type = 'wpsc_ticket_thread' AND p.post_status = 'publish' AND p.post_content = %s
		AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS link WHERE link.post_id = p.ID AND link.meta_key = 'ticket_id' AND link.meta_value = %s)
		AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} AS kind WHERE kind.post_id = p.ID AND kind.meta_key = 'thread_type' AND kind.meta_value = 'reply')
		ORDER BY p.ID DESC LIMIT 1",
		$last_post_id, $text, (string) $row['ticket_id']
	) );
	if ( ! $thread_id || $wpdb->last_error ) {
		$wpdb->update( $table, array( 'ack_state' => 'uncertain' ), array( 'id' => $id, 'ack_state' => 'sending' ), array( '%s' ), array( '%d', '%s' ) );
		return new WP_Error( 'cai_ack_unconfirmed', 'No se pudo confirmar el aviso. Comprueba el ticket en WordPress antes de enviar otro mensaje.', array( 'status' => 502 ) );
	}
	$sent_at = current_time( 'mysql' );
	$saved = $wpdb->update( $table,
		array( 'ack_state' => 'sent', 'ack_sent_at' => $sent_at, 'ack_sent_by' => get_current_user_id() ),
		array( 'id' => $id, 'ack_state' => 'sending' ),
		array( '%s', '%s', '%d' ), array( '%d', '%s' ) );
	if ( 1 !== $saved ) {
		return new WP_Error( 'cai_ack_tracking', 'El aviso se ha registrado en el ticket, pero no se pudo actualizar su estado en el PWA. No lo envies de nuevo.', array( 'status' => 500 ) );
	}
	return rest_ensure_response( array( 'success' => true, 'ack_state' => 'sent', 'ack_sent_at' => $sent_at ) );
}

function cai_rest_dismiss( $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'cai_drafts';
	$id    = (int) $request->get_param( 'id' );

	$updated = $wpdb->update(
		$table,
		array(
			'status'     => 'dismissed',
			'updated_at' => current_time( 'mysql' ),
		),
		array(
			'id'     => $id,
			'status' => 'pending',
		),
		array( '%s', '%s' ),
		array( '%d', '%s' )
	);

	if ( ! $updated ) {
		return new WP_Error( 'cai_not_found', 'Borrador no encontrado o ya procesado.', array( 'status' => 404 ) );
	}

	return rest_ensure_response( array( 'success' => true ) );
}

/**
 * Guarda el token de notificacion push que el navegador del agente genero
 * para este PWA, asociado al usuario de WP autenticado en la peticion.
 */
function cai_rest_register_token( $request ) {
	$token = $request->get_param( 'token' );
	if ( ! $token ) {
		return new WP_Error( 'cai_missing_token', 'Falta el token.', array( 'status' => 400 ) );
	}

	cai_save_push_token( get_current_user_id(), $token );

	return rest_ensure_response( array( 'success' => true ) );
}

/**
 * Elimina el token de push cuando el agente desactiva las notificaciones
 * desde el sino del PWA -- solo borra tokens del propio usuario autenticado.
 */
function cai_rest_unregister_token( $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'cai_push_tokens';
	$token = $request->get_param( 'token' );
	if ( ! $token ) {
		return new WP_Error( 'cai_missing_token', 'Falta el token.', array( 'status' => 400 ) );
	}

	$wpdb->delete( $table, array( 'token' => $token, 'user_id' => get_current_user_id() ), array( '%s', '%d' ) );

	return rest_ensure_response( array( 'success' => true ) );
}

function cai_register_rest_routes() {
	register_rest_route( 'ai-ticket-copilot/v1', '/acknowledge', array(
		'methods' => 'POST',
		'callback' => 'cai_rest_acknowledge',
		'permission_callback' => 'cai_rest_permission_check',
		'args' => array( 'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ) ),
	) );
	register_rest_route(
		'ai-ticket-copilot/v1',
		'/pending',
		array(
			'methods'             => 'GET',
			'callback'            => 'cai_rest_get_pending',
			'permission_callback' => 'cai_rest_permission_check',
		)
	);

	register_rest_route(
		'ai-ticket-copilot/v1',
		'/history',
		array(
			'methods'             => 'GET',
			'callback'            => 'cai_rest_get_history',
			'permission_callback' => 'cai_rest_permission_check',
		)
	);

	register_rest_route(
		'ai-ticket-copilot/v1',
		'/send',
		array(
			'methods'             => 'POST',
			'callback'            => 'cai_rest_send_reply',
			'permission_callback' => 'cai_rest_permission_check',
			'args'                => array(
				'id'     => array(
					'required' => true,
					'type'     => 'integer',
				),
				'choice' => array(
					'required' => true,
					'type'     => 'string',
					'enum'     => array( 'basico', 'intermedio', 'tecnico', 'custom' ),
				),
				'text'   => array(
					'required' => false,
					'type'     => 'string',
				),
			),
		)
	);

	register_rest_route(
		'ai-ticket-copilot/v1',
		'/dismiss',
		array(
			'methods'             => 'POST',
			'callback'            => 'cai_rest_dismiss',
			'permission_callback' => 'cai_rest_permission_check',
			'args'                => array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			),
		)
	);

	register_rest_route(
		'ai-ticket-copilot/v1',
		'/register-token',
		array(
			'methods'             => 'POST',
			'callback'            => 'cai_rest_register_token',
			'permission_callback' => 'cai_rest_permission_check',
			'args'                => array(
				'token' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);

	register_rest_route(
		'ai-ticket-copilot/v1',
		'/unregister-token',
		array(
			'methods'             => 'POST',
			'callback'            => 'cai_rest_unregister_token',
			'permission_callback' => 'cai_rest_permission_check',
			'args'                => array(
				'token' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'cai_register_rest_routes' );

/**
 * Pantalla de configuracion: Ajustes > AI Ticket Copilot.
 */
function cai_sanitize_trim( $value ) {
	return trim( (string) $value );
}

function cai_register_settings() {
	register_setting( 'cai_settings', CAI_OPTION_ENABLED );
	register_setting( 'cai_settings', CAI_OPTION_API_KEY, array( 'sanitize_callback' => 'cai_sanitize_trim' ) );
	register_setting( 'cai_settings', CAI_OPTION_MODEL, array( 'sanitize_callback' => 'cai_sanitize_trim' ) );
}
add_action( 'admin_init', 'cai_register_settings' );

/**
 * Regenera un pendiente por peticion para no acumular llamadas a la IA
 * dentro del mismo limite de tiempo del servidor. No envia replies ni push.
 */
function cai_ajax_regenerate_pending() {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cai_regenerate_pending', 'nonce', false ) ) {
		wp_send_json_error( array( 'message' => 'Sesion caducada o sin permisos. Recarga la pagina.' ), 403 );
	}
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		wp_send_json_error( array( 'message' => 'Metodo no permitido.' ), 405 );
	}
	$config = cai_get_openai_config();
	if ( empty( $config['api_key'] ) ) {
		wp_send_json_error( array( 'message' => 'Guarda una clave de API antes de regenerar los borradores.' ), 400 );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'cai_drafts';
	// La primera peticion toma una instantanea de los pendientes de este lote.
	if ( ! isset( $_POST['draft_id'] ) ) {
		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE status = 'pending' ORDER BY id ASC" );
		if ( $wpdb->last_error ) {
			wp_send_json_error( array( 'message' => 'No se pudo consultar la lista de pendientes.' ), 500 );
		}
		wp_send_json_success( array( 'ids' => array_map( 'intval', $ids ) ) );
	}

	$id = filter_var( $_POST['draft_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
	if ( false === $id ) {
		wp_send_json_error( array( 'message' => 'Borrador no valido.' ), 400 );
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'", $id ), ARRAY_A );
	if ( $wpdb->last_error ) {
		wp_send_json_error( array( 'message' => 'No se pudo consultar el borrador.' ), 500 );
	}
	if ( ! $row ) {
		wp_send_json_success( array( 'result' => 'skipped', 'message' => 'Ya no esta pendiente.' ) );
	}

	// Usar siempre el prompt actual, incluso si el borrador original era de regla fija.
	$draft = cai_call_openai_api( $row['subject'], $row['description'], $row['category'], $row['priority'], $row['customer_name'] );
	$valid = is_array( $draft ) && isset( $draft['confidence'], $draft['needs_human'] )
		&& in_array( $draft['confidence'], array( 'alta', 'media', 'baja' ), true ) && is_bool( $draft['needs_human'] );
	foreach ( array( 'draft_basico', 'draft_intermedio', 'draft_tecnico' ) as $key ) {
		$valid = $valid && isset( $draft[ $key ] ) && is_string( $draft[ $key ] ) && '' !== trim( $draft[ $key ] );
	}
	if ( ! $valid ) {
		wp_send_json_error( array( 'message' => 'La IA no devolvio tres respuestas validas. Se conserva el borrador anterior.' ), 502 );
	}

	// El estado y el contenido deben seguir intactos al terminar la llamada:
	// no sobrescribir un envio, descarte o regeneracion concurrente.
	$updated = $wpdb->update(
		$table,
		array(
			'draft_basico'     => $draft['draft_basico'],
			'draft_intermedio' => $draft['draft_intermedio'],
			'draft_tecnico'    => $draft['draft_tecnico'],
			'confidence'       => $draft['confidence'],
			'needs_human'      => $draft['needs_human'] ? 1 : 0,
			'updated_at'       => current_time( 'mysql' ),
		),
		array(
			'id'              => $id,
			'status'          => 'pending',
			'updated_at'      => $row['updated_at'],
			'draft_basico'     => $row['draft_basico'],
			'draft_intermedio' => $row['draft_intermedio'],
			'draft_tecnico'    => $row['draft_tecnico'],
		),
		array( '%s', '%s', '%s', '%s', '%d', '%s' ),
		array( '%d', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( false === $updated ) {
		wp_send_json_error( array( 'message' => 'No se pudieron guardar las respuestas. Se conserva el borrador anterior.' ), 500 );
	}
	wp_send_json_success( array(
		'result'    => $updated ? 'updated' : 'skipped',
		'ticket_id' => (int) $row['ticket_id'],
		'message'   => $updated ? 'Respuestas actualizadas.' : 'Sin cambios: el borrador ya fue actualizado o dejo de estar pendiente.',
	) );
}
add_action( 'wp_ajax_cai_regenerate_pending', 'cai_ajax_regenerate_pending' );

/**
 * Borra todos los tokens de push registrados -- util cuando se acumulan
 * tokens viejos (ej. de despliegues anteriores del plugin) y un mismo
 * dispositivo termina recibiendo la misma notificacion por duplicado.
 * Tras limpiar, el PWA vuelve a registrar un token nuevo solo en el proximo
 * inicio de sesion o al tocar el sino.
 */
function cai_handle_clear_push_tokens() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'cai_clear_push_tokens' ) ) {
		wp_die( 'No autorizado.' );
	}
	global $wpdb;
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cai_push_tokens" );
	wp_safe_redirect( add_query_arg( 'cai_cleared', '1', admin_url( 'options-general.php?page=ai-ticket-copilot' ) ) );
	exit;
}
add_action( 'admin_post_cai_clear_push_tokens', 'cai_handle_clear_push_tokens' );

function cai_add_settings_page() {
	add_options_page(
		'AI Ticket Copilot',
		'AI Ticket Copilot',
		'manage_options',
		'ai-ticket-copilot',
		'cai_render_settings_page'
	);
}
add_action( 'admin_menu', 'cai_add_settings_page' );

function cai_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_enqueue_script( 'cai-admin-regenerate', plugins_url( 'admin-regenerate.js', __FILE__ ), array(), '1.0.0', true );
	wp_localize_script( 'cai-admin-regenerate', 'caiRegenerate', array(
		'url'   => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'cai_regenerate_pending' ),
	) );
	?>
	<div class="wrap">
		<h1>AI Ticket Copilot -- Borradores automaticos</h1>
		<p>Cuando llega un ticket nuevo, genera un borrador de respuesta (via regla fija o IA) y avisa por <strong>notificacion push</strong> al PWA de aprobacion -- ahi el agente lo revisa, edita si hace falta y decide si lo envia o lo descarta. Nada se envia al cliente automaticamente.</p>
		<?php $last_error = get_option( 'cai_last_error' ); ?>
		<?php if ( $last_error ) : ?>
			<div class="notice notice-warning" style="padding: 10px;">
				<strong>Ultimo error registrado:</strong> <?php echo esc_html( $last_error ); ?>
			</div>
		<?php endif; ?>
		<?php if ( isset( $_GET['cai_cleared'] ) ) : ?>
			<div class="notice notice-success" style="padding: 10px;">
				Tokens de notificaciones eliminados. Vuelve a abrir el PWA (o toca el sino) en cada dispositivo para recibir notificaciones de nuevo.
			</div>
		<?php endif; ?>
		<?php if ( file_exists( __DIR__ . '/openai-config.php' ) ) : ?>
			<div class="notice notice-info" style="padding: 10px;">
				Hay un archivo <code>openai-config.php</code> en la carpeta del plugin -- mientras exista, la clave y el modelo de ahi tienen prioridad sobre lo que se guarde aqui abajo.
			</div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'cai_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">Activar generacion automatica</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( CAI_OPTION_ENABLED ); ?>" value="1" <?php checked( get_option( CAI_OPTION_ENABLED ), '1' ); ?> />
							Generar borrador al crear un ticket nuevo
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Clave de la API (OpenAI)</th>
					<td>
						<input type="password" name="<?php echo esc_attr( CAI_OPTION_API_KEY ); ?>" value="<?php echo esc_attr( get_option( CAI_OPTION_API_KEY ) ); ?>" class="regular-text" autocomplete="off" />
						<p class="description">Creada en platform.openai.com, dentro de un proyecto con creditos.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Modelo</th>
					<td>
						<input type="text" name="<?php echo esc_attr( CAI_OPTION_MODEL ); ?>" value="<?php echo esc_attr( get_option( CAI_OPTION_MODEL, 'gpt-6-sol' ) ); ?>" class="regular-text" />
						<p class="description">Por defecto: gpt-6-sol (equilibrio precio/calidad). Mas barato: gpt-6-luna. Mas potente: gpt-6-astra.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Guardar configuracion' ); ?>
		</form>
		<hr />
		<h2>App de aprobacion (PWA)</h2>
		<p>Abre esta pagina en el movil (Chrome/Safari) y usa "Anadir a pantalla de inicio" para instalarla como app. Necesitas un <a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>" target="_blank">Application Password</a> de tu usuario de WordPress para iniciar sesion ahi.</p>
		<p><a href="<?php echo esc_url( plugins_url( 'pwa/', __FILE__ ) ); ?>" target="_blank" class="button button-primary">Abrir app de aprobacion</a></p>
		<hr />
		<h2>Regenerar respuestas pendientes</h2>
		<p>Genera tres respuestas nuevas con las instrucciones y la configuracion guardadas para cada borrador pendiente del PWA. Sustituye las sugerencias anteriores sin enviar mensajes a los clientes.</p>
		<p class="description">Consume credito de la API por cada borrador. Guarda primero los cambios de configuracion y manten esta pagina abierta hasta terminar. Si un borrador falla, conserva sus respuestas anteriores.</p>
		<p><button type="button" class="button button-secondary" id="cai-regenerate-pending">Regenerar respuestas pendientes</button></p>
		<p id="cai-regenerate-status" role="status" aria-live="polite"></p>
		<ul id="cai-regenerate-errors"></ul>
		<noscript><p>Activa JavaScript para regenerar las respuestas.</p></noscript>
		<hr />
		<h2>Notificaciones push</h2>
		<?php
		global $wpdb;
		$token_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cai_push_tokens" );
		?>
		<p><?php echo esc_html( $token_count ); ?> dispositivo(s) registrado(s) para recibir notificaciones.</p>
		<p class="description">Si un dispositivo recibe la misma notificacion duplicada (por ejemplo, tras varias actualizaciones del plugin), usa esto para borrar todos los tokens y que cada dispositivo registre uno nuevo y unico.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('¿Borrar todos los tokens de notificaciones? Cada dispositivo debera volver a activarlas (abriendo el PWA o tocando el sino).');">
			<input type="hidden" name="action" value="cai_clear_push_tokens" />
			<?php wp_nonce_field( 'cai_clear_push_tokens' ); ?>
			<?php submit_button( 'Eliminar todos los tokens', 'delete', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
