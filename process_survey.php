<?php
/**
 * Procesador Backend de la Encuesta (Compatible con PHP < 8 y PHP 7.x)
 * Procesa la encuesta desde el servidor PHP sin exponer parámetros a JS ni al navegador.
 */

// Evitar acceso directo por GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Cargar la configuración privada de Salesforce desde el servidor
$config = require __DIR__ . '/config.php';

function getSalesforceAccessToken($oauthConfig, &$errorMessage)
{
    $requiredSettings = array(
        'token_url' => 'SF_TOKEN_URL',
        'client_id' => 'SF_CLIENT_ID',
        'client_secret' => 'SF_CLIENT_SECRET'
    );
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

    $ch = curl_init($oauthConfig['token_url']);
    if ($ch === false) {
        $errorMessage = 'No se pudo inicializar cURL para solicitar el token OAuth.';
        return false;
    }

    $curlOptions = array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(array(
            'grant_type' => 'client_credentials',
            'client_id' => $oauthConfig['client_id'],
            'client_secret' => $oauthConfig['client_secret']
        )),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true
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

    if ($response === false || $curlError !== '') {
        $errorMessage = 'Error cURL al solicitar el token OAuth: ' . $curlError;
        return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $tokenResponse = json_decode($response, true);
        $providerMessage = is_array($tokenResponse) && !empty($tokenResponse['error_description'])
            ? $tokenResponse['error_description']
            : $response;
        $errorMessage = 'Salesforce rechazó la autenticación OAuth (HTTP '
            . $httpCode . '): ' . $providerMessage;
        return false;
    }

    $tokenResponse = json_decode($response, true);
    if (!is_array($tokenResponse) || empty($tokenResponse['access_token'])) {
        $errorMessage = 'Salesforce respondió sin un access_token OAuth válido.';
        return false;
    }

    return $tokenResponse['access_token'];
}

// Sanitizar y obtener los datos enviados por el usuario
$case_id = isset($_POST['case_id']) ? trim($_POST['case_id']) : '';
$contact_id = isset($_POST['contact_id']) ? trim($_POST['contact_id']) : '';
$owner = isset($_POST['owner']) ? trim($_POST['owner']) : '';
$area = isset($_POST['area']) ? trim($_POST['area']) : 'Alumni';
$evento = isset($_POST['evento']) ? trim($_POST['evento']) : '';
$documento = isset($_POST['documento']) ? trim($_POST['documento']) : '';

$solicitud_atendida = isset($_POST['q_atendido']) ? trim($_POST['q_atendido']) : '';
$necesidad_resuelta = isset($_POST['q_resuelta']) ? trim($_POST['q_resuelta']) : '';
$satisfaccion_atencion = isset($_POST['q_satisfaccion']) ? trim($_POST['q_satisfaccion']) : '';
$calificacion_debora = isset($_POST['q_debora']) ? trim($_POST['q_debora']) : '';
$comentarios_mejora = isset($_POST['q_mejoras']) ? trim($_POST['q_mejoras']) : '';
$indicador_nps = isset($_POST['q_nps']) ? trim($_POST['q_nps']) : '';

// Validar que las preguntas requeridas estén completas
if (empty($solicitud_atendida) || empty($necesidad_resuelta) || empty($satisfaccion_atencion) || empty($calificacion_debora) || $indicador_nps === '') {
    $redirectUrl = 'index.php?error=campos_incompletos';
    if (!empty($case_id)) {
        $redirectUrl .= '&case_id=' . urlencode($case_id);
    }
    if (!empty($contact_id)) {
        $redirectUrl .= '&contactId=' . urlencode($contact_id);
    }
    if (!empty($owner)) {
        $redirectUrl .= '&owner=' . urlencode($owner);
    }
    if (!empty($area)) {
        $redirectUrl .= '&area=' . urlencode($area);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$mode = isset($config['mode']) ? $config['mode'] : 'HYBRID';
$targetStatus = isset($config['target_status']) ? $config['target_status'] : 'Resuelto';
$fieldsConfig = isset($config['fields']) ? $config['fields'] : array();

// HYBRID y REST_API envían siempre a Apex, incluso cuando no hay Caso relacionado.
if ($mode === 'HYBRID' || $mode === 'REST_API') {
    $oauthConfig = isset($config['salesforce_oauth']) ? $config['salesforce_oauth'] : array();
    $oauthError = '';
    $accessToken = getSalesforceAccessToken($oauthConfig, $oauthError);
    if ($accessToken === false) {
        error_log('Error de autenticación Salesforce: ' . $oauthError);
        header('Location: gracias.php' . (!empty($case_id) ? '?case_id=' . urlencode($case_id) . '&' : '?') . 'salesforce_error=1');
        exit;
    }

    if (empty($oauthConfig['apex_url']) || strpos($oauthConfig['apex_url'], 'https://') !== 0) {
        error_log('Error de configuración Salesforce: SF_APEX_URL debe ser una URL HTTPS.');
        header('Location: gracias.php' . (!empty($case_id) ? '?case_id=' . urlencode($case_id) . '&' : '?') . 'salesforce_error=1');
        exit;
    }

    $payloadData = array(
        'caseId' => $case_id,
        'contactId' => $contact_id,
        'status' => $targetStatus,
        'owner' => $owner,
        'area' => $area,
        'evento' => $evento,
        'documento' => $documento,
        'responses' => array(
            'solicitudAtendida' => $solicitud_atendida,
            'necesidadResuelta' => $necesidad_resuelta,
            'satisfaccionAtencion' => $satisfaccion_atencion,
            'calificacionDebora' => $calificacion_debora,
            'comentariosMejora' => $comentarios_mejora,
            'indicadorNPS' => $indicador_nps
        )
    );

    $jsonData = json_encode($payloadData);
    if ($jsonData === false) {
        error_log('Error al codificar el payload de encuesta: ' . json_last_error_msg());
        header('Location: gracias.php' . (!empty($case_id) ? '?case_id=' . urlencode($case_id) . '&' : '?') . 'salesforce_error=1');
        exit;
    }

    $ch = curl_init($oauthConfig['apex_url']);
    if ($ch === false) {
        error_log('Error de configuración Salesforce: no se pudo inicializar cURL para Apex REST.');
        header('Location: gracias.php' . (!empty($case_id) ? '?case_id=' . urlencode($case_id) . '&' : '?') . 'salesforce_error=1');
        exit;
    }

    $headers = array(
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $accessToken
    );

    $curlOptions = array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true
    );
    if (!empty($oauthConfig['ca_bundle'])) {
        if (!is_file($oauthConfig['ca_bundle']) || !is_readable($oauthConfig['ca_bundle'])) {
            error_log('Error de configuración Salesforce: el paquete indicado en SF_CA_BUNDLE no existe o no se puede leer.');
            header('Location: gracias.php' . (!empty($case_id) ? '?case_id=' . urlencode($case_id) . '&' : '?') . 'salesforce_error=1');
            exit;
        }
        $curlOptions[CURLOPT_CAINFO] = $oauthConfig['ca_bundle'];
    }
    curl_setopt_array($ch, $curlOptions);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $salesforce_error = $response === false || !empty($curl_error) || $http_code < 200 || $http_code >= 300;

    $response_data = null;
    if (!$salesforce_error) {
        $response_data = json_decode($response, true);
        $salesforce_error = !is_array($response_data)
            || !isset($response_data['success'])
            || $response_data['success'] !== true;
    }

    if ($salesforce_error) {
        error_log('Error al enviar encuesta a Salesforce. HTTP ' . $http_code . ': ' . $curl_error . ' Respuesta: ' . $response);
    }

    $redirect = 'gracias.php';
    if (!empty($case_id)) {
        $redirect .= '?case_id=' . urlencode($case_id);
    }
    if ($salesforce_error) {
        $redirect .= (strpos($redirect, '?') === false ? '?' : '&') . 'salesforce_error=1';
    } elseif (!empty($response_data['npsId'])) {
        $redirect .= (strpos($redirect, '?') === false ? '?' : '&') . 'nps_id=' . urlencode($response_data['npsId']);
    }
    header('Location: ' . $redirect);
    exit;

} 
// CASO 2: Si NO existe ID de Caso -> Crear Nuevo Caso / Registrar Encuesta Presencial
else {
    
    $postData = array(
        'orgid' => $config['org_id'],
        'status' => $targetStatus,
        isset($fieldsConfig['solicitud_atendida']) ? $fieldsConfig['solicitud_atendida'] : 'Solicitud_Atendida__c' => $solicitud_atendida,
        isset($fieldsConfig['necesidad_resuelta']) ? $fieldsConfig['necesidad_resuelta'] : 'Necesidad_Resuelta__c' => $necesidad_resuelta,
        isset($fieldsConfig['satisfaccion_atencion']) ? $fieldsConfig['satisfaccion_atencion'] : 'Satisfaccion_Atencion__c' => $satisfaccion_atencion,
        isset($fieldsConfig['calificacion_debora']) ? $fieldsConfig['calificacion_debora'] : 'Calificacion_Debora__c' => $calificacion_debora,
        isset($fieldsConfig['comentarios_mejora']) ? $fieldsConfig['comentarios_mejora'] : 'description' => $comentarios_mejora,
        isset($fieldsConfig['indicador_nps']) ? $fieldsConfig['indicador_nps'] : 'Indicador_NPS__c' => $indicador_nps,
        isset($fieldsConfig['area']) ? $fieldsConfig['area'] : 'Area__c' => $area,
        isset($fieldsConfig['asesor']) ? $fieldsConfig['asesor'] : 'Asesor__c' => $owner,
        isset($fieldsConfig['evento']) ? $fieldsConfig['evento'] : 'Evento__c' => $evento,
        isset($fieldsConfig['documento']) ? $fieldsConfig['documento'] : 'Documento__c' => $documento
    );

    $ch = curl_init($config['web_to_case_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (!empty($curl_error) || $http_code < 200 || $http_code >= 400) {
        error_log('Error al crear caso en Salesforce Web-to-Case. HTTP ' . $http_code . ': ' . $curl_error . ' Respuesta: ' . $response);
        header('Location: gracias.php?salesforce_error=1');
        exit;
    }

    header('Location: gracias.php');
    exit;
}
