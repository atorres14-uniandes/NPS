<?php
/**
 * Procesador Backend de la Encuesta.
 * Valida el envío, recupera el contexto desde la sesión del servidor y lo envía a Apex REST.
 * El navegador nunca decide el caso ni el contacto; Salesforce los resuelve.
 */

// Evitar acceso directo por GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require __DIR__ . '/survey_common.php';
$config = require __DIR__ . '/config.php';

survey_start_session();

function redirect_to($url)
{
    header('Location: ' . $url);
    exit;
}

function post_value($key)
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

function getSalesforceAccessToken($oauthConfig, &$errorMessage)
{
    $grantType = isset($oauthConfig['grant_type']) ? $oauthConfig['grant_type'] : '';
    $requiredSettings = array(
        'token_url' => 'SF_TOKEN_URL',
        'client_id' => 'SF_CLIENT_ID',
        'client_secret' => 'SF_CLIENT_SECRET'
    );
    if ($grantType === 'password') {
        $requiredSettings['username'] = 'SF_USERNAME';
        $requiredSettings['password'] = 'SF_PASSWORD';
    } elseif ($grantType !== 'client_credentials') {
        $errorMessage = 'SF_GRANT_TYPE debe ser "password" o "client_credentials".';
        return false;
    }

    foreach ($requiredSettings as $setting => $environmentName) {
        if (empty($oauthConfig[$setting])
            || strpos($oauthConfig[$setting], 'REPLACE_') === 0) {
            $errorMessage = 'Falta configurar ' . $environmentName
                . ' en el archivo de entorno privado.';
            return false;
        }
    }

    if (strpos($oauthConfig['token_url'], 'https://') !== 0) {
        $errorMessage = 'La URL del token OAuth debe usar HTTPS.';
        return false;
    }

    $tokenRequest = array(
        'grant_type' => $grantType,
        'client_id' => $oauthConfig['client_id'],
        'client_secret' => $oauthConfig['client_secret']
    );
    if ($grantType === 'password') {
        $tokenRequest['username'] = $oauthConfig['username'];
        $tokenRequest['password'] = $oauthConfig['password'];
    }

    $result = salesforce_http_post(
        $oauthConfig,
        $oauthConfig['token_url'],
        http_build_query($tokenRequest),
        array('Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'),
        $errorMessage
    );
    if ($result === false) {
        return false;
    }

    $tokenResponse = json_decode($result['body'], true);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        // Solo el código de error de OAuth; la respuesta completa puede incluir datos de la cuenta.
        $providerError = is_array($tokenResponse) && !empty($tokenResponse['error'])
            ? $tokenResponse['error']
            : 'sin_detalle';
        $errorMessage = 'Salesforce rechazó la autenticación OAuth (HTTP '
            . $result['status'] . '): ' . $providerError;
        return false;
    }

    if (!is_array($tokenResponse) || empty($tokenResponse['access_token'])) {
        $errorMessage = 'Salesforce respondió sin un access_token OAuth válido.';
        return false;
    }

    return $tokenResponse['access_token'];
}

function salesforce_http_post($oauthConfig, $url, $body, $headers, &$errorMessage)
{
    $ch = curl_init($url);
    if ($ch === false) {
        $errorMessage = 'No se pudo inicializar cURL.';
        return false;
    }

    $curlOptions = array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    );

    if (!empty($oauthConfig['ca_bundle'])) {
        if (!is_file($oauthConfig['ca_bundle']) || !is_readable($oauthConfig['ca_bundle'])) {
            $errorMessage = 'El paquete de certificados indicado en SF_CA_BUNDLE no existe o no se puede leer.';
            return false;
        }
        $curlOptions[CURLOPT_CAINFO] = $oauthConfig['ca_bundle'];
    }

    curl_setopt_array($ch, $curlOptions);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        $errorMessage = 'Error cURL: ' . $curlError;
        return false;
    }

    return array('status' => $httpCode, 'body' => $response);
}

// 1. Validar que el formulario provenga de esta sesión (CSRF y contexto)
$form_id = post_value('form_id');
$form = survey_get_form($form_id);
if ($form === null || !hash_equals($form['csrf'], post_value('csrf_token'))) {
    redirect_to('gracias.php?estado=expirada');
}
if ($form['submitted']) {
    redirect_to('gracias.php?estado=ok');
}

$rateLimit = isset($config['rate_limit']) ? $config['rate_limit'] : array('max' => 20, 'window' => 600);
if (!survey_rate_limit_ok($rateLimit['max'], $rateLimit['window'])) {
    error_log('NPS: límite de envíos alcanzado.');
    redirect_to('gracias.php?estado=limite');
}

$back_with_error = 'index.php?form=' . urlencode($form_id) . '&error=campos_incompletos';

// 2. Validar respuestas contra los valores permitidos
$answers = array();
foreach (survey_answer_options() as $question => $allowedValues) {
    $value = trim(post_value($question));
    if (!in_array($value, $allowedValues, true)) {
        redirect_to($back_with_error);
    }
    $answers[$question] = $value;
}

$comentarios = trim(str_replace("\0", '', post_value('q_mejoras')));
$commentLength = function_exists('mb_strlen') ? mb_strlen($comentarios, 'UTF-8') : strlen($comentarios);
if ($commentLength > SURVEY_COMMENT_MAX_LENGTH) {
    redirect_to($back_with_error);
}

// 3. Asesor: el de la URL (guardado en sesión) o el elegido de la lista
$agents_catalog = isset($config['agents']) ? $config['agents'] : array();
$owner = $form['owner'];
if ($owner === '') {
    $owner = trim(post_value('owner'));
    if (!isset($agents_catalog[$owner])) {
        redirect_to($back_with_error);
    }
}

// 4. Documento: solo en encuestas sin caso
$documento = '';
if ($form['case_id'] === '') {
    $documento = survey_normalize_document(post_value('documento'));
    if (!preg_match(SURVEY_DOCUMENT_PATTERN, $documento)) {
        redirect_to($back_with_error);
    }
}

// 5. Enviar a Salesforce
$oauthConfig = isset($config['salesforce_oauth']) ? $config['salesforce_oauth'] : array();
$errorMessage = '';
$accessToken = getSalesforceAccessToken($oauthConfig, $errorMessage);
if ($accessToken === false) {
    error_log('NPS: error de autenticación Salesforce. ' . $errorMessage);
    redirect_to('gracias.php?estado=error');
}

if (empty($oauthConfig['apex_url']) || strpos($oauthConfig['apex_url'], 'https://') !== 0) {
    error_log('NPS: SF_APEX_URL debe ser una URL HTTPS.');
    redirect_to('gracias.php?estado=error');
}

$payloadData = array(
    // El mismo form_id se reenvía en cada reintento: Apex no duplica el registro.
    'submissionId' => $form_id,
    'caseId' => $form['case_id'],
    'documento' => $documento,
    'owner' => $owner,
    'recordType' => isset($form['record_type']) ? $form['record_type'] : '',
    'responses' => array(
        'solicitudAtendida' => $answers['q_atendido'],
        'necesidadResuelta' => $answers['q_resuelta'],
        'satisfaccionAtencion' => $answers['q_satisfaccion'],
        'calificacionDebora' => $answers['q_debora'],
        'comentariosMejora' => $comentarios,
        'indicadorNPS' => $answers['q_nps']
    )
);

$jsonData = json_encode($payloadData);
if ($jsonData === false) {
    error_log('NPS: error al codificar el payload: ' . json_last_error_msg());
    redirect_to('gracias.php?estado=error');
}

$result = salesforce_http_post(
    $oauthConfig,
    $oauthConfig['apex_url'],
    $jsonData,
    array(
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken
    ),
    $errorMessage
);
if ($result === false) {
    error_log('NPS: error al llamar a Apex REST. ' . $errorMessage);
    redirect_to('gracias.php?estado=error');
}

$response = json_decode($result['body'], true);
$code = is_array($response) && isset($response['code']) && is_string($response['code']) ? $response['code'] : '';
$success = is_array($response) && isset($response['success']) && $response['success'] === true;

if ($success && $result['status'] >= 200 && $result['status'] < 300) {
    survey_mark_submitted($form_id);
    redirect_to('gracias.php?estado=ok');
}

// Solo se registra el código HTTP y el código funcional; nunca el documento ni las respuestas.
error_log('NPS: Salesforce no registró la encuesta. HTTP ' . $result['status'] . ' código ' . ($code !== '' ? $code : 'sin_codigo'));

if ($code === 'ALREADY_ANSWERED') {
    survey_mark_submitted($form_id);
    redirect_to('gracias.php?estado=respondida');
}
if ($code === 'INVALID_CASE') {
    redirect_to('gracias.php?estado=enlace');
}
if ($code === 'INVALID_REQUEST') {
    redirect_to($back_with_error);
}
redirect_to('gracias.php?estado=error');
