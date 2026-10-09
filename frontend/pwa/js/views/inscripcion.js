/**
 * Vista de Inscripción Express Móvil PWA PAD-28/32
 * Flujo en 3 Pasos con Cámara OCR, Validación Luhn Mod 10 y Lookup JCE en 1 Segundo
 */

const InscripcionView = {
  voterData: {},

  render(container) {
    const user = Auth.getUser() || {};
    const defaultCoord = user.role?.includes('Coordinador') ? user.nombre : (user.coordinador_nombre || user.nombre || 'Coordinación General');

    container.innerHTML = `
      <div class="pwa-container">
        <!-- Encabezado de Captación -->
        <div style="margin-bottom: 14px;">
          <h3 style="font-family: var(--font-title); font-size: 18px; font-weight: 800; color: #fff;">
            <i class="fa fa-user-plus" style="color: var(--secondary);"></i> Inscripción de Elector
          </h3>
          <p style="font-size: 11px; color: var(--text-gray);">Captación oficial de campo • Circunscripción 3</p>
        </div>

        <!-- PASO 1: CAPTURA POR CÁMARA O CÉDULA -->
        <div class="pwa-card">
          <div class="pwa-card-header">
            <h4 class="pwa-card-title"><i class="fa fa-id-card"></i> Paso 1: Cédula de Identidad</h4>
            <span class="meta-badge" id="badge-cedula-status">Requerida</span>
          </div>

          <!-- BOTÓN DE CÁMARA OCR -->
          <div class="ocr-capture-box" onclick="document.getElementById('camera-file-input').click()">
            <input type="file" id="camera-file-input" accept="image/*" capture="environment" style="display:none;" onchange="InscripcionView.handleImageUpload(event)">
            <i class="fa fa-camera ocr-icon"></i>
            <div class="ocr-title">Escanear Cédula con Cámara</div>
            <div class="ocr-sub">Extracción automática de datos mediante Google Cloud Vision OCR</div>
          </div>

          <!-- DIGITACIÓN RÁPIDA MANUAL -->
          <div class="form-group">
            <label class="form-label">Número de Cédula (11 dígitos): <span class="req">*</span></label>
            <div style="position: relative;">
              <input type="tel" inputmode="numeric" id="reg-cedula" class="form-control" 
                     placeholder="000-0000000-0" maxlength="13" 
                     oninput="InscripcionView.onCedulaInput(this)">
              <div id="cedula-spinner" style="position: absolute; right: 14px; top: 14px; display: none;">
                <i class="fa fa-spinner fa-spin" style="color: var(--accent-cyan);"></i>
              </div>
            </div>
            <div id="cedula-feedback" style="font-size: 11px; margin-top: 5px;"></div>
          </div>
        </div>

        <!-- PASO 2: DATOS OFICIALES JCE (AUTOCOMPLETADOS) -->
        <div class="pwa-card" id="card-datos-elector" style="opacity: 0.6;">
          <div class="pwa-card-header">
            <h4 class="pwa-card-title"><i class="fa fa-check-circle"></i> Paso 2: Datos Geoelectorales</h4>
            <span style="font-size: 11px; color: var(--accent-cyan);" id="label-fuente-datos">Pendiente Cédula</span>
          </div>

          <div class="form-group">
            <label class="form-label">Nombres: <span class="req">*</span></label>
            <input type="text" id="reg-nombres" class="form-control" placeholder="Nombre(s) del elector">
          </div>

          <div class="form-group">
            <label class="form-label">Apellidos: <span class="req">*</span></label>
            <input type="text" id="reg-apellidos" class="form-control" placeholder="Apellido(s) del elector">
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div class="form-group">
              <label class="form-label">Colegio: <span class="req">*</span></label>
              <input type="text" id="reg-colegio" class="form-control" placeholder="Ej: 1401">
            </div>
            <div class="form-group">
              <label class="form-label">Sector:</label>
              <input type="text" id="reg-sector" class="form-control" placeholder="Ej: Invivienda">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Recinto Electoral:</label>
            <input type="text" id="reg-recinto" class="form-control" placeholder="Nombre de la escuela o liceo">
          </div>

          <div class="form-group">
            <label class="form-label">Municipio:</label>
            <input type="text" id="reg-municipio" class="form-control" value="Santo Domingo Este" placeholder="Municipio">
          </div>
        </div>

        <!-- PASO 3: CONTACTO Y CONFIRMACIÓN -->
        <div class="pwa-card">
          <div class="pwa-card-header">
            <h4 class="pwa-card-title"><i class="fa fa-phone-alt"></i> Paso 3: Contacto y Guardado</h4>
          </div>

          <div class="form-group">
            <label class="form-label">Teléfono Celular (WhatsApp): <span class="req">*</span></label>
            <input type="tel" inputmode="numeric" id="reg-telefono" class="form-control" 
                   placeholder="809-000-0000" maxlength="12" 
                   oninput="InscripcionView.onTelefonoInput(this)">
          </div>

          <div class="form-group">
            <label class="form-label">Correo Electrónico (Opcional):</label>
            <input type="email" id="reg-email" class="form-control" placeholder="ejemplo@correo.com">
          </div>

          <div class="form-group">
            <label class="form-label">Coordinador Responsable:</label>
            <input type="text" id="reg-coordinador" class="form-control" value="${defaultCoord}" readonly>
          </div>

          <!-- CHECKBOX PARA ASCENDER A MILITANTE LÍDER (ML) -->
          <div style="background: rgba(227, 161, 21, 0.08); border: 1px solid rgba(227, 161, 21, 0.3); border-radius: 10px; padding: 12px; margin-bottom: 16px;">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #fff; font-weight: 600;">
              <input type="checkbox" id="reg-es-ml" style="width: 18px; height: 18px; accent-color: var(--secondary);">
              <span>Registrar como <strong>Militante Líder (ML)</strong> con meta de 25</span>
            </label>
            <p style="font-size: 11px; color: var(--text-gray); margin-top: 4px; padding-left: 28px;">
              Genera automáticamente un código ML y permite que este elector forme su propia red.
            </p>
          </div>

          <!-- BOTÓN FINAL DE GUARDADO -->
          <button class="btn-pwa btn-primary" id="btn-submit-registro" onclick="InscripcionView.guardarRegistro()" style="height: 52px; font-size: 15px;">
            <i class="fa fa-save"></i> Guardar Inscripción en Padrón
          </button>
        </div>
      </div>
    `;
  },

  // Manejo de input de cédula con formato y búsqueda automática
  async onCedulaInput(input) {
    let clean = input.value.replace(/\D/g, '');
    if (clean.length > 11) clean = clean.substring(0, 11);
    input.value = API.formatearCedula(clean);

    const feedback = document.getElementById('cedula-feedback');
    const badge = document.getElementById('badge-cedula-status');

    if (clean.length < 11) {
      feedback.innerHTML = `<span style="color: var(--text-gray);">Faltan ${11 - clean.length} dígitos</span>`;
      badge.innerText = 'Incompleta';
      badge.style.color = 'var(--text-gray)';
      return;
    }

    // Validar algoritmo Luhn Mod 10
    const esValida = API.validarCedula(clean);
    if (!esValida) {
      feedback.innerHTML = `<span style="color: var(--accent-red); font-weight: 700;"><i class="fa fa-times-circle"></i> Cédula inválida (Algoritmo Luhn Mod 10)</span>`;
      badge.innerText = 'Inválida';
      badge.style.color = 'var(--accent-red)';
      return;
    }

    feedback.innerHTML = `<span style="color: var(--accent-green); font-weight: 700;"><i class="fa fa-check-circle"></i> Cédula matemáticamente válida</span>`;
    badge.innerText = 'Válida';
    badge.style.color = 'var(--accent-green)';

    // Consultar Padrón Maestro en caliente
    this.consultarPadronMaestro(clean);
  },

  // Consulta en caliente al Padrón Maestro Circunscripción 3
  async consultarPadronMaestro(cedulaClean) {
    const spinner = document.getElementById('cedula-spinner');
    const cardDatos = document.getElementById('card-datos-elector');
    const labelFuente = document.getElementById('label-fuente-datos');

    if (spinner) spinner.style.display = 'block';

    try {
      const res = await API.lookupCedula(cedulaClean);
      if (spinner) spinner.style.display = 'none';

      if (res && res.encontrado && res.elector) {
        const el = res.elector;
        document.getElementById('reg-nombres').value = el.nombres || '';
        document.getElementById('reg-apellidos').value = el.apellidos || '';
        document.getElementById('reg-colegio').value = el.colegio_electoral || '';
        document.getElementById('reg-sector').value = el.sector || '';
        document.getElementById('reg-recinto').value = el.recinto_nombre || el.recinto_ubicacion || '';
        document.getElementById('reg-municipio').value = el.municipio || 'Santo Domingo Este';

        if (el.telefono_fijo || el.celular) {
          const telInput = document.getElementById('reg-telefono');
          if (!telInput.value) {
            telInput.value = API.formatearTelefono(el.celular || el.telefono_fijo);
          }
        }

        if (cardDatos) cardDatos.style.opacity = '1';
        if (labelFuente) {
          labelFuente.innerHTML = '<span style="color: var(--accent-green); font-weight: 700;"><i class="fa fa-database"></i> Verificado en Padrón JCE Circ. 3</span>';
        }

        UI.showToast('Datos autocompletados desde el Padrón Maestro.', 'success');
      } else {
        if (cardDatos) cardDatos.style.opacity = '1';
        if (labelFuente) {
          labelFuente.innerHTML = '<span style="color: var(--secondary);"><i class="fa fa-exclamation-triangle"></i> Llenado manual (Simpatizante)</span>';
        }
      }
    } catch (e) {
      if (spinner) spinner.style.display = 'none';
      if (cardDatos) cardDatos.style.opacity = '1';
    }
  },

  // Formateo de teléfono en tiempo real
  onTelefonoInput(input) {
    let clean = input.value.replace(/\D/g, '');
    if (clean.length > 10) clean = clean.substring(0, 10);
    input.value = API.formatearTelefono(clean);
  },

  // Subida y procesamiento de foto de cédula con Google Cloud Vision OCR
  async handleImageUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    UI.showToast('Analizando cédula con Google Vision OCR...', 'warning');

    try {
      const res = await API.procesarOCR(file);
      if (res && res.exito && res.datos) {
        const d = res.datos;
        if (d.cedula) {
          const cedInput = document.getElementById('reg-cedula');
          cedInput.value = API.formatearCedula(d.cedula);
          this.onCedulaInput(cedInput);
        }
        if (d.nombres) document.getElementById('reg-nombres').value = d.nombres;
        if (d.apellidos) document.getElementById('reg-apellidos').value = d.apellidos;
        if (d.colegio) document.getElementById('reg-colegio').value = d.colegio;

        UI.showToast('Cédula extraída exitosamente por OCR.', 'success');
      } else {
        UI.showToast('No se pudo extraer la cédula con nitidez. Ingrésela manualmente.', 'danger');
      }
    } catch (e) {
      UI.showToast('Error procesando imagen: ' + (e.message || 'Intente de nuevo.'), 'danger');
    }
  },

  // Envío final del formulario al backend con validaciones estrictas
  async guardarRegistro() {
    const cedula = document.getElementById('reg-cedula').value.trim();
    const nombres = document.getElementById('reg-nombres').value.trim();
    const apellidos = document.getElementById('reg-apellidos').value.trim();
    const colegio = document.getElementById('reg-colegio').value.trim();
    const recinto = document.getElementById('reg-recinto').value.trim() || 'Recinto Oficial';
    const sector = document.getElementById('reg-sector').value.trim() || 'Sector Circ. 3';
    const municipio = document.getElementById('reg-municipio').value.trim() || 'Santo Domingo Este';
    const telefono = document.getElementById('reg-telefono').value.trim();
    const email = document.getElementById('reg-email').value.trim();
    const coordinador = document.getElementById('reg-coordinador').value.trim();
    const esML = document.getElementById('reg-es-ml').checked;

    // 1. Validar campos requeridos
    if (!cedula || !nombres || !apellidos || !colegio || !telefono) {
      UI.showToast('Complete los campos obligatorios: Cédula, Nombres, Apellidos, Colegio y Teléfono.', 'danger');
      return;
    }

    // 2. Validar Cédula Luhn Mod 10
    if (!API.validarCedula(cedula)) {
      UI.showToast('La cédula ingresada no es válida según el algoritmo oficial.', 'danger');
      return;
    }

    // 3. Validar Teléfono Dominicano (809/829/849)
    if (!API.validarTelefono(telefono)) {
      UI.showToast('El teléfono debe tener 10 dígitos y prefijo dominicano válido (809, 829 o 849).', 'danger');
      return;
    }

    const btn = document.getElementById('btn-submit-registro');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Guardando en Padrón...';
    btn.disabled = true;

    const payload = {
      cedula: cedula,
      nombres: nombres,
      apellidos: apellidos,
      colegio_electoral: colegio,
      recinto_ubicacion: recinto,
      sector: sector,
      municipio: municipio,
      telefono: telefono.replace(/\D/g, ''),
      email: email,
      coordinador: coordinador,
      centro_acopio: 'Acopio Central',
      canal_origen: 'PWA Móvil',
      tipo_elector: esML ? 'Nuevo Elector (ML)' : 'Nuevo Elector',
      es_militante_lider: esML ? 1 : 0,
      nivel_estructura: esML ? 'ML - Militante Líder' : 'Votante'
    };

    try {
      const res = await API.registrarVotante(payload);
      btn.innerHTML = originalText;
      btn.disabled = false;

      if (res && res.exito) {
        // Desplegar Modal de Éxito con Voucher y Botón de WhatsApp
        this.mostrarVoucherExito(res, payload);
      }
    } catch (err) {
      btn.innerHTML = originalText;
      btn.disabled = false;

      // Restricción Universal UNIQUE (HTTP 409)
      if (err.status === 409) {
        App.showModal(
          '⚠️ Cédula Ya Registrada',
          `
            <div style="text-align: center; padding: 10px;">
              <i class="fa fa-exclamation-circle" style="font-size: 38px; color: var(--secondary); margin-bottom: 12px;"></i>
              <p style="font-size: 13.5px; line-height: 1.5; color: #fff;">${err.message}</p>
              <div style="background: rgba(227, 161, 21, 0.1); border: 1px solid var(--secondary); border-radius: 8px; padding: 10px; margin-top: 14px; font-size: 12px; color: var(--secondary);">
                <strong>Norma PLAD-REL-INGESTA-01:</strong> La restricción de unicidad protege la base de datos contra registros duplicados.
              </div>
            </div>
          `,
          `<button class="btn-pwa btn-primary" onclick="App.closeModal()">Entendido</button>`
        );
      } else {
        UI.showToast(err.message || 'Error al guardar elector.', 'danger');
      }
    }
  },

  // Despliega el voucher con QR en el modal para compartirlo por WhatsApp
  mostrarVoucherExito(res, payload) {
    const voterId = res.id;
    const cleanCed = payload.cedula.replace(/\D/g, '');
    const qrTargetUrl = API.getPublicUrl(`validar.php?folio=${encodeURIComponent(folio)}&cedula=${encodeURIComponent(cleanCed)}`);

    const bodyHtml = `
      <div class="voucher-box">
        <div class="voucher-header">
          <h4>¡INSCRIPCIÓN EXITOSA!</h4>
          <p style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">Padrón de Apoyo • Pastora Altagracia</p>
        </div>

        <div class="voucher-folio">${folio}</div>

        <h3 style="font-size: 16px; font-weight: 800; color: #002F6C; margin-bottom: 2px;">
          ${payload.nombres} ${payload.apellidos}
        </h3>
        <p style="font-size: 12px; color: #64748B;">Cédula: ${payload.cedula}</p>

        <!-- CONTENEDOR DEL CÓDIGO QR -->
        <div class="voucher-qr-wrap" id="voucher-qr-target"></div>

        <p style="font-size: 11px; color: #475569; margin-bottom: 8px;">
          Escanea para certificar con <strong>Normas PLAD</strong>
        </p>

        <div style="background: #F1F5F9; border-radius: 8px; padding: 8px; font-size: 11px; color: #334155; text-align: left; margin-top: 10px;">
          <div><strong>Colegio:</strong> ${payload.colegio_electoral}</div>
          <div><strong>Recinto:</strong> ${payload.recinto_ubicacion}</div>
          <div><strong>Registrado por:</strong> ${payload.coordinador}</div>
        </div>
      </div>
    `;

    const footerHtml = `
      <button class="btn-pwa btn-whatsapp" onclick="App.compartirVoucherWhatsApp('${voterId}', '${payload.nombres}', '${payload.cedula}', '${folio}')">
        <i class="fab fa-whatsapp"></i> Compartir por WhatsApp
      </button>
      <button class="btn-pwa btn-primary" onclick="App.closeModal(); Router.navigate('dashboard');">
        <i class="fa fa-check"></i> Finalizar
      </button>
    `;

    App.showModal('Comprobante Oficial Emitido', bodyHtml, footerHtml);

    // Renderizar QR en local inmediatamente con qrcode.min.js
    setTimeout(() => {
      const qrContainer = document.getElementById('voucher-qr-target');
      if (qrContainer && typeof QRCode !== 'undefined') {
        qrContainer.innerHTML = '';
        new QRCode(qrContainer, {
          text: qrTargetUrl,
          width: 140,
          height: 140,
          colorDark: '#002F6C',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      }
    }, 100);
  }
};

window.InscripcionView = InscripcionView;
