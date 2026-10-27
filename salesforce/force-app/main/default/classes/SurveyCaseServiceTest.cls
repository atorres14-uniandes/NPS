@IsTest
private class SurveyCaseServiceTest {

    private static Map<String, Object> validResponses() {
        return new Map<String, Object>{
            'solicitudAtendida' => 'Sí, completamente',
            'necesidadResuelta' => 'Sí',
            'satisfaccionAtencion' => 'Muy satisfecho',
            'calificacionDebora' => '5',
            'comentariosMejora' => 'Todo bien',
            'indicadorNPS' => '9'
        };
    }

    private static Map<String, Object> basePayload() {
        return new Map<String, Object>{
            'submissionId' => UUID.randomUUID().toString(),
            'owner' => UserInfo.getUserId(),
            'responses' => validResponses()
        };
    }

    private static SurveyCaseService.ResponseWrapper call(Object payload) {
        RestRequest request = new RestRequest();
        request.requestURI = '/services/apexrest/SurveyCaseServiceV2/';
        request.httpMethod = 'POST';
        if (payload != null) {
            request.requestBody = Blob.valueOf(payload instanceof String ? (String) payload : JSON.serialize(payload));
        }
        RestContext.request = request;
        RestContext.response = new RestResponse();
        return SurveyCaseService.handleSurveySubmission();
    }

    private static Contact newContact(String documento) {
        Contact contactRecord = new Contact(LastName = 'Encuesta Test');
        if (documento != null) {
            Schema.DescribeFieldResult fieldDescribe = documentField();
            Schema.DisplayType fieldType = fieldDescribe.getType();
            Boolean isNumber = fieldType == Schema.DisplayType.DOUBLE
                || fieldType == Schema.DisplayType.INTEGER
                || fieldType == Schema.DisplayType.LONG;
            contactRecord.put(fieldDescribe.getName(), isNumber ? (Object) Decimal.valueOf(documento) : (Object) documento);
        }
        return contactRecord;
    }

    private static void insertAllowingDuplicates(List<SObject> records) {
        Database.DMLOptions options = new Database.DMLOptions();
        options.DuplicateRuleHeader.allowSave = true;
        Database.insert(records, options);
    }

    private static Schema.DescribeFieldResult documentField() {
        Schema.SObjectField field = Schema.SObjectType.Contact.fields.getMap().get(SurveyCaseService.DOCUMENT_FIELD);
        Assert.isNotNull(field, 'Configure SurveyCaseService.DOCUMENT_FIELD con el API Name real del documento en Contact.');
        return field.getDescribe();
    }

    private static List<NPS__c> surveys() {
        return [
            SELECT Id, Case__c, Contact__c, OwnerId, Atendido__c, Resuelto__c, Satisfaccion__c,
                Calificacion__c, Comentarios__c, Recomendacion__c, Id_Envio__c
            FROM NPS__c
        ];
    }

    @IsTest
    static void documentFieldIsConfigured() {
        Assert.isTrue(documentField().isFilterable(), 'El campo de documento debe poder usarse en filtros SOQL.');
    }

    @IsTest
    static void caseSurveyUsesCaseContact() {
        Contact contactRecord = newContact(null);
        insertAllowingDuplicates(new List<SObject>{ contactRecord });
        Case caseRecord = new Case(Subject = 'Encuesta Test', ContactId = contactRecord.Id);
        insert caseRecord;

        Map<String, Object> payload = basePayload();
        payload.put('caseId', String.valueOf(caseRecord.Id));

        Test.startTest();
        SurveyCaseService.ResponseWrapper response = call(payload);
        Test.stopTest();

        Assert.isTrue(response.success);
        Assert.areEqual('CREATED', response.code);
        Assert.areEqual(201, RestContext.response.statusCode);

        List<NPS__c> records = surveys();
        Assert.areEqual(1, records.size());
        Assert.areEqual(caseRecord.Id, records[0].Case__c);
        Assert.areEqual(contactRecord.Id, records[0].Contact__c);
        Assert.areEqual(UserInfo.getUserId(), records[0].OwnerId);
        Assert.areEqual('Sí, completamente', records[0].Atendido__c);
        Assert.areEqual('5', records[0].Calificacion__c);
        Assert.areEqual(9, records[0].Recomendacion__c);
        Assert.areEqual(payload.get('submissionId'), records[0].Id_Envio__c);
    }

    @IsTest
    static void caseSurveyWithoutContactIsSaved() {
        Case caseRecord = new Case(Subject = 'Encuesta Test');
        insert caseRecord;

        Map<String, Object> payload = basePayload();
        payload.put('caseId', String.valueOf(caseRecord.Id));

        SurveyCaseService.ResponseWrapper response = call(payload);

        Assert.isTrue(response.success);
        List<NPS__c> records = surveys();
        Assert.areEqual(1, records.size());
        Assert.areEqual(caseRecord.Id, records[0].Case__c);
        Assert.isNull(records[0].Contact__c);
    }

    @IsTest
    static void contactIdFromClientIsIgnored() {
        Contact caseContact = newContact(null);
        Contact otherContact = newContact(null);
        insertAllowingDuplicates(new List<SObject>{ caseContact, otherContact });
        Case caseRecord = new Case(Subject = 'Encuesta Test', ContactId = caseContact.Id);
        insert caseRecord;

        Map<String, Object> payload = basePayload();
        payload.put('caseId', String.valueOf(caseRecord.Id));
        payload.put('contactId', String.valueOf(otherContact.Id));

        SurveyCaseService.ResponseWrapper response = call(payload);

        Assert.isTrue(response.success);
        Assert.areEqual(caseContact.Id, surveys()[0].Contact__c);
    }

    @IsTest
    static void secondSurveyForSameCaseIsRejected() {
        Case caseRecord = new Case(Subject = 'Encuesta Test');
        insert caseRecord;

        Map<String, Object> first = basePayload();
        first.put('caseId', String.valueOf(caseRecord.Id));
        call(first);

        Map<String, Object> second = basePayload();
        second.put('caseId', String.valueOf(caseRecord.Id));
        Map<String, Object> changedResponses = validResponses();
        changedResponses.put('indicadorNPS', '0');
        second.put('responses', changedResponses);

        SurveyCaseService.ResponseWrapper response = call(second);

        Assert.isFalse(response.success);
        Assert.areEqual('ALREADY_ANSWERED', response.code);
        Assert.areEqual(409, RestContext.response.statusCode);
        List<NPS__c> records = surveys();
        Assert.areEqual(1, records.size());
        Assert.areEqual(9, records[0].Recomendacion__c, 'La primera respuesta no debe sobrescribirse.');
    }

    @IsTest
    static void retriedSubmissionIsIdempotent() {
        Map<String, Object> payload = basePayload();
        payload.put('documento', '99999999');

        SurveyCaseService.ResponseWrapper first = call(payload);
        SurveyCaseService.ResponseWrapper retry = call(payload);

        Assert.areEqual('CREATED', first.code);
        Assert.isTrue(retry.success);
        Assert.areEqual('ALREADY_RECEIVED', retry.code);
        Assert.areEqual(200, RestContext.response.statusCode);
        Assert.areEqual(1, surveys().size());
    }

    @IsTest
    static void caseNumberIsRejected() {
        Case caseRecord = new Case(Subject = 'Encuesta Test');
        insert caseRecord;
        String caseNumber = [SELECT CaseNumber FROM Case WHERE Id = :caseRecord.Id].CaseNumber;

        Map<String, Object> payload = basePayload();
        payload.put('caseId', caseNumber);

        SurveyCaseService.ResponseWrapper response = call(payload);

        Assert.isFalse(response.success);
        Assert.areEqual('INVALID_CASE', response.code);
        Assert.areEqual(400, RestContext.response.statusCode);
        Assert.areEqual(0, surveys().size());
    }

    @IsTest
    static void fifteenCharacterAndForeignIdsAreRejected() {
        Case caseRecord = new Case(Subject = 'Encuesta Test');
        insert caseRecord;

        Map<String, Object> shortId = basePayload();
        shortId.put('caseId', String.valueOf(caseRecord.Id).left(15));
        Assert.areEqual('INVALID_CASE', call(shortId).code);

        Map<String, Object> userId = basePayload();
        userId.put('caseId', UserInfo.getUserId());
        Assert.areEqual('INVALID_CASE', call(userId).code);

        Assert.areEqual(0, surveys().size());
    }

    @IsTest
    static void presentialSurveyLinksUniqueContact() {
        Contact contactRecord = newContact('1020304050');
        insertAllowingDuplicates(new List<SObject>{ contactRecord });

        Map<String, Object> payload = basePayload();
        payload.put('documento', ' 1.020.304-050 ');

        SurveyCaseService.ResponseWrapper response = call(payload);

        Assert.isTrue(response.success);
        List<NPS__c> records = surveys();
        Assert.areEqual(contactRecord.Id, records[0].Contact__c);
        Assert.isNull(records[0].Case__c);
    }

    @IsTest
    static void presentialSurveyWithUnknownDocumentHasNoContact() {
        Map<String, Object> payload = basePayload();
        payload.put('documento', '5555555555');

        SurveyCaseService.ResponseWrapper response = call(payload);

        Assert.isTrue(response.success);
        List<NPS__c> records = surveys();
        Assert.areEqual(1, records.size());
        Assert.isNull(records[0].Contact__c);
        Assert.isNull(records[0].Case__c);
    }

    @IsTest
    static void presentialSurveyWithAmbiguousDocumentHasNoContact() {
        if (documentField().isUnique()) {
            // Un campo único no admite dos contactos con el mismo documento.
            return;
        }
        insertAllowingDuplicates(new List<SObject>{ newContact('7777777'), newContact('7777777') });

        Map<String, Object> payload = basePayload();
        payload.put('documento', '7777777');

        SurveyCaseService.ResponseWrapper response = call(payload);

        Assert.isTrue(response.success);
        Assert.isNull(surveys()[0].Contact__c);
    }

    @IsTest
    static void presentialSurveyRequiresValidDocument() {
        for (String documento : new List<String>{ null, '', '12', 'ABC12345', '1234567890123456' }) {
            Map<String, Object> payload = basePayload();
            payload.put('documento', documento);
            SurveyCaseService.ResponseWrapper response = call(payload);
            Assert.isFalse(response.success, 'Documento aceptado: ' + documento);
            Assert.areEqual(400, RestContext.response.statusCode);
        }
        Assert.areEqual(0, surveys().size());
    }

    @IsTest
    static void invalidOwnerFallsBackToDefaultOwner() {
        Map<String, Object> payload = basePayload();
        payload.put('documento', '12345678');
        payload.put('owner', '00G000000000000AAA');

        SurveyCaseService.ResponseWrapper response = call(payload);

        Assert.isTrue(response.success);
        Assert.areEqual(UserInfo.getUserId(), surveys()[0].OwnerId);
    }

    @IsTest
    static void invalidRecommendationsAreRejected() {
        for (String value : new List<String>{ null, '', '-1', '11', '5.5', 'abc', '100' }) {
            Map<String, Object> payload = basePayload();
            payload.put('documento', '12345678');
            Map<String, Object> responses = validResponses();
            responses.put('indicadorNPS', value);
            payload.put('responses', responses);

            SurveyCaseService.ResponseWrapper response = call(payload);

            Assert.isFalse(response.success, 'Valor aceptado: ' + value);
            Assert.areEqual('INVALID_REQUEST', response.code);
        }
        Assert.areEqual(0, surveys().size());
    }

    @IsTest
    static void unknownOptionsAreRejected() {
        Map<String, String> invalidValues = new Map<String, String>{
            'solicitudAtendida' => 'Tal vez',
            'necesidadResuelta' => 'si',
            'satisfaccionAtencion' => '',
            'calificacionDebora' => '6'
        };
        for (String key : invalidValues.keySet()) {
            Map<String, Object> payload = basePayload();
            payload.put('documento', '12345678');
            Map<String, Object> responses = validResponses();
            responses.put(key, invalidValues.get(key));
            payload.put('responses', responses);

            Assert.isFalse(call(payload).success, 'Opción aceptada en ' + key);
        }
        Assert.areEqual(0, surveys().size());
    }

    @IsTest
    static void commentIsOptionalButLimited() {
        Map<String, Object> withoutComment = basePayload();
        withoutComment.put('documento', '12345678');
        Map<String, Object> responses = validResponses();
        responses.remove('comentariosMejora');
        withoutComment.put('responses', responses);
        Assert.isTrue(call(withoutComment).success);

        Integer maxLength = Schema.SObjectType.NPS__c.fields.Comentarios__c.getLength();
        Map<String, Object> tooLong = basePayload();
        tooLong.put('documento', '12345678');
        Map<String, Object> longResponses = validResponses();
        longResponses.put('comentariosMejora', 'a'.repeat(maxLength + 1));
        tooLong.put('responses', longResponses);
        Assert.isFalse(call(tooLong).success);

        Assert.areEqual(1, surveys().size());
    }

    @IsTest
    static void malformedRequestsAreRejectedWithoutDetails() {
        List<Object> bodies = new List<Object>{ null, '', '{no es json', 'null', '[]' };
        for (Object body : bodies) {
            SurveyCaseService.ResponseWrapper response = call(body);
            Assert.isFalse(response.success);
            Assert.areEqual(400, RestContext.response.statusCode);
            Assert.areEqual('La solicitud contiene datos no válidos.', response.message);
        }

        Map<String, Object> badSubmission = basePayload();
        badSubmission.put('documento', '12345678');
        badSubmission.put('submissionId', 'abc');
        Assert.areEqual('INVALID_REQUEST', call(badSubmission).code);

        Map<String, Object> noResponses = basePayload();
        noResponses.put('documento', '12345678');
        noResponses.remove('responses');
        Assert.areEqual('INVALID_REQUEST', call(noResponses).code);
    }

    @IsTest
    static void normalizeDocumentRemovesSeparators() {
        Assert.areEqual('1020304050', SurveyCaseService.normalizeDocument(' 1.020 304-050 '));
        Assert.areEqual('', SurveyCaseService.normalizeDocument(null));
    }
}
