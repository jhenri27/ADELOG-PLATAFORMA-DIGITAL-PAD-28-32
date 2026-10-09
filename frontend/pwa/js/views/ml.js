/**
 * Vista de Dashboard para Militantes Líderes (ML) PWA PAD-28/32
 * Meta Personal: 25 Electores Directos con Gamificación
 */

const MLView = {
  async render(container) {
    const user = Auth.getUser() || {};
    
    container.innerHTML = `
      <div class="pwa-container">
        <!-- Saludo y Código ML -->
        <div style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center;">
          <div>
            <h3 style="font-family: var(--font-title); font-size: 17px; font-weight: 800; color: #fff;">
              ¡Hola, ${user.nombre ? user.nombre.split(' ')[0] : 'Líder'}!
            </h3>
            <p style="font-size: 11px; color: var(--text-gray);">Campaña Pastora Altagracia • Circ. 3</p>
          </div>
          <span class="meta-badge" style="font-size: 12px; padding: 4px 10px;">
            <i class="fa ${Auth.isDigitador() ? 'fa-keyboard' : 'fa-id-badge'}"></i> ${user.codigo_ml || user.role || 'ML'}
          </span>
        </div>

        <!-- WIDGET GAMIFICADO: META 0/25 -->
        <div class="meta-ml-widget" id="ml-widget-container">
          <div style="text-align: center; padding: 20px 0;">
            <i class="fa fa-spinner fa-spin" style="font-size: 24px; color: var(--secondary);"></i>
            <p style="font-size: 12px; color: var(--text-gray); margin-top: 8px;">Cargando tu avance electoral...</p>
          </div>
        </div>

        <!-- ACCIONES RÁPIDAS DE CALLE -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px;">
          <button class="btn-pwa btn-primary" onclick="Router.navigate('inscribir')">
            <i class="fa fa-user-plus"></i> Inscribir Elector
          </button>
          <button class="btn-pwa btn-gold" onclick="Router.navigate('consultar')">
            <i class="fa fa-search"></i> Consultar Cédula
          </button>
        </div>

        <!-- ÚLTIMOS ELECTORES CAPTADOS -->
        <div class="pwa-card">
          <div class="pwa-card-header">
            <h4 class="pwa-card-title"><i class="fa fa-users"></i> Mis Captaciones Recientes</h4>
            <a href="#mis-inscritos" style="color: var(--accent-cyan); font-size: 12px; font-weight: 600; text-decoration: none;">Ver Todos</a>
          </div>
          <div id="ml-recent-voters">
            <div style="text-align: center; padding: 15px; color: var(--text-muted); font-size: 12px;">
              <i class="fa fa-spinner fa-spin"></i> Cargando electores...
            </div>
          </div>
        </div>
      </div>
    `;

    // Cargar datos reales de la BD
    this.cargarDatosML(user);
  },

  async cargarDatosML(user) {
    const isDig = Auth.isDigitador();
    const metaObjetivo = isDig ? 50 : 25;
    let totalInscritos = user.total_inscritos || 0;
    let votantes = [];

    try {
      // Consultar lista de inscritos filtrada por este usuario
      const res = await API.getVotantes({ registrado_por: user.id });
      if (res && res.voters) {
        votantes = res.voters;
        totalInscritos = res.total || votantes.length;
      }
    } catch (e) {
      console.warn('Error cargando electores del ML, usando datos en caché:', e);
    }

    const porcentaje = Math.min(100, Math.round((totalInscritos / metaObjetivo) * 100));
    const faltantes = Math.max(0, metaObjetivo - totalInscritos);

    // Renderizar Widget de Meta
    const widget = document.getElementById('ml-widget-container');
    if (widget) {
      widget.innerHTML = `
        <div class="meta-widget-top">
          <div>
            <span class="meta-badge">${isDig ? '<i class="fa fa-keyboard"></i> Rendimiento de Digitación' : '<i class="fa fa-flag"></i> Meta Electoral Oficial'}</span>
            <div class="meta-counter" style="margin-top: 6px;">
              ${totalInscritos} <span>/ ${metaObjetivo}</span>
            </div>
          </div>
          <div style="text-align: right;">
            <span style="font-family: var(--font-title); font-size: 26px; font-weight: 800; color: var(--secondary);">
              ${porcentaje}%
            </span>
            <div style="font-size: 10px; color: var(--text-gray); text-transform: uppercase;">Cumplimiento</div>
          </div>
        </div>

        <div class="meta-progress-bar">
          <div class="meta-progress-fill" style="width: ${porcentaje}%;"></div>
        </div>

        <div class="meta-status-text">
          <span>${totalInscritos >= metaObjetivo ? '🎉 ¡Meta cumplida exitosamente!' : `Faltan <strong>${faltantes} electores</strong>`}</span>
          <span>Demarcación Circ. 3</span>
        </div>
      `;
    }

    // Renderizar electores recientes
    const recentContainer = document.getElementById('ml-recent-voters');
    if (recentContainer) {
      if (!votantes || votantes.length === 0) {
        recentContainer.innerHTML = `
          <div style="text-align: center; padding: 20px 10px; color: var(--text-gray);">
            <i class="fa fa-user-friends" style="font-size: 28px; color: var(--border-highlight); margin-bottom: 8px;"></i>
            <p style="font-size: 13px;">Aún no has registrado electores.</p>
            <button class="btn-pwa btn-primary" style="margin-top: 10px; height: 38px; font-size: 12px;" onclick="Router.navigate('inscribir')">
              <i class="fa fa-plus"></i> Inscribir mi primer elector
            </button>
          </div>
        `;
        return;
      }

      const topVoters = votantes.slice(0, 4);
      recentContainer.innerHTML = topVoters.map(v => `
        <div class="voter-item-card">
          <div class="voter-info">
            <div class="voter-name">${v.nombres} ${v.apellidos}</div>
            <div class="voter-cedula">${API.formatearCedula(v.cedula)}</div>
            <div class="voter-meta">
              <span><i class="fa fa-vote-yea"></i> Col. ${v.colegio_electoral || '—'}</span>
              <span><i class="fa fa-map-marker-alt"></i> ${v.sector || 'Circ. 3'}</span>
            </div>
          </div>
          <div class="voter-actions">
            <button class="btn-action-sm" style="background: rgba(37, 211, 102, 0.15); color: #25D366;" 
                    onclick="App.compartirVoucherWhatsApp('${v.id}', '${v.nombres}', '${v.cedula}', '${v.codigo_comprobante || ''}')" title="Compartir WhatsApp">
              <i class="fab fa-whatsapp"></i>
            </button>
            <button class="btn-action-sm" style="background: rgba(0, 210, 255, 0.15); color: var(--accent-cyan);" 
                    onclick="App.verComprobanteModal('${v.id}')" title="Ver Comprobante">
              <i class="fa fa-qrcode"></i>
            </button>
          </div>
        </div>
      `).join('');
    }
  }
};

window.MLView = MLView;
