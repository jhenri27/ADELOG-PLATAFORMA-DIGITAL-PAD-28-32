/**
 * Vista de Consulta Dual de Estatus Elector PWA PAD-28/32
 * Diagnóstico en Caliente de 4 Estados conforme a la Norma PLAD-REL-INGESTA-01
 */

const ConsultaView = {
  render(container) {
    container.innerHTML = `
      <div class="pwa-container">
        <!-- Encabezado de Consulta -->
        <div style="margin-bottom: 14px;">
          <h3 style="font-family: var(--font-title); font-size: 18px; font-weight: 800; color: #fff;">
            <i class="fa fa-search" style="color: var(--secondary);"></i> Consulta Dual de Estatus
          </h3>
          <p style="font-size: 11px; color: var(--text-gray);">Cruce Padrón Maestro Partido vs. Padrón Candidata</p>
        </div>

        <!-- TARJETA DE BÚSQUEDA -->
        <div class="pwa-card">
          <div class="form-group" style="margin-bottom: 10px;">
            <label class="form-label">Ingrese Cédula del Ciudadano:</label>
            <input type="tel" inputmode="numeric" id="consulta-cedula" class="form-control" 
                   placeholder="000-0000000-0" maxlength="13" 
                   oninput="ConsultaView.onCedulaInput(this)">
          </div>
          <button class="btn-pwa btn-gold" id="btn-ejecutar-consulta" onclick="ConsultaView.ejecutarConsulta()">
            <i class="fa fa-search"></i> Verificar Estatus Dual
          </button>
        </div>

        <!-- CONTENEDOR DE RESULTADOS Y DIAGNÓSTICO -->
        <div id="consulta-resultado-container">
          <!-- Renderizado dinámico tras búsqueda -->
        </div>

        <!-- EXPLICACIÓN NORMATIVA DE LOS 4 ESTADOS -->
        <div class="pwa-card" style="margin-top: 20px; font-size: 11.5px; color: var(--text-gray);">
          <div style="font-family: var(--font-title); font-weight: 700; color: #fff; margin-bottom: 8px;">
            <i class="fa fa-book"></i> Guía de Diagnóstico Electoral (Normas PLAD):
          </div>
          <div style="display: flex; gap: 8px; margin-bottom: 6px;">
            <span style="color: #34D399; font-weight: 800;">• Estado 1:</span>
            <span>Militante Comprometido (En padrón del partido y registrado con Pastora).</span>
          </div>
          <div style="display: flex; gap: 8px; margin-bottom: 6px;">
            <span style="color: var(--accent-cyan); font-weight: 800;">• Estado 2:</span>
            <span>Militante No Captado (Objetivo Estratégico de captación inmediata).</span>
          </div>
          <div style="display: flex; gap: 8px; margin-bottom: 6px;">
            <span style="color: #C084FC; font-weight: 800;">• Estado 3:</span>
            <span>Simpatizante Externo (Registrado en red sin militancia previa).</span>
          </div>
          <div style="display: flex; gap: 8px;">
            <span style="color: #94A3B8; font-weight: 800;">• Estado 4:</span>
            <span>No Localizado en la Demarcación.</span>
          </div>
        </div>
      </div>
    `;
  },

  onCedulaInput(input) {
    let clean = input.value.replace(/\D/g, '');
    if (clean.length > 11) clean = clean.substring(0, 11);
    input.value = API.formatearCedula(clean);
  },

  async ejecutarConsulta() {
    const input = document.getElementById('consulta-cedula');
    const cedula = input.value.trim();
    const clean = cedula.replace(/\D/g, '');

    if (clean.length !== 11 || !API.validarCedula(clean)) {
      UI.showToast('Ingrese una cédula dominicana válida de 11 dígitos.', 'danger');
      return;
    }

    const btn = document.getElementById('btn-ejecutar-consulta');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Cruzando Padrones...';
    btn.disabled = true;

    const resultBox = document.getElementById('consulta-resultado-container');
    resultBox.innerHTML = `
      <div style="text-align: center; padding: 24px; color: var(--text-gray);">
        <i class="fa fa-spinner fa-spin" style="font-size: 26px; color: var(--secondary);"></i>
        <p style="margin-top: 10px; font-size: 13px;">Evaluando Padrón Maestro (330k) y Padrón Activo...</p>
      </div>
    `;

    try {
      const res = await API.queryDualEstatus(clean);
      btn.innerHTML = originalText;
      btn.disabled = false;

      this.renderResultadoDiagnostico(res, clean);
    } catch (err) {
      btn.innerHTML = originalText;
      btn.disabled = false;
      resultBox.innerHTML = `
        <div class="estado-card estado-4">
          <div class="estado-title"><i class="fa fa-times-circle"></i> Error de Consulta</div>
          <p class="estado-desc">${err.message || 'No se pudo realizar el cruce de datos.'}</p>
        </div>
      `;
    }
  },

  renderResultadoDiagnostico(res, cedulaClean) {
    const resultBox = document.getElementById('consulta-resultado-container');
    const v = (res.votantes && res.votantes.length > 0) ? res.votantes[0] : null;

    const estaEnPartido = v ? (v.en_padron_partido === true) : (res.partido_encontrado === true);
    const estaConPastora = v ? (v.en_padron_candidata === true) : (res.candidata_encontrado === true);
    const estadoDiag = v ? v.estado_diagnostico : (res.estado_diagnostico || 'NO_LOCALIZADO');

    let estadoClase = 'estado-4';
    let estadoTitulo = 'Estado 4: No Localizado en Circunscripción 3';
    let estadoIcono = 'fa-question-circle';
    let estadoDesc = 'El ciudadano no figura en el padrón electoral de la Circunscripción 3 ni en el padrón de apoyo.';

    if (estadoDiag === 'COMPROMETIDO' || (estaEnPartido && estaConPastora)) {
      estadoClase = 'estado-1';
      estadoTitulo = 'Estado 1: Militante Comprometido (Registrado)';
      estadoIcono = 'fa-check-double';
      estadoDesc = 'El ciudadano es militante en el padrón del partido y ya está activo en el Padrón de Apoyo a Pastora Altagracia.';
    } else if (estadoDiag === 'NO_CAPTADO' || (estaEnPartido && !estaConPastora)) {
      estadoClase = 'estado-2';
      estadoTitulo = 'Estado 2: Militante No Captado (¡Objetivo Prioritario!)';
      estadoIcono = 'fa-bullseye';
      estadoDesc = 'Militante oficial del partido en la Circunscripción 3 que AÚN NO HA SIDO CAPTADO por la estructura de la candidata.';
    } else if (estadoDiag === 'EXTERNO' || (!estaEnPartido && estaConPastora)) {
      estadoClase = 'estado-3';
      estadoTitulo = 'Estado 3: Simpatizante Externo Registrado';
      estadoIcono = 'fa-user-tag';
      estadoDesc = 'Ciudadano captado y activo en el padrón de la candidata como multiplicador de red externo.';
    }

    const nombreCompleto = v?.nombre_completo || res.elector?.nombre_completo || '';
    const colegio = v?.colegio_electoral || res.elector?.colegio_electoral || '';
    const recinto = v?.recinto_ubicacion || res.elector?.recinto_ubicacion || '';
    const sector = v?.sector || res.elector?.sector || '';
    const municipio = v?.municipio || res.elector?.municipio || 'Santo Domingo Este';
    const coordinador = v?.coordinador || '';
    const fechaReg = v?.fecha_registro || '';
    const comprobante = v?.codigo_comprobante || '';
    const voterId = v?.id || 0;

    resultBox.innerHTML = `
      <div class="estado-card ${estadoClase}">
        <div class="estado-title">
          <i class="fa ${estadoIcono}"></i> ${estadoTitulo}
        </div>
        <p class="estado-desc">${estadoDesc}</p>

        <!-- DATOS DEL CIUDADANO SI EXISTE -->
        ${nombreCompleto ? `
          <div style="background: rgba(0,0,0,0.25); border-radius: 8px; padding: 12px; margin-top: 12px; font-size: 12.5px;">
            <div style="font-weight: 800; color: #fff; font-size: 14.5px;">
              ${nombreCompleto}
            </div>
            <div style="color: var(--accent-cyan); margin-top: 2px; font-weight: 700;">
              Cédula: ${API.formatearCedula(cedulaClean)}
            </div>
            ${comprobante ? `
              <div style="color: var(--secondary); margin-top: 4px; font-size: 11.5px; font-weight: 700;">
                <i class="fa fa-qrcode"></i> Folio: ${comprobante}
              </div>
            ` : ''}
            <div style="color: var(--text-gray); margin-top: 6px; display: grid; grid-template-columns: 1fr 1fr; gap: 4px;">
              <div><strong>Colegio:</strong> ${colegio || '—'}</div>
              <div><strong>Sector:</strong> ${sector || '—'}</div>
            </div>
            ${recinto ? `
              <div style="color: var(--text-gray); margin-top: 4px;">
                <strong>Recinto:</strong> ${recinto}
              </div>
            ` : ''}
            ${coordinador ? `
              <div style="color: #34D399; margin-top: 4px; font-size: 11.5px;">
                <strong>Registrado por:</strong> ${coordinador} ${fechaReg ? `(${fechaReg})` : ''}
              </div>
            ` : ''}
          </div>
        ` : ''}

        <!-- BOTONES DE ACCIÓN SEGÚN ESTADO -->
        ${(!estaConPastora) ? `
          <div style="margin-top: 14px;">
            <button class="btn-pwa btn-primary" onclick="ConsultaView.procederInscripcion('${cedulaClean}', '${v?.nombres || ''}', '${v?.apellidos || ''}', '${colegio}', '${sector}')">
              <i class="fa fa-user-plus"></i> Inscribir Ahora con Pastora
            </button>
          </div>
        ` : `
          <div style="margin-top: 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
            <button class="btn-pwa btn-whatsapp" style="height: 42px; font-size: 12px;" onclick="App.compartirVoucherWhatsApp('${voterId}', '${nombreCompleto}', '${cedulaClean}', '${comprobante}')">
              <i class="fab fa-whatsapp"></i> WhatsApp
            </button>
            <button class="btn-pwa btn-outline" style="height: 42px; font-size: 12px;" onclick="App.verComprobanteModal('${voterId}')">
              <i class="fa fa-qrcode"></i> Ver QR
            </button>
          </div>
        `}
      </div>
    `;
  },

  procederInscripcion(cedula, nombres, apellidos, colegio, sector) {
    Router.navigate('inscribir');
    setTimeout(() => {
      const cedInput = document.getElementById('reg-cedula');
      if (cedInput) {
        cedInput.value = API.formatearCedula(cedula);
        InscripcionView.onCedulaInput(cedInput);
      }
    }, 150);
  }
};

window.ConsultaView = ConsultaView;
