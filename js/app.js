/**
 * Control del Flujo Visual y Lista Desplegable Asesor -> Área (PHP < 8 Compatible)
 */

document.addEventListener('DOMContentLoaded', () => {
  const surveyForm = document.getElementById('survey-form');
  const stepPanels = document.querySelectorAll('.step-panel');
  const progressBarFill = document.getElementById('progress-bar-fill');
  const currentStepText = document.getElementById('current-step-num');
  const stepCategoryTitle = document.getElementById('step-category-title');
  const btnPrev = document.getElementById('btn-prev');
  const btnNext = document.getElementById('btn-next');
  const btnSubmit = document.getElementById('btn-submit');
  const errorBanner = document.getElementById('error-banner');
  const errorMessage = document.getElementById('error-message');
  
  const displayAreaName = document.getElementById('display-area-name');
  const displayOwnerName = document.getElementById('display-owner-name');
  const fieldDocumento = document.getElementById('field_documento');
  const selectAgent = document.getElementById('select_agent');

  let currentStep = 1;
  let isSubmitting = false;
  const totalSteps = window.TOTAL_STEPS || stepPanels.length;
  const showStep1 = window.SHOW_STEP1 || false;

  const categoryTitlesWithStep1 = [
    "Información inicial",
    "Evaluación del servicio",
    "Recomendación"
  ];

  const categoryTitlesDirect = [
    "Evaluación del servicio",
    "Recomendación"
  ];

  const categoryTitles = showStep1 ? categoryTitlesWithStep1 : categoryTitlesDirect;

  // 1. Escuchar la selección de Asesor en la Lista Desplegable (Select)
  if (selectAgent) {
    selectAgent.addEventListener('change', () => {
      // Solo cambia los textos visibles; el backend valida el asesor contra su catálogo.
      const selectedOption = selectAgent.options[selectAgent.selectedIndex];
      const agentName = selectedOption ? selectedOption.getAttribute('data-name') : '';
      const associatedArea = selectedOption ? selectedOption.getAttribute('data-area') : '';

      if (displayOwnerName) displayOwnerName.textContent = agentName || 'la persona que te atendió';
      if (displayAreaName && associatedArea) displayAreaName.textContent = associatedArea;

      hideError();
    });
  }

  // 2. Ocultar errores al interactuar con las preguntas
  document.addEventListener('change', (e) => {
    if (e.target.matches('input[type="radio"], select, textarea, input[type="text"]')) {
      hideError();
    }
  });

  document.addEventListener('input', (e) => {
    if (e.target.matches('input[type="text"]')) {
      hideError();
    }
  });

  // 3. Navegación Stepper
  btnNext.addEventListener('click', () => {
    if (validateStep(currentStep)) {
      hideError();
      if (currentStep < totalSteps) {
        currentStep++;
        updateStepView();
      }
    }
  });

  btnPrev.addEventListener('click', () => {
    hideError();
    if (currentStep > 1) {
      currentStep--;
      updateStepView();
    }
  });

  surveyForm.addEventListener('submit', (e) => {
    // Enter en el campo de documento no debe enviar la encuesta antes del último paso.
    if (currentStep !== totalSteps) {
      e.preventDefault();
      btnNext.click();
      return false;
    }

    if (isSubmitting || !validateStep(currentStep)) {
      e.preventDefault();
      return false;
    }

    isSubmitting = true;
    setSubmitLoadingState(true);
  });

  function setSubmitLoadingState(isLoading) {
    if (isLoading) {
      btnSubmit.disabled = true;
      btnSubmit.innerHTML = `
        <svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
          <circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="10"></circle>
        </svg>
        Guardando...
      `;
    }
  }

  function updateStepView() {
    stepPanels.forEach((panel, idx) => {
      if (idx + 1 === currentStep) {
        panel.classList.add('active');
      } else {
        panel.classList.remove('active');
      }
    });

    const percentage = (currentStep / totalSteps) * 100;
    progressBarFill.style.width = `${percentage}%`;
    currentStepText.textContent = currentStep;
    stepCategoryTitle.textContent = categoryTitles[currentStep - 1] || "";

    if (currentStep === 1) {
      btnPrev.style.visibility = 'hidden';
    } else {
      btnPrev.style.visibility = 'visible';
    }

    if (currentStep === totalSteps) {
      btnNext.style.display = 'none';
      btnSubmit.style.display = 'inline-flex';
    } else {
      btnNext.style.display = 'inline-flex';
      btnSubmit.style.display = 'none';
    }

    document.querySelector('.survey-wrapper').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function validateStep(step) {
    let isValid = true;
    let msg = "";

    if (showStep1 && step === 1) {
      // Misma regla que el backend: solo dígitos (se ignoran espacios, puntos y guiones), de 4 a 15.
      const documento = fieldDocumento ? fieldDocumento.value.replace(/[\s.\-]/g, '') : '';
      if (fieldDocumento && !/^[0-9]{4,15}$/.test(documento)) {
        isValid = false;
        msg = "Por favor ingrese su número de documento (solo números).";
      } else if (selectAgent && selectAgent.value.trim() === '') {
        isValid = false;
        msg = "Por favor seleccione la persona que le atendió de la lista desplegable.";
      }
    } else {
      const evalStepIndex = showStep1 ? 2 : 1;
      const npsStepIndex = showStep1 ? 3 : 2;

      if (step === evalStepIndex) {
        const q1 = document.querySelector('input[data-question="q_atendido"]:checked') || document.querySelector('input[name="q_atendido"]:checked');
        const q2 = document.querySelector('input[data-question="q_resuelta"]:checked') || document.querySelector('input[name="q_resuelta"]:checked');
        const q3 = document.querySelector('input[data-question="q_satisfaccion"]:checked') || document.querySelector('input[name="q_satisfaccion"]:checked');
        const q4 = document.querySelector('input[data-question="q_debora"]:checked') || document.querySelector('input[name="q_debora"]:checked');

        if (!q1) {
          isValid = false;
          msg = "Por favor responda si su solicitud o caso fue atendido.";
        } else if (!q2) {
          isValid = false;
          msg = "Por favor responda si su necesidad quedó resuelta.";
        } else if (!q3) {
          isValid = false;
          msg = "Por favor seleccione su nivel de satisfacción con el área.";
        } else if (!q4) {
          isValid = false;
          msg = "Por favor califique la atención recibida (1 a 5).";
        }
      } else if (step === npsStepIndex) {
        const qnps = document.querySelector('input[data-question="q_nps"]:checked') || document.querySelector('input[name="q_nps"]:checked');
        if (!qnps) {
          isValid = false;
          msg = "Por favor seleccione una puntuación en la escala de 0 a 10.";
        }
      }
    }

    if (!isValid) {
      showError(msg);
    }

    return isValid;
  }

  function showError(msg) {
    errorMessage.textContent = msg;
    errorBanner.classList.add('active');
  }

  function hideError() {
    errorBanner.classList.remove('active');
  }
});
