/**
 * Servicio Apex REST que registra respuestas de encuesta en NPS__c.
 *
 * Canal correo (caso): llega caseId. El contacto se toma de Case.ContactId,
 * nunca del cliente. Solo se admite una encuesta por caso.
 * Canal presencial (QR): no llega caseId. Llega el documento y se busca el
 * contacto; si no hay exactamente una coincidencia se guarda sin contacto.
 *
 * Nunca se modifica un registro existente. submissionId (Id_Envio__c) evita
 * duplicados por reintentos o doble clic.
 */
@RestResource(urlMapping='/SurveyCaseServiceV2/*')
global with sharing class SurveyCaseService {

    // API Name del campo de Contact que almacena el número de documento.
    @TestVisible
    private static final String DOCUMENT_FIELD = 'N_mero_de_Identificaci_n__c';

    private static final Set<String> ATENDIDO_VALUES = new Set<String>{
        'Sí, completamente', 'Parcialmente', 'No'
    };
    private static final Set<String> RESUELTO_VALUES = new Set<String>{
        'Sí', 'Parcialmente', 'No'
    };
    private static final Set<String> SATISFACCION_VALUES = new Set<String>{
        'Muy satisfecho', 'Satisfecho', 'Neutral', 'Insatisfecho', 'Muy insatisfecho'
    };
    private static final Set<String> CALIFICACION_VALUES = new Set<String>{
        '1', '2', '3', '4', '5'
    };

    private static final Pattern NPS_PATTERN = Pattern.compile('^(10|[0-9])$');
    private static final Pattern SUBMISSION_ID_PATTERN = Pattern.compile(
        '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
    );
    private static final Pattern DOCUMENT_PATTERN = Pattern.compile('^[0-9]{4,15}$');
    private static final Pattern ID_PATTERN = Pattern.compile('^[a-zA-Z0-9]{15}([a-zA-Z0-9]{3})?$');

    private static final String MSG_INVALID = 'La solicitud contiene datos no válidos.';
    private static final String MSG_INVALID_LINK = 'El enlace de la encuesta no es válido.';
    private static final String MSG_ANSWERED = 'Esta encuesta ya fue respondida.';
    private static final String MSG_CREATED = 'Encuesta registrada.';
    private static final String MSG_RECEIVED = 'La encuesta ya había sido recibida.';
    private static final String MSG_INTERNAL = 'No fue posible registrar la encuesta.';

    @HttpPost
    global static ResponseWrapper handleSurveySubmission() {
        try {
            SurveyPayload payload = parsePayload(RestContext.request);

            String submissionId = payload.submissionId == null ? '' : payload.submissionId.trim().toLowerCase();
            if (!SUBMISSION_ID_PATTERN.matcher(submissionId).matches()) {
                throw invalid(400, 'INVALID_REQUEST', MSG_INVALID, 'submissionId');
            }

            NPS__c npsRecord = buildRecord(payload.responses);
            npsRecord.Id_Envio__c = submissionId;

            // Un reintento de un envío ya guardado se responde como éxito sin crear nada.
            if (submissionExists(submissionId)) {
                return respond(200, true, 'ALREADY_RECEIVED', MSG_RECEIVED);
            }

            if (String.isNotBlank(payload.caseId)) {
                Case relatedCase = findCase(payload.caseId);
                if (caseAlreadyAnswered(relatedCase.Id)) {
                    throw invalid(409, 'ALREADY_ANSWERED', MSG_ANSWERED, 'caseId');
                }
                npsRecord.Case__c = relatedCase.Id;
                npsRecord.Contact__c = relatedCase.ContactId;
            } else {
                String documento = normalizeDocument(payload.documento);
                if (!DOCUMENT_PATTERN.matcher(documento).matches()) {
                    throw invalid(400, 'INVALID_REQUEST', MSG_INVALID, 'documento');
                }
                npsRecord.Contact__c = findContactByDocument(documento);
            }

            // Si el asesor no es un usuario activo, el propietario queda por defecto
            // (el usuario de integración) y la encuesta no se pierde.
            Id ownerId = resolveActiveUser(payload.owner);
            if (ownerId != null) {
                npsRecord.OwnerId = ownerId;
            }

            // Sin tipo válido se usa el tipo de registro predeterminado del usuario de integración.
            Id recordTypeId = resolveRecordType(payload.recordType);
            if (recordTypeId != null) {
                npsRecord.RecordTypeId = recordTypeId;
            }

            try {
                insert as user npsRecord;
            } catch (DmlException dmlError) {
                if (dmlError.getDmlType(0) == StatusCode.DUPLICATE_VALUE) {
                    return respond(200, true, 'ALREADY_RECEIVED', MSG_RECEIVED);
                }
                throw dmlError;
            }

            return respond(201, true, 'CREATED', MSG_CREATED);
        } catch (SurveyException surveyError) {
            System.debug(LoggingLevel.WARN, 'SurveyCaseService rechazó la solicitud. Código: '
                + surveyError.code + '. Dato: ' + surveyError.fieldName);
            return respond(surveyError.statusCode, false, surveyError.code, surveyError.getMessage());
        } catch (Exception unexpectedError) {
            System.debug(LoggingLevel.ERROR, 'SurveyCaseService error interno: '
                + unexpectedError.getTypeName() + ' ' + unexpectedError.getMessage()
                + '\n' + unexpectedError.getStackTraceString());
            return respond(500, false, 'INTERNAL_ERROR', MSG_INTERNAL);
        }
    }

    private static SurveyPayload parsePayload(RestRequest request) {
        if (request == null || request.requestBody == null || request.requestBody.size() == 0) {
            throw invalid(400, 'INVALID_REQUEST', MSG_INVALID, 'body');
        }

        SurveyPayload payload;
        try {
            payload = (SurveyPayload) JSON.deserialize(request.requestBody.toString(), SurveyPayload.class);
        } catch (Exception parseError) {
            throw invalid(400, 'INVALID_REQUEST', MSG_INVALID, 'body');
        }

        if (payload == null) {
            throw invalid(400, 'INVALID_REQUEST', MSG_INVALID, 'body');
        }
        return payload;
    }

    private static NPS__c buildRecord(ResponseData responses) {
        if (responses == null) {
            throw invalid(400, 'INVALID_REQUEST', MSG_INVALID, 'responses');
        }

        String recomendacion = responses.indicadorNPS == null ? '' : responses.indicadorNPS.trim();
        if (!NPS_PATTERN.matcher(recomendacion).matches()) {
            throw invalid(400, 'INVALID_REQUEST', MSG_INVALID, 'indicadorNPS');
        }

        String comentarios = responses.comentariosMejora == null ? '' : responses.comentariosMejora.trim();
        if (comentarios.length() > Schema.SObjectType.NPS__c.fields.Comentarios__c.getLength()) {
            throw invalid(400, 'INVALID_REQUEST', MSG_INVALID, 'comentariosMejora');
        }

        return new NPS__c(
            Name = 'Encuesta NPS ' + Datetime.now().format('yyyy-MM-dd HH:mm:ss'),
            Atendido__c = requireOption(responses.solicitudAtendida, ATENDIDO_VALUES, 'solicitudAtendida'),
            Resuelto__c = requireOption(responses.necesidadResuelta, RESUELTO_VALUES, 'necesidadResuelta'),
            Satisfaccion__c = requireOption(responses.satisfaccionAtencion, SATISFACCION_VALUES, 'satisfaccionAtencion'),
            Calificacion__c = requireOption(responses.calificacionDebora, CALIFICACION_VALUES, 'calificacionDebora'),
            Comentarios__c = String.isBlank(comentarios) ? null : comentarios,
            Recomendacion__c = Integer.valueOf(recomendacion)
        );
    }

    private static String requireOption(String value, Set<String> allowedValues, String fieldName) {
        String trimmed = value == null ? '' : value.trim();
        if (!allowedValues.contains(trimmed)) {
            throw invalid(400, 'INVALID_REQUEST', MSG_INVALID, fieldName);
        }
        return trimmed;
    }

    private static Boolean submissionExists(String submissionId) {
        return [
            SELECT COUNT()
            FROM NPS__c
            WHERE Id_Envio__c = :submissionId
            WITH USER_MODE
        ] > 0;
    }

    private static Case findCase(String caseReference) {
        // Solo se acepta el Id de 18 caracteres; CaseNumber es secuencial y fácil de adivinar.
        String reference = caseReference.trim();
        Id caseId = parseId(reference, Case.SObjectType);
        if (caseId == null || reference.length() != 18) {
            throw invalid(400, 'INVALID_CASE', MSG_INVALID_LINK, 'caseId');
        }

        List<Case> cases = [
            SELECT Id, ContactId
            FROM Case
            WHERE Id = :caseId
            WITH USER_MODE
            LIMIT 1
        ];
        if (cases.isEmpty()) {
            throw invalid(400, 'INVALID_CASE', MSG_INVALID_LINK, 'caseId');
        }
        return cases[0];
    }

    private static Boolean caseAlreadyAnswered(Id caseId) {
        return [
            SELECT COUNT()
            FROM NPS__c
            WHERE Case__c = :caseId
            WITH USER_MODE
        ] > 0;
    }

    private static Id resolveActiveUser(String ownerReference) {
        Id userId = parseId(ownerReference, User.SObjectType);
        if (userId == null) {
            return null;
        }

        List<User> users = [
            SELECT Id
            FROM User
            WHERE Id = :userId AND IsActive = true
            WITH USER_MODE
            LIMIT 1
        ];
        return users.isEmpty() ? null : users[0].Id;
    }

    private static Id resolveRecordType(String developerName) {
        if (String.isBlank(developerName)) {
            return null;
        }

        Schema.RecordTypeInfo info = Schema.SObjectType.NPS__c
            .getRecordTypeInfosByDeveloperName()
            .get(developerName.trim());
        if (info == null || !info.isActive() || !info.isAvailable() || info.isMaster()) {
            System.debug(LoggingLevel.WARN, 'SurveyCaseService: tipo de registro no válido: ' + developerName);
            return null;
        }
        return info.getRecordTypeId();
    }

    @TestVisible
    private static String normalizeDocument(String documento) {
        return documento == null ? '' : documento.replaceAll('[\\s.\\-]', '');
    }

    private static Id findContactByDocument(String documento) {
        Schema.SObjectField field = Schema.SObjectType.Contact.fields.getMap().get(DOCUMENT_FIELD);
        if (field == null) {
            System.debug(LoggingLevel.ERROR, 'SurveyCaseService: DOCUMENT_FIELD no existe en Contact.');
            return null;
        }

        Schema.DescribeFieldResult fieldDescribe = field.getDescribe();
        Schema.DisplayType fieldType = fieldDescribe.getType();
        Object documentValue = documento;
        if (fieldType == Schema.DisplayType.DOUBLE
                || fieldType == Schema.DisplayType.INTEGER
                || fieldType == Schema.DisplayType.LONG) {
            documentValue = Decimal.valueOf(documento);
        }

        // LIMIT 2 basta para distinguir una coincidencia única de una ambigua.
        List<Contact> matches = Database.queryWithBinds(
            'SELECT Id FROM Contact WHERE ' + fieldDescribe.getName() + ' = :documentValue LIMIT 2',
            new Map<String, Object>{ 'documentValue' => documentValue },
            AccessLevel.USER_MODE
        );
        return matches.size() == 1 ? matches[0].Id : null;
    }

    private static Id parseId(String value, Schema.SObjectType expectedType) {
        if (String.isBlank(value) || !ID_PATTERN.matcher(value.trim()).matches()) {
            return null;
        }
        try {
            Id parsed = Id.valueOf(value.trim());
            return parsed.getSObjectType() == expectedType ? parsed : null;
        } catch (Exception invalidId) {
            return null;
        }
    }

    private static SurveyException invalid(Integer statusCode, String code, String message, String fieldName) {
        SurveyException error = new SurveyException(message);
        error.statusCode = statusCode;
        error.code = code;
        error.fieldName = fieldName;
        return error;
    }

    private static ResponseWrapper respond(Integer statusCode, Boolean success, String code, String message) {
        if (RestContext.response != null) {
            RestContext.response.statusCode = statusCode;
        }
        ResponseWrapper response = new ResponseWrapper();
        response.success = success;
        response.code = code;
        response.message = message;
        return response;
    }

    private class SurveyException extends Exception {
        public Integer statusCode;
        public String code;
        public String fieldName;
    }

    global class SurveyPayload {
        public String submissionId;
        public String caseId;
        public String documento;
        public String owner;
        public String recordType;
        public ResponseData responses;
    }

    global class ResponseData {
        public String solicitudAtendida;
        public String necesidadResuelta;
        public String satisfaccionAtencion;
        public String calificacionDebora;
        public String comentariosMejora;
        public String indicadorNPS;
    }

    global class ResponseWrapper {
        public Boolean success;
        public String code;
        public String message;
    }
}
