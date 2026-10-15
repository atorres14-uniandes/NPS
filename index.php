<?php

/**
 * Vista Principal de Encuesta (PHP < 8 Compatible)
 * Selección de Asesor mediante Lista Desplegable (Select).
 */

$config = require __DIR__ . '/config.php';
$agents_catalog = isset($config['agents']) ? $config['agents'] : array();

// 1. Extraer Parámetros URL
$case_id = '';
if (isset($_GET['case_id'])) {
  $case_id = trim($_GET['case_id']);
} elseif (isset($_GET['casenumber'])) {
  $case_id = trim($_GET['casenumber']);
} elseif (isset($_GET['id'])) {
  $case_id = trim($_GET['id']);
} elseif (isset($_GET['c'])) {
  $case_id = trim($_GET['c']);
}

$contact_id = '';
if (isset($_GET['contactId'])) {
  $contact_id = trim($_GET['contactId']);
} elseif (isset($_GET['contact_id'])) {
  $contact_id = trim($_GET['contact_id']);
}

$raw_owner = '';
if (isset($_GET['owner'])) {
  $raw_owner = trim($_GET['owner']);
} elseif (isset($_GET['propietario'])) {
  $raw_owner = trim($_GET['propietario']);
} elseif (isset($_GET['owner_id'])) {
  $raw_owner = trim($_GET['owner_id']);
} elseif (isset($_GET['asesor'])) {
  $raw_owner = trim($_GET['asesor']);
}

$owner_display_name = '';
$area = 'Alumni';

// Resolver Owner (soporta Long ID de Salesforce o Nombre)
if (!empty($raw_owner)) {
  if (isset($agents_catalog[$raw_owner])) {
    $owner_display_name = $agents_catalog[$raw_owner]['name'];
    $area = $agents_catalog[$raw_owner]['area'];
  } else {
    $owner_display_name = $raw_owner;
    if (isset($_GET['area'])) {
      $area_param = ucfirst(strtolower(trim($_GET['area'])));
      if (in_array($area_param, array('Alumni', 'Filantropia', 'Filantropía', 'Servicios'))) {
        $area = ($area_param === 'Filantropia') ? 'Filantropía' : $area_param;
      }
    }
  }
}

$evento = '';
if (isset($_GET['evento'])) {
  $evento = trim($_GET['evento']);
} elseif (isset($_GET['event'])) {
  $evento = trim($_GET['event']);
}

$documento = '';
if (isset($_GET['documento'])) {
  $documento = trim($_GET['documento']);
} elseif (isset($_GET['cedula'])) {
  $documento = trim($_GET['cedula']);
} elseif (isset($_GET['contacto'])) {
  $documento = trim($_GET['contacto']);
} elseif (isset($_GET['doc'])) {
  $documento = trim($_GET['doc']);
}

// 2. Evaluar qué información requiere el primer paso
$need_identification = (empty($documento) && empty($case_id));
$need_agent_selection = empty($raw_owner);
$show_step1 = ($need_identification || $need_agent_selection);

$total_steps = $show_step1 ? 3 : 2;
$has_error = isset($_GET['error']) && $_GET['error'] === 'campos_incompletos';

// Lista de asesores únicos para la lista desplegable
$unique_agents = array();
foreach ($agents_catalog as $key => $info) {
  if ($key === $info['name'] && !isset($unique_agents[$info['name']])) {
    $unique_agents[$info['name']] = $info;
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Encuesta de Satisfacción</title>
  <meta name="description" content="Breve encuesta de satisfacción de servicio para egresados.">
  <link rel="stylesheet" href="css/styles.css?v=1.2">
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

      <?php if (!empty($case_id)): ?>
        <div class="case-info-bar" id="case-badge">
          <span>Referencia Caso:</span> <strong>#<?php echo htmlspecialchars($case_id, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
      <?php endif; ?>

      <?php if (!empty($evento)): ?>
        <div class="case-info-bar" style="background: #eef2ff; color: #4f46e5; margin-left: 0.25rem;">
          <span>Evento:</span> <strong><?php echo htmlspecialchars($evento, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
      <?php endif; ?>
    </header>

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

      <!-- Campos ocultos transferidos por URL -->
      <input type="hidden" id="case_id" name="case_id" value="<?php echo htmlspecialchars($case_id, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" id="contact_id" name="contact_id" value="<?php echo htmlspecialchars($contact_id, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" id="evento" name="evento" value="<?php echo htmlspecialchars($evento, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" id="input_owner" name="owner" value="<?php echo htmlspecialchars($raw_owner, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" id="input_owner_name" name="owner_name" value="<?php echo htmlspecialchars($owner_display_name, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" id="input_area" name="area" value="<?php echo htmlspecialchars($area, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" id="input_documento" name="documento" value="<?php echo htmlspecialchars($documento, ENT_QUOTES, 'UTF-8'); ?>">

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
                Documento de Identificación o Cédula
                <span style="color: #ef4444;">*</span>
              </label>
              <input
                type="text"
                id="field_documento"
                name="q_documento"
                class="custom-textarea"
                style="min-height: 48px; height: 48px; resize: none;"
                placeholder="Ingresa tu número de documento o correo..."
                value="<?php echo htmlspecialchars($documento, ENT_QUOTES, 'UTF-8'); ?>">
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
                name="select_agent"
                class="custom-textarea"
                style="min-height: 50px; height: 50px; padding: 0.75rem 1rem; cursor: pointer; font-size: 0.95rem; font-weight: 600;">
                <option value="">-- Selecciona la persona que te atendió --</option>
                <?php foreach ($unique_agents as $agent_name => $info): ?>
                  <option
                    value="<?php echo htmlspecialchars($agent_name, ENT_QUOTES, 'UTF-8'); ?>"
                    data-area="<?php echo htmlspecialchars($info['area'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($agent_name, ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($info['area'], ENT_QUOTES, 'UTF-8'); ?>)
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
            <span class="question-number">4.</span> Califica la atención recibida de <strong id="display-owner-name"><?php echo !empty($owner_display_name) ? htmlspecialchars($owner_display_name, ENT_QUOTES, 'UTF-8') : 'la persona que le atendió'; ?></strong> en una escala de 1 a 5
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

    <footer class="survey-footer">
      <p></p>
    </footer>

  </main>

  <script>
    window.SHOW_STEP1 = <?php echo $show_step1 ? 'true' : 'false'; ?>;
    window.TOTAL_STEPS = <?php echo $total_steps; ?>;
  </script>

  <script src="js/app.js"></script>
</body>

</html>