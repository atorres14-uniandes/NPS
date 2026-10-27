<?php

/**
 * Vista de Agradecimiento tras completar la Encuesta (PHP < 8 Compatible)
 */
$messages = array(
  'ok' => 'Tus respuestas han sido registradas exitosamente.',
  'respondida' => 'Esta encuesta ya había sido respondida. Gracias por tu interés.',
  'enlace' => 'El enlace de la encuesta no es válido.',
  'expirada' => 'La encuesta expiró. Abre nuevamente el enlace o escanea el código QR.',
  'limite' => 'Recibimos demasiados envíos desde tu conexión. Intenta de nuevo en unos minutos.',
  'error' => 'No pudimos registrar tus respuestas en este momento. Intenta de nuevo más tarde.'
);
$estado = isset($_GET['estado']) && is_string($_GET['estado']) && isset($messages[$_GET['estado']])
  ? $_GET['estado']
  : 'ok';
$is_error = in_array($estado, array('enlace', 'expirada', 'limite', 'error'), true);
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>¡Gracias por tus comentarios!</title>
  <link rel="stylesheet" href="css/styles.css">
  <style>
    .thankyou-card {
      text-align: center;
      padding: 3.5rem 2rem;
    }

    .success-icon-wrapper {
      width: auto;
      height: auto;
      background: transparent;
      border: none;
      box-shadow: none;
      margin: 0 auto 1.5rem;
    }
.success-icon-wrapper {
  width: auto;
  height: auto;
  background: transparent;
  border: none;
  box-shadow: none;
  margin: 0 auto 1rem;
  padding: 12px;
}

    .success-logo {
      max-width: 160px;
      height: auto;
    }

    .success-icon-wrapper svg {
      width: 44px;
      height: 44px;
      fill: currentColor;
    }

    @keyframes popIn {
      0% {
        transform: scale(0);
        opacity: 0;
      }

      100% {
        transform: scale(1);
        opacity: 1;
      }
    }

    .thankyou-title {
      font-size: 1.75rem;
      font-weight: 800;
      color: var(--text-main);
      margin-bottom: 0.75rem;
    }

    .thankyou-desc {
      font-size: 1rem;
      color: var(--text-muted);
      max-width: 480px;
      margin: 0 auto 2rem;
      line-height: 1.6;
    }
  </style>
</head>

<body>

  <!-- Ambient Background Orbs -->
  <div class="bg-orb bg-orb-1"></div>
  <div class="bg-orb bg-orb-2"></div>

  <main class="survey-wrapper">
    <div class="thankyou-card">
      <div class="success-icon-wrapper">
        <img src="images/SENECA.png"
          alt="Universidad de los Andes"
          class="success-logo">
      </div>

      <h1 class="thankyou-title">¡Muchas gracias por tu tiempo!</h1>
      <p class="thankyou-desc"<?php echo $is_error ? ' style="color: #b42318;"' : ''; ?>>
        <?php echo htmlspecialchars($messages[$estado], ENT_QUOTES, 'UTF-8'); ?>
      </p>
    </div>
  </main>

</body>

</html>