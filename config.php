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
    // OAuth y Apex REST autenticado. Las credenciales se guardan fuera del webroot.
    'salesforce_oauth' => array(
        // "password" o "client_credentials"
        'grant_type' => $getSetting('SF_GRANT_TYPE', 'client_credentials'),
        'token_url' => $getSetting('SF_TOKEN_URL'),
        'client_id' => $getSetting('SF_CLIENT_ID'),
        'client_secret' => $getSetting('SF_CLIENT_SECRET'),
        'username' => $getSetting('SF_USERNAME'),
        'password' => $getSetting('SF_PASSWORD'),
        'apex_url' => $getSetting('SF_APEX_URL'),
        'ca_bundle' => $getSetting('SF_CA_BUNDLE', 'C:\\laragon\\etc\\ssl\\cacert.pem')
    ),

    // Límite de envíos por IP: máximo de envíos dentro de la ventana (segundos)
    'rate_limit' => array('max' => 20, 'window' => 600),

    // Áreas que se aceptan en el parámetro "area" de la URL
    'areas' => array('Alumni', 'Filantropía', 'Servicios', 'JSAI'),

    // Catálogo de asesores por Id de usuario de Salesforce (005...).
    // Se usa para la lista "¿Quién te atendió?" y para mostrar el nombre del asesor.
    'agents' => array(
        '0051I000002ikf7QAA' => array('name' => 'Debora Valencia', 'area' => 'Alumni'),
        '0051I0000025WKnQAM' => array('name' => 'Marcos Torres', 'area' => 'Alumni'),
        '005ce000008dPQ9AAM' => array('name' => 'Alexander Torres Escudero', 'area' => 'JSAI')
    )
);
