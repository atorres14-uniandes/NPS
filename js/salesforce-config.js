/**
 * Configuración de Integración Salesforce (Actualizar y Crear Casos)
 * 
 * Modifique los valores de este archivo para conectar la encuesta con su Salesforce.
 */
const SALESFORCE_CONFIG = {
  // Modo de integración:
  // "HYBRID": Si recibe case_id en URL actualiza el caso existente vía REST API; si no, crea uno nuevo.
  // "REST_API": Siempre envía peticiones JSON a Apex REST / Salesforce Site Endpoint.
  // "WEB_TO_CASE": Envío estándar de formulario Web-to-Case (solo creación).
  mode: "HYBRID",

  // ID de la Organización de Salesforce (Org ID)
  orgId: "00D1I000001UaEj",

  // URL del endpoint Apex REST / Salesforce Site para ACTUALIZAR casos existentes
  // Ejemplo: "https://miuniversidad.my.salesforce-sites.com/services/apexrest/SurveyCaseService"
  apexRestEndpoint: "https://REEMPLAZAR_CON_TU_SITIO_SALESFORCE.my.salesforce-sites.com/services/apexrest/SurveyCaseService",

  // URL del servlet Web-to-Case estándar para CREAR nuevos casos (si no existe case_id)
  endpoint: "https://webto.salesforce.com/servlet/servlet.WebToCase?encoding=UTF-8",

  // URL de redirección tras completar la encuesta (Página de Agradecimiento)
  retURL: window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')) + '/gracias.html',

  // Estado al que pasará el caso una vez respondida la encuesta
  targetStatus: "Resuelto",

  // Mapeo de API Names / IDs de campos personalizados de Salesforce (Custom Fields)
  fields: {
    // 1.1 ¿Su solicitud o caso fue atendido? (Sí, completamente | Parcialmente | No)
    solicitudAtendida: "Solicitud_Atendida__c",

    // 1.2 ¿Su necesidad quedó resuelta? (Sí | Parcialmente | No)
    necesidadResuelta: "Necesidad_Resuelta__c",

    // 2.1 ¿Qué tan satisfecho(a) se encuentra con la atención recibida por el área de Atención a Egresados?
    satisfaccionAtencion: "Satisfaccion_Atencion__c",

    // 2.2 Califique la atención recibida de Débora en una escala de 1 a 5
    calificacionDebora: "Calificacion_Debora__c",

    // 3.1 ¿Qué podríamos mejorar en nuestra atención? (Texto abierto)
    comentariosMejora: "Description",

    // 4.1 Indicador NPS (Escala de 0 a 10)
    indicadorNPS: "Indicador_NPS__c"
  }
};
