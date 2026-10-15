<?php
/**
 * Configuración Servidor Backend Salesforce y Encuesta.
 */

$envFile = getenv('SURVEY_ENV_FILE');
if ($envFile === false || trim($envFile) === '') {
    $envFile = dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR . 'etc'
        . DIRECTORY_SEPARATOR . 'Encuesta NPS'
        . DIRECTORY_SEPARATOR . '.env';
}

$envValues = array();
if (is_file($envFile) && is_readable($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES);
    if ($envLines === false) {
        throw new RuntimeException('No se pudo leer el archivo de entorno de Salesforce.');
    }

    foreach ($envLines as $envLine) {
        $envLine = trim($envLine);
        if ($envLine === '' || strpos($envLine, '#') === 0) {
            continue;
        }

        if (strpos($envLine, 'export ') === 0) {
            $envLine = substr($envLine, 7);
        }

        $separator = strpos($envLine, '=');
        if ($separator === false) {
            continue;
        }

        $key = trim(substr($envLine, 0, $separator));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }

        $value = trim(substr($envLine, $separator + 1));
        if (strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"')
                || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value);
        }
        $envValues[$key] = $value;
    }
}

$getSetting = function ($key, $default = '') use ($envValues) {
    if (array_key_exists($key, $envValues)) {
        return $envValues[$key];
    }
    $value = getenv($key);
    return $value === false ? $default : $value;
};

return array(
    // Modo de integración: "HYBRID", "REST_API", "WEB_TO_CASE"
    'mode' => 'HYBRID',

    // Entorno usado para las pruebas. Este archivo debe apuntar a la sandbox.
    'environment' => 'sandbox',

    // ID de la Organización de Salesforce (Org ID) de la sandbox
    'org_id' => '00Dce000006gRMv',

    // OAuth y Apex REST autenticado. Las credenciales se guardan fuera del webroot.
    'salesforce_oauth' => array(
        'grant_type' => $getSetting('SF_GRANT_TYPE', 'client_credentials'),
        'token_url' => $getSetting(
            'SF_TOKEN_URL',
            'https://vde--full.sandbox.my.salesforce.com/services/oauth2/token'
        ),
        'client_id' => $getSetting('SF_CLIENT_ID'),
        'client_secret' => $getSetting('SF_CLIENT_SECRET'),
        'username' => $getSetting('SF_USERNAME'),
        'password' => $getSetting('SF_PASSWORD'),
        'apex_url' => $getSetting(
            'SF_APEX_URL',
            'https://vde--full.sandbox.my.salesforce.com/services/apexrest/SurveyCaseServiceV2'
        ),
        'ca_bundle' => $getSetting('SF_CA_BUNDLE', 'C:\\laragon\\etc\\ssl\\cacert.pem')
    ),

    // URL del Endpoint Web-to-Case para CREAR nuevos casos
    'web_to_case_url' => 'https://webto.salesforce.com/servlet/servlet.WebToCase?encoding=UTF-8',

    // Estado al que pasará el caso en Salesforce tras responder la encuesta
    'target_status' => 'Resuelto',

    // Áreas soportadas
    'areas' => array('Alumni', 'Filantropía', 'Servicios'),

    // Catálogo de asesores y mapeo con su área (acepta Salesforce Long ID 005... o Nombre)
    'agents' => array(
        // Mapeo por Salesforce Long ID (Ejemplos)
        '0051I000002ikf7QAA' => array('name' => 'Debora Valencia', 'area' => 'Alumni'),
        '0051I0000025WKnQAM' => array('name' => 'Marcos Torres', 'area' => 'Alumni'),
        '005ce000008dPQ9AAM' => array('name' => 'Alexander Torres Escudero', 'area' => 'JSAI'),
        
        
        // Mapeo por Nombre
        'Debora Valencia' => array('name' => 'Debora Valencia', 'area' => 'Alumni'),
        'Marcos Torres' => array('name' => 'Marcos Torres', 'area' => 'Alumni'),
        'Alexander Torres Escudero' => array('name' => 'Alexander Torres Escudero', 'area' => 'JSAI'),

    ),

    // Mapeo de Nombres de Campos de Salesforce
    'fields' => array(
        'solicitud_atendida' => '00Nce0000035tQL',
        'necesidad_resuelta' => '00Nce0000035tN7',
        'satisfaccion_atencion' => '00Nce0000035sxJ',
        'calificacion_debora' => '00Nce0000035spF',
        'comentarios_mejora' => '00Nce0000035t29',
        'indicador_nps' => '00Nce0000035tJt',
        'area' => 'Area__c',
        'evento' => 'Evento__c',
        'documento' => 'Documento_Identificacion__c',
        'asesor' => 'Asesor__c'
    )
);
