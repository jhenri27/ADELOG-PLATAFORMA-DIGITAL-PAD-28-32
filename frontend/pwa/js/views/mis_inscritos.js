/**
 * Vista de Mis Inscritos / Padrón de Red Territorial PWA PAD-28/32
 */

const MisInscritosView = {
  allVoters: [],

  getHeaderInfo(user) {
    if (Auth.isAdmin() || user.role === 'Coordinador General' || user.role === 'Jefe Electoral') {
      return {
        title: 'Padrón General Completo',
        sub: 'Auditoría y control global de toda la demarcación'
      };
    }
    if (Auth.isCoordinator()) {
      return {
        title: 'Padrón de Mi Red',
        sub: 'Supervisión territorial de electores en tu zona'
      };
    }
    if (Auth.isDigitador()) {
      return {
        title: 'Mis Electores Digitados',
        sub: 'Inscripciones realizadas bajo tu usuario'
      };
    }
    return {
      title: 'Mis 25 Electores',
      sub: 'Captaciones personales activas en tu meta de red'
    };
  },

  async render(container) {
    const user = Auth.getUser() || {};
    const headerInfo = this.getHeaderInfo(user);

    container.innerHTML = `
      <div class="pwa-container">
        <!-- Encabezado -->
        <div style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center;">
          <div>
            <h3 style="font-family: var(--font-title); font-size: 18px; font-weight: 800; color: #fff;">
              <i class="fa fa-users" style="color: var(--secondary);"></i> ${headerInfo.title}
            </h3>
            <p style="font-size: 11px; color: var(--text-gray);">${headerInfo.sub}</p>
          </div>
          <span class="meta-badge" id="voters-total-count">0 electores</span>
        </div>

        <!-- BUSCADOR EN VIVO -->
        <div class="form-group">
          <input type="text" id="voters-search-input" class="form-control" 
                 placeholder="🔍 Buscar por nombre, cédula o colegio..." 
                 oninput="MisInscritosView.filtrarVotantes(this.value)">
        </div>

        <!-- LISTA DE VOTANTES -->
        <div id="voters-list-container">
          <div style="text-align: center; padding: 30px; color: var(--text-muted);">
            <i class="fa fa-spinner fa-spin" style="font-size: 24px; color: var(--secondary);"></i>
            <p style="margin-top: 10px; font-size: 13px;">Cargando lista de electores...</p>
          </div>
        </div>
      </div>
    `;

    this.cargarVotantes(user);
  },

  async cargarVotantes(user) {
    try {
      // El backend aplica automáticamente el aislamiento y control de visibilidad por rol (PLAD-SEC-01)
      const res = await API.getVotantes();
      this.allVoters = res?.voters || res?.votantes || [];
      const totalCount = document.getElementById('voters-total-count');
      if (totalCount) totalCount.innerText = `${this.allVoters.length} electores`;

      this.renderLista(this.allVoters);
    } catch (e) {
      const listContainer = document.getElementById('voters-list-container');
      if (listContainer) {
        listContainer.innerHTML = `
          <div style="text-align: center; padding: 20px; color: var(--accent-red); font-size: 13px;">
            <i class="fa fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 8px;"></i>
            <p>Error cargando electores: ${e.message || 'Verifique su conexión'}</p>
          </div>
        `;
      }
    }
  },

  renderLista(votantes) {
    const container = document.getElementById('voters-list-container');
    if (!container) return;

    if (!votantes || votantes.length === 0) {
      container.innerHTML = `
        <div style="text-align: center; padding: 40px 10px; color: var(--text-gray);">
          <i class="fa fa-user-friends" style="font-size: 36px; color: var(--border-highlight); margin-bottom: 12px;"></i>
          <h4 style="font-family: var(--font-title); font-size: 16px; color: #fff;">No se encontraron electores</h4>
          <p style="font-size: 12px; margin-top: 4px;">Comienza a captar simpatizantes en tu zona.</p>
          <button class="btn-pwa btn-primary" style="margin-top: 16px; width: auto; padding: 0 24px;" onclick="Router.navigate('inscribir')">
            <i class="fa fa-plus"></i> Inscribir Elector Ahora
          </button>
        </div>
      `;
      return;
    }

    container.innerHTML = votantes.map(v => `
      <div class="voter-item-card">
        <div class="voter-info">
          <div class="voter-name">
            ${v.nombres} ${v.apellidos}
            ${v.es_militante_lider == 1 ? '<span class="meta-badge" style="font-size: 9px; padding: 1px 5px; margin-left: 4px;">ML</span>' : ''}
          </div>
          <div class="voter-cedula">${API.formatearCedula(v.cedula)}</div>
          <div class="voter-meta">
            <span><i class="fa fa-vote-yea"></i> Col. ${v.colegio_electoral || '—'}</span>
            <span><i class="fa fa-map-marker-alt"></i> ${v.sector || 'Circ. 3'}</span>
          </div>
        </div>
        <div class="voter-actions">
          <button class="btn-action-sm" style="background: rgba(37, 211, 102, 0.15); color: #25D366;" 
                  onclick="App.compartirVoucherWhatsApp('${v.id}', '${v.nombres}', '${v.cedula}', '${v.codigo_comprobante || ''}')" title="Reenviar WhatsApp">
            <i class="fab fa-whatsapp"></i>
          </button>
          <button class="btn-action-sm" style="background: rgba(0, 210, 255, 0.15); color: var(--accent-cyan);" 
                  onclick="App.verComprobanteModal('${v.id}')" title="Ver Comprobante con QR">
            <i class="fa fa-qrcode"></i>
          </button>
        </div>
      </div>
    `).join('');
  },

  filtrarVotantes(texto) {
    const term = (texto || '').toLowerCase().trim();
    if (!term) {
      this.renderLista(this.allVoters);
      return;
    }

    const filtrados = this.allVoters.filter(v => {
      const nombreCompleto = `${v.nombres} ${v.apellidos}`.toLowerCase();
      const cedulaClean = (v.cedula || '').replace(/\D/g, '');
      const colegio = (v.colegio_electoral || '').toLowerCase();
      const sector = (v.sector || '').toLowerCase();

      return (
        nombreCompleto.includes(term) ||
        cedulaClean.includes(term) ||
        colegio.includes(term) ||
        sector.includes(term)
      );
    });

    this.renderLista(filtrados);
  }
};

window.MisInscritosView = MisInscritosView;
