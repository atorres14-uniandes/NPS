<?php
/**
 * Funciones compartidas por index.php y process_survey.php.
 * El contexto de cada formulario (caso, asesor, área, evento) se guarda en la
 * sesión del servidor; el navegador solo recibe un identificador opaco.
 */

define('SURVEY_SESSION_KEY', 'nps_forms');
define('SURVEY_MAX_FORMS', 10);
define('SURVEY_FORM_TTL', 7200);
define('SURVEY_DOCUMENT_PATTERN', '/^[0-9]{4,15}$/');
define('SURVEY_COMMENT_MAX_LENGTH', 32768);

function survey_start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('NPSSESSID');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ));
    session_start();
}

function survey_uuid4()
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * Valida el formato de un Id de Salesforce y su prefijo de objeto.
 * Solo comprueba la forma; la existencia la valida Apex.
 */
function survey_is_salesforce_id($value, $prefix, $only18 = false)
{
    $pattern = $only18 ? '/^[a-zA-Z0-9]{18}$/' : '/^[a-zA-Z0-9]{15}([a-zA-Z0-9]{3})?$/';
    return is_string($value)
        && preg_match($pattern, $value) === 1
        && strpos($value, $prefix) === 0;
}

function survey_normalize_document($value)
{
    return preg_replace('/[\s.\-]/', '', (string) $value);
}

function survey_clean_text($value, $maxLength)
{
    // preg_replace devuelve null si el texto no es UTF-8 válido.
    $value = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value));
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
}

/**
 * Devuelve el área del catálogo que coincide con el valor recibido
 * (sin distinguir mayúsculas ni tildes) o '' si no coincide ninguna.
 */
function survey_resolve_area($value, array $areas)
{
    $simplify = function ($text) {
        return strtolower(strtr(trim((string) $text), array(
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u'
        )));
    };
    foreach ($areas as $area) {
        if ($simplify($area) === $simplify($value)) {
            return $area;
        }
    }
    return '';
}

/**
 * Valores permitidos por pregunta. Deben coincidir con SurveyCaseService.cls.
 */
function survey_answer_options()
{
    return array(
        'q_atendido' => array('Sí, completamente', 'Parcialmente', 'No'),
        'q_resuelta' => array('Sí', 'Parcialmente', 'No'),
        'q_satisfaccion' => array('Muy satisfecho', 'Satisfecho', 'Neutral', 'Insatisfecho', 'Muy insatisfecho'),
        'q_debora' => array('1', '2', '3', '4', '5'),
        'q_nps' => array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10')
    );
}

function survey_register_form(array $context)
{
    if (!isset($_SESSION[SURVEY_SESSION_KEY]) || !is_array($_SESSION[SURVEY_SESSION_KEY])) {
        $_SESSION[SURVEY_SESSION_KEY] = array();
    }

    $now = time();
    foreach ($_SESSION[SURVEY_SESSION_KEY] as $id => $form) {
        if ($form['created'] < $now - SURVEY_FORM_TTL) {
            unset($_SESSION[SURVEY_SESSION_KEY][$id]);
        }
    }
    while (count($_SESSION[SURVEY_SESSION_KEY]) >= SURVEY_MAX_FORMS) {
        array_shift($_SESSION[SURVEY_SESSION_KEY]);
    }

    $formId = survey_uuid4();
    $context['csrf'] = bin2hex(random_bytes(32));
    $context['created'] = $now;
    $context['submitted'] = false;
    $_SESSION[SURVEY_SESSION_KEY][$formId] = $context;
    return $formId;
}

function survey_get_form($formId)
{
    if (!is_string($formId)
        || !isset($_SESSION[SURVEY_SESSION_KEY][$formId])
        || $_SESSION[SURVEY_SESSION_KEY][$formId]['created'] < time() - SURVEY_FORM_TTL) {
        return null;
    }
    return $_SESSION[SURVEY_SESSION_KEY][$formId];
}

function survey_mark_submitted($formId)
{
    if (isset($_SESSION[SURVEY_SESSION_KEY][$formId])) {
        $_SESSION[SURVEY_SESSION_KEY][$formId]['submitted'] = true;
    }
}

/**
 * Límite simple de envíos por IP en una ventana de tiempo, guardado en archivos temporales.
 */
function survey_rate_limit_ok($maxRequests, $windowSeconds)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'desconocida';
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nps_rate_' . sha1($ip) . '.json';
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        error_log('NPS: no se pudo abrir el archivo de límite de envíos.');
        return true;
    }

    flock($handle, LOCK_EX);
    $times = json_decode(stream_get_contents($handle), true);
    if (!is_array($times)) {
        $times = array();
    }

    $now = time();
    $times = array_values(array_filter($times, function ($time) use ($now, $windowSeconds) {
        return is_int($time) && $time > $now - $windowSeconds;
    }));
    $allowed = count($times) < $maxRequests;
    if ($allowed) {
        $times[] = $now;
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($times));
    flock($handle, LOCK_UN);
    fclose($handle);
    return $allowed;
}
