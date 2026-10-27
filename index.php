<?php

/**
 * Vista Principal de Encuesta.
 *
 * Parámetros de URL admitidos:
 *   case_id  Id de 18 caracteres del Caso (encuestas enviadas por correo). Sin él se pide el documento.
 *   owner    Id del usuario de Salesforce que prestó el servicio. Sin él se muestra la lista de asesores.
 *   area     Área del servicio (debe existir en config.php).
 *   evento   Nombre del evento (solo informativo por ahora).
 *   tipo     Nombre técnico del tipo de registro de NPS__c (p.ej. Evento, Experiencia). Lo valida Apex.
 *
 * Estos valores se guardan en la sesión del servidor; el formulario solo envía un identificador opaco.
 */

require __DIR__ . '/survey_common.php';
$config = require __DIR__ . '/config.php';
$agents_catalog = isset($config['agents']) ? $config['agents'] : array();
$areas = isset($config['areas']) ? $config['areas'] : array();

survey_start_session();

$invalid_link = false;
$form_id = isset($_GET['form']) ? (string) $_GET['form'] : '';
$context = survey_get_form($form_id);

// Si vuelve por un error de validación se reutiliza el mismo formulario y su contexto.
if ($context === null || $context['submitted']) {
  $case_id = isset($_GET['case_id']) ? trim((string) $_GET['case_id']) : '';
  if ($case_id !== '' && !survey_is_salesforce_id($case_id, '500', true)) {
    $invalid_link = true;
  }

  $owner = isset($_GET['owner']) ? trim((string) $_GET['owner']) : '';
  if (!survey_is_salesforce_id($owner, '005')) {
    $owner = '';
  }

  $area = isset($_GET['area']) ? survey_resolve_area($_GET['area'], $areas) : '';
  if ($area === '' && $owner !== '' && isset($agents_catalog[$owner])) {
    $area = $agents_catalog[$owner]['area'];
  }

  $context = array(
    'case_id' => $case_id,
    'owner' => $owner,
    'area' => $area !== '' ? $area : 'Alumni',
    'evento' => isset($_GET['evento']) ? survey_clean_text($_GET['evento'], 100) : '',
    'record_type' => (isset($_GET['tipo']) && is_string($_GET['tipo']) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/', $_GET['tipo']))
      ? $_GET['tipo']
      : ''
  );

  $form_id = $invalid_link ? '' : survey_register_form($context);
  if (!$invalid_link) {
    $context = survey_get_form($form_id);
  }
}

$area = $context['area'];
$evento = $context['evento'];
$owner_display_name = ($context['owner'] !== '' && isset($agents_catalog[$context['owner']]))
  ? $agents_catalog[$context['owner']]['name']
  : '';

// Con caso no se pide documento: el contacto lo toma Salesforce del caso.
$need_identification = $context['case_id'] === '';
$need_agent_selection = $context['owner'] === '';
$show_step1 = ($need_identification || $need_agent_selection);

$total_steps = $show_step1 ? 3 : 2;
$has_error = isset($_GET['error']) && $_GET['error'] === 'campos_incompletos';
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Encuesta de Satisfacción</title>
  <meta name="description" content="Breve encuesta de satisfacción de servicio para egresados.">
  <link rel="stylesheet" href="css/styles.css?v=1.3">
</head>

<body>

  <!-- Ambient background glow elements -->
  <div class="bg-orb bg-orb-1"></div>
  <div class="bg-orb bg-orb-2"></div>

  <main class="survey-wrapper">

    <!-- Encabezado de la Encuesta -->
    <header class="survey-header">
      <div class="brand-badge">
        <img src="images/Logo Uniandes 02.png" alt="Logo Uniandes" class="brand-logo">
      </div>
      <h1>Tu opinión nos importa</h1>
      <p>Solo te tomará un minuto responder estas preguntas.</p>

      <?php if (!empty($evento)): ?>
        <div class="case-info-bar" style="background: #eef2ff; color: #4f46e5; margin-left: 0.25rem;">
          <span>Evento:</span> <strong><?php echo htmlspecialchars($evento, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
      <?php endif; ?>
    </header>

    <?php if ($invalid_link): ?>
      <div class="error-banner active">
        <span>El enlace de la encuesta no es válido. Verifica que lo hayas copiado completo.</span>
      </div>
    <?php else: ?>

    <!-- Barra de Progreso -->
    <div class="progress-container">
      <div class="progress-info">
        <span id="step-category-title">
          <?php echo $show_step1 ? 'Datos iniciales' : 'Evaluación del servicio'; ?>
        </span>
        <span>Paso <span id="current-step-num">1</span> de <?php echo $total_steps; ?></span>
      </div>
      <div class="progress-bar-track">
        <div class="progress-bar-fill" id="progress-bar-fill" style="width: <?php echo round(100 / $total_steps); ?>%;"></div>
      </div>
    </div>

    <!-- Formulario Principal -->
    <form id="survey-form" method="POST" action="process_survey.php" class="survey-form">

      <!-- El contexto (caso, asesor, área) queda en la sesión del servidor -->
      <input type="hidden" name="form_id" value="<?php echo htmlspecialchars($form_id, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($context['csrf'], ENT_QUOTES, 'UTF-8'); ?>">

      <!-- Banner de Errores de Validación -->
      <div class="error-banner <?php echo $has_error ? 'active' : ''; ?>" id="error-banner">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
        </svg>
        <span id="error-message">Por favor completa los campos requeridos para continuar.</span>
      </div>

      <?php if ($show_step1): ?>
        <!-- ==========================================
           PASO 1: Datos Iniciales
           ========================================== -->
        <div class="step-panel active" data-step="1" id="step-initial">
          <div class="step-header">
            <span class="step-category">Paso 1</span>
            <h2 class="step-title">Información de la atención</h2>
          </div>

          <?php if ($need_identification): ?>
            <!-- Campo Identificación Persona -->
            <div class="question-group">
              <label class="question-label" for="field_documento">
                Número de documento de identificación
                <span style="color: #ef4444;">*</span>
              </label>
              <input
                type="text"
                id="field_documento"
                name="documento"
                class="custom-textarea"
                style="min-height: 48px; height: 48px; resize: none;"
                inputmode="numeric"
                autocomplete="off"
                maxlength="20"
                placeholder="Ingresa tu número de documento">
            </div>
          <?php endif; ?>

          <?php if ($need_agent_selection): ?>
            <!-- Selección de Asesor como Lista Desplegable (Select) -->
            <div class="question-group">
              <label class="question-label" for="select_agent">
                ¿Quién te atendió? <span style="color: #ef4444;">*</span>
              </label>
              <select
                id="select_agent"
                name="owner"
                class="custom-textarea"
                style="min-height: 50px; height: 50px; padding: 0.75rem 1rem; cursor: pointer; font-size: 0.95rem; font-weight: 600;">
                <option value="">-- Selecciona la persona que te atendió --</option>
                <?php foreach ($agents_catalog as $agent_id => $info): ?>
                  <option
                    value="<?php echo htmlspecialchars($agent_id, ENT_QUOTES, 'UTF-8'); ?>"
                    data-name="<?php echo htmlspecialchars($info['name'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-area="<?php echo htmlspecialchars($info['area'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($info['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($info['area'], ENT_QUOTES, 'UTF-8'); ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

        </div>
      <?php endif; ?>

      <!-- ==========================================
           PASO DE EVALUACIÓN: Solicitud, Área y Persona
           ========================================== -->
      <div class="step-panel <?php echo !$show_step1 ? 'active' : ''; ?>" data-step="<?php echo $show_step1 ? '2' : '1'; ?>" id="step-eval">
        <div class="step-header">
          <span class="step-category">Evaluación</span>
          <h2 class="step-title">Atención recibida</h2>
        </div>

        <!-- Pregunta 1: Atendido -->
        <div class="question-group">
          <label class="question-label">
            <span class="question-number">1.</span> ¿Tu solicitud o caso fue atendido?
          </label>
          <div class="options-grid" style="grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
            <label class="option-card" style="padding: 0.75rem 0.4rem; justify-content: center; text-align: center;">
              <input type="radio" data-question="q_atendido" name="q_atendido" value="Sí, completamente">
              <span class="option-text" style="font-size: 0.85rem;">Sí, completamente</span>
            </label>
            <label class="option-card" style="padding: 0.75rem 0.4rem; justify-content: center; text-align: center;">
              <input type="radio" data-question="q_atendido" name="q_atendido" value="Parcialmente">
              <span class="option-text" style="font-size: 0.85rem;">Parcialmente</span>
            </label>
            <label class="option-card" style="padding: 0.75rem 0.4rem; justify-content: center; text-align: center;">
              <input type="radio" data-question="q_atendido" name="q_atendido" value="No">
              <span class="option-text" style="font-size: 0.85rem;">No</span>
            </label>
          </div>
        </div>

        <!-- Pregunta 2: Resuelta -->
        <div class="question-group">
          <label class="question-label">
            <span class="question-number">2.</span> ¿Tu necesidad quedó resuelta?
          </label>
          <div class="options-grid" style="grid-template-columns: repeat(3, 1fr); gap: 0.5rem;">
            <label class="option-card" style="padding: 0.75rem 0.4rem; justify-content: center; text-align: center;">
              <input type="radio" data-question="q_resuelta" name="q_resuelta" value="Sí">
              <span class="option-text">Sí</span>
            </label>
            <label class="option-card" style="padding: 0.75rem 0.4rem; justify-content: center; text-align: center;">
              <input type="radio" data-question="q_resuelta" name="q_resuelta" value="Parcialmente">
              <span class="option-text">Parcialmente</span>
            </label>
            <label class="option-card" style="padding: 0.75rem 0.4rem; justify-content: center; text-align: center;">
              <input type="radio" data-question="q_resuelta" name="q_resuelta" value="No">
              <span class="option-text">No</span>
            </label>
          </div>
        </div>

        <!-- Pregunta 3: Adaptada al Área -->
        <div class="question-group">
          <label class="question-label">
            <span class="question-number">3.</span> ¿Qué tan satisfecho(a) te encuentras con la atención recibida por el área de <strong id="display-area-name"><?php echo htmlspecialchars($area, ENT_QUOTES, 'UTF-8'); ?></strong>?
          </label>
          <div class="options-grid">
            <label class="option-card">
              <input type="radio" data-question="q_satisfaccion" name="q_satisfaccion" value="Muy satisfecho">
              <span class="custom-radio"></span>
              <span class="option-text">Muy satisfecho</span>
            </label>
            <label class="option-card">
              <input type="radio" data-question="q_satisfaccion" name="q_satisfaccion" value="Satisfecho">
              <span class="custom-radio"></span>
              <span class="option-text">Satisfecho</span>
            </label>
            <label class="option-card">
              <input type="radio" data-question="q_satisfaccion" name="q_satisfaccion" value="Neutral">
              <span class="custom-radio"></span>
              <span class="option-text">Neutral</span>
            </label>
            <label class="option-card">
              <input type="radio" data-question="q_satisfaccion" name="q_satisfaccion" value="Insatisfecho">
              <span class="custom-radio"></span>
              <span class="option-text">Insatisfecho</span>
            </label>
            <label class="option-card">
              <input type="radio" data-question="q_satisfaccion" name="q_satisfaccion" value="Muy insatisfecho">
              <span class="custom-radio"></span>
              <span class="option-text">Muy insatisfecho</span>
            </label>
          </div>
        </div>

        <!-- Pregunta 4: Adaptada al Nombre de la Persona que Atendió -->
        <div class="question-group">
          <label class="question-label">
            <span class="question-number">4.</span> Califica la atención recibida de <strong id="display-owner-name"><?php echo !empty($owner_display_name) ? htmlspecialchars($owner_display_name, ENT_QUOTES, 'UTF-8') : 'la persona que te atendió'; ?></strong> en una escala de 1 a 5
          </label>
          <div class="rating-scale-5">
            <label class="rating-card-5">
              <input type="radio" data-question="q_debora" name="q_debora" value="1">
              <span class="rating-num">1</span>
              <span class="rating-desc">Muy mala</span>
            </label>
            <label class="rating-card-5">
              <input type="radio" data-question="q_debora" name="q_debora" value="2">
              <span class="rating-num">2</span>
              <span class="rating-desc">Mala</span>
            </label>
            <label class="rating-card-5">
              <input type="radio" data-question="q_debora" name="q_debora" value="3">
              <span class="rating-num">3</span>
              <span class="rating-desc">Regular</span>
            </label>
            <label class="rating-card-5">
              <input type="radio" data-question="q_debora" name="q_debora" value="4">
              <span class="rating-num">4</span>
              <span class="rating-desc">Buena</span>
            </label>
            <label class="rating-card-5">
              <input type="radio" data-question="q_debora" name="q_debora" value="5">
              <span class="rating-num">5</span>
              <span class="rating-desc">Excelente</span>
            </label>
          </div>
        </div>
      </div>

      <!-- ==========================================
           PASO DE RECOMENDACIÓN Y COMENTARIO FINAL
           ========================================== -->
      <div class="step-panel" data-step="<?php echo $total_steps; ?>" id="step-nps">
        <div class="step-header">
          <span class="step-category">Recomendación</span>
          <h2 class="step-title">Tu experiencia general</h2>
        </div>

        <!-- Pregunta NPS -->
        <div class="question-group">
          <label class="question-label">
            ¿Qué probabilidad hay de que recomiendes nuestro servicio a otro egresado?
          </label>

          <div class="nps-container">
            <div class="nps-grid">
              <?php for ($i = 0; $i <= 10; $i++): ?>
                <label class="nps-btn" data-nps="<?php echo $i; ?>">
                  <input type="radio" data-question="q_nps" name="q_nps" value="<?php echo $i; ?>">
                  <?php echo $i; ?>
                </label>
              <?php endfor; ?>
            </div>

            <div class="nps-legend">
              <span>0 = Nada probable</span>
              <span>10 = Muy probable</span>
            </div>
          </div>
        </div>

        <!-- Pregunta de Comentario de Mejora Opcional -->
        <div class="question-group">
          <label class="question-label" for="q_mejoras">
            ¿Qué podríamos mejorar en nuestra atención? <span style="font-weight: normal; color: #64748b;">(Opcional)</span>
          </label>
          <textarea
            id="q_mejoras"
            name="q_mejoras"
            class="custom-textarea"
            placeholder="Escribe tus sugerencias o comentarios..."
            maxlength="<?php echo SURVEY_COMMENT_MAX_LENGTH; ?>"
            rows="4"></textarea>
        </div>
      </div>

      <!-- Botones de Navegación -->
      <div class="form-actions">
        <button type="button" id="btn-prev" class="btn btn-primary" style="visibility: hidden;">
          <svg viewBox="0 0 24 24">
            <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" />
          </svg>
          Anterior
        </button>

        <button type="button" id="btn-next" class="btn btn-primary">
          Siguiente
          <svg viewBox="0 0 24 24">
            <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" />
          </svg>
        </button>

        <button type="submit" id="btn-submit" class="btn btn-primary" style="display: none;">
          Enviar Encuesta
          <svg viewBox="0 0 24 24">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
          </svg>
        </button>
      </div>

    </form>
    <?php endif; ?>

    <footer class="survey-footer">
      <p></p>
    </footer>

  </main>

  <?php if (!$invalid_link): ?>
    <script>
      window.SHOW_STEP1 = <?php echo $show_step1 ? 'true' : 'false'; ?>;
      window.TOTAL_STEPS = <?php echo $total_steps; ?>;
    </script>

    <script src="js/app.js?v=1.3"></script>
  <?php endif; ?>
</body>

</html>
