/**
 * Módulo de Integración con Salesforce REST API
 * Permite tanto actualizar un Caso existente por case_id como crear un nuevo Caso.
 */

const SalesforceAPI = (function () {

  /**
   * Envía las respuestas de la encuesta a Salesforce
   * @param {Object} data Objeto con las respuestas y el id del caso
   * @returns {Promise<Object>}
   */
  async function submitSurvey(data) {
    const config = typeof SALESFORCE_CONFIG !== 'undefined' ? SALESFORCE_CONFIG : {};
    const mode = config.mode || 'HYBRID';

    const payload = {
      caseId: data.caseId || null,
      status: config.targetStatus || "Resuelto",
      responses: {
        solicitudAtendida: data.solicitudAtendida,
        necesidadResuelta: data.necesidadResuelta,
        satisfaccionAtencion: data.satisfaccionAtencion,
        calificacionDebora: data.calificacionDebora,
        comentariosMejora: data.comentariosMejora,
        indicadorNPS: data.indicadorNPS
      },
      // Nombres API de campos en Salesforce para Apex/REST API
      fieldMappings: config.fields || {}
    };

    console.log("Enviando payload a Salesforce:", payload);

    // Si tenemos un caseId y estamos en modo HYBRID o REST_API -> Actualizar caso existente
    if (payload.caseId && (mode === 'HYBRID' || mode === 'REST_API')) {
      return await updateExistingCase(payload, config);
    } 
    // Si no hay caseId o está configurado modo WEB_TO_CASE -> Crear caso o fallback
    else {
      return await createNewCaseWebToCase(data, config);
    }
  }

  /**
   * Envía la actualización del Caso existente al servicio Apex REST / Salesforce Site Endpoint
   */
  async function updateExistingCase(payload, config) {
    const endpoint = config.apexRestEndpoint;

    if (!endpoint || endpoint.includes("REEMPLAZAR")) {
      console.warn("No se ha configurado un endpoint Apex REST válido en salesforce-config.js. Usando fallback de simulación o envío estándar.");
    }

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
      });

      if (!response.ok) {
        throw new Error(`Error en el servidor de Salesforce: ${response.status} ${response.statusText}`);
      }

      const result = await response.json();
      return { success: true, mode: 'UPDATE', data: result };

    } catch (error) {
      console.error("Falló la actualización vía REST API. Ejecutando fallback...", error);
      
      // Si falla la llamada AJAX (por ejemplo por CORS o si no se ha desplegado el Apex aún),
      // realizamos el envío mediante un formulario dinámico como respaldo de seguridad.
      return submitViaDynamicForm(payload, config);
    }
  }

  /**
   * Envía un nuevo caso a Salesforce vía Web-to-Case tradicional
   */
  async function createNewCaseWebToCase(data, config) {
    const form = document.getElementById('salesforce-survey-form');
    if (form) {
      // Form submit tradicional a Web-to-Case
      form.submit();
      return { success: true, mode: 'CREATE' };
    }
    return { success: false, error: "Formulario no encontrado" };
  }

  /**
   * Envío mediante formulario dinámico (fallback de respaldo)
   */
  function submitViaDynamicForm(payload, config) {
    return new Promise((resolve) => {
      const form = document.getElementById('salesforce-survey-form');
      if (form) {
        form.action = config.endpoint || form.action;
        form.submit();
        resolve({ success: true, mode: 'FALLBACK_FORM' });
      } else {
        resolve({ success: false, error: "Formulario no encontrado" });
      }
    });
  }

  return {
    submitSurvey: submitSurvey
  };

})();
