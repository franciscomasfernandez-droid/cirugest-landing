<?php
/**
 * ============================================================================
 * CiruGest — Receptor del formulario de la landing comercial
 * ----------------------------------------------------------------------------
 * Recibe el POST de landingcomercial.html, valida los datos y envía el aviso
 * por correo. Los datos NO salen del hosting de CiruGest: no interviene ningún
 * servicio de terceros.
 *
 * INSTALACIÓN
 *   1. Suba este archivo a la MISMA carpeta que landingcomercial.html.
 *   2. Si queda dentro de la instalación de WordPress, detecta wp-load.php solo
 *      y usa wp_mail() con la configuración SMTP que ya tenga el sitio.
 *   3. Revise la sección CONFIGURACIÓN de abajo.
 *
 * REQUISITO DE ENTREGABILIDAD
 *   El remitente (CG_FROM_EMAIL) debe ser una casilla del dominio cirugest.cl.
 *   Si usa un correo externo (Gmail, etc.) como remitente, SPF/DMARC hará que
 *   los avisos caigan en spam o sean rechazados.
 * ============================================================================
 */

// ----------------------------- CONFIGURACIÓN -----------------------------

/** Destinatario(s) de los avisos. Separe varios con coma. */
const CG_TO_EMAIL    = 'contacto@cirugest.cl';

/** Remitente. DEBE ser una casilla real del dominio cirugest.cl. */
const CG_FROM_EMAIL  = 'no-reply@cirugest.cl';
const CG_FROM_NAME   = 'Landing CiruGest';

/**
 * Respaldo opcional en CSV. Déjelo vacío ('') para no guardar nada.
 * Si lo activa, use una ruta FUERA de la carpeta pública (public_html),
 * por ejemplo: '/home/usuario/cirugest-leads.csv'
 * Nunca lo deje dentro de la web: sería un archivo de datos descargable.
 */
const CG_LOG_FILE    = '';

/** Segundos mínimos entre cargar el formulario y enviarlo (anti-bot). */
const CG_MIN_SECONDS = 3;

// ------------------------------------------------------------------------

// Todo lo que imprima WordPress al cargarse queda atrapado aquí y se descarta,
// para que la respuesta sea siempre JSON limpio.
ob_start();

/** Respuesta JSON uniforme. Descarta cualquier salida previa. */
function cg_responder(bool $ok, string $mensaje, int $codigo = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        http_response_code($codigo);
    }
    echo json_encode(['ok' => $ok, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Recorta respetando UTF-8 aunque el hosting no tenga mbstring. */
function cg_recortar(string $v, int $max): string {
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

/** Limpia un valor de entrada: sin etiquetas, sin saltos de línea, acotado. */
function cg_limpiar(string $campo, int $max = 200): string {
    $v = $_POST[$campo] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = strip_tags(trim($v));
    $v = str_replace(["\r", "\n", "\0"], ' ', $v);   // evita inyección de cabeceras
    return cg_recortar($v, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cg_responder(false, 'Método no permitido.', 405);
}

// ------------------------------- ANTI-SPAM -------------------------------

// 1. Honeypot: campo invisible que solo un bot completa.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    cg_responder(true, 'Recibido.');   // respuesta neutra: el bot no aprende nada
}

// 2. Trampa de tiempo: un humano no completa el formulario en 3 segundos.
$inicio = (int) ($_POST['t'] ?? 0);
if ($inicio > 0 && (time() - intdiv($inicio, 1000)) < CG_MIN_SECONDS) {
    cg_responder(true, 'Recibido.');
}

// ------------------------------ VALIDACIÓN -------------------------------

$nombres   = cg_limpiar('nombres', 80);
$apellidos = cg_limpiar('apellidos', 80);
$email     = cg_limpiar('email', 120);
$telefono  = cg_limpiar('telefono', 30);
$modalidad = cg_limpiar('modalidad', 60);

$modalidades = [
    'Visita Presencial de un Ejecutivo',
    'Videoreunión con un Ejecutivo',
];

$errores = [];
if ($nombres === '' || $apellidos === '')             { $errores[] = 'nombre'; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL))       { $errores[] = 'email'; }
if (strlen(preg_replace('/\D/', '', $telefono)) < 11) { $errores[] = 'teléfono'; }
if (!in_array($modalidad, $modalidades, true))        { $errores[] = 'modalidad'; }
if (($_POST['consent'] ?? '') === '')                 { $errores[] = 'autorización'; }

if ($errores) {
    cg_responder(false, 'Revise los siguientes campos: ' . implode(', ', $errores) . '.', 422);
}

// -------------------------------- CORREO ---------------------------------

$fecha = date('d-m-Y H:i');
$ip    = $_SERVER['REMOTE_ADDR'] ?? 's/i';

// El nombre va dentro de una cabecera: fuera cualquier carácter que la rompa.
$nombre_cabecera = trim(str_replace(['<', '>', '"', ',', ';', ':'], '', "$nombres $apellidos"));

$asunto = "Nueva solicitud de reunión — {$nombres} {$apellidos}";
$cuerpo = <<<TXT
Nueva solicitud desde la landing comercial.

Nombres ............ {$nombres}
Apellidos .......... {$apellidos}
Email .............. {$email}
Teléfono ........... {$telefono}
Modalidad .......... {$modalidad}

Fecha .............. {$fecha}
Origen ............. landingcomercial.html
IP ................. {$ip}

Responder dentro de 1 día hábil (es el compromiso publicado en la landing).
TXT;

$cabeceras = [
    'From: ' . CG_FROM_NAME . ' <' . CG_FROM_EMAIL . '>',
    'Reply-To: ' . $nombre_cabecera . ' <' . $email . '>',
    'Content-Type: text/plain; charset=UTF-8',
];

// Reutiliza WordPress si el archivo está dentro de la instalación:
// así los avisos salen por el mismo SMTP ya configurado en el sitio.
$wp = __DIR__ . '/wp-load.php';
if (!file_exists($wp)) {
    $wp = dirname(__DIR__) . '/wp-load.php';
}

if (file_exists($wp)) {
    define('WP_USE_THEMES', false);
    require_once $wp;
    $enviado = wp_mail(CG_TO_EMAIL, $asunto, $cuerpo, $cabeceras);
} else {
    $enviado = mail(
        CG_TO_EMAIL,
        '=?UTF-8?B?' . base64_encode($asunto) . '?=',
        $cuerpo,
        implode("\r\n", $cabeceras)
    );
}

// ------------------------------- RESPALDO --------------------------------

if (CG_LOG_FILE !== '') {
    $fh = @fopen(CG_LOG_FILE, 'a');
    if ($fh) {
        @fputcsv($fh, [$fecha, $nombres, $apellidos, $email, $telefono, $modalidad, $ip]);
        @fclose($fh);
    }
}

// ------------------------------ RESPUESTA --------------------------------

if (!$enviado) {
    error_log('[CiruGest] Falló el envío del aviso para ' . $email);
    cg_responder(false, 'No pudimos procesar su solicitud. Escríbanos a contacto@cirugest.cl.', 500);
}

cg_responder(true, 'Solicitud recibida.');
