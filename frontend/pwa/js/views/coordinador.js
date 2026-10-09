/**
 * Vista de Dashboard para Coordinadores PWA PAD-28/32
 * Supervisión Táctica de Red Territorial y Monitoreo de Militantes Líderes (ML)
 */

const CoordinadorView = {
  async render(container) {
    const user = Auth.getUser() || {};

    container.innerHTML = `
      <div class="pwa-container">
        <!-- Saludo y Rol Coordinador -->
        <div style="margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center;">
          <div>
            <h3 style="font-family: var(--font-title); font-size: 17px; font-weight: 800; color: #fff;">
              ¡Hola, ${user.nombre ? user.nombre.split(' ')[0] : 'Coordinador'}!
            </h3>
            <p style="font-size: 11px; color: var(--secondary); font-weight: 600; text-transform: uppercase;">
              ${user.role || 'Coordinador de Zona'} • Circunscripción 3
            </p>
          </div>
          <span class="meta-badge" style="background: rgba(0, 210, 255, 0.15); color: var(--accent-cyan); border-color: rgba(0, 210, 255, 0.3);">
            <i class="fa ${Auth.isAdmin() ? 'fa-shield-alt' : 'fa-users-cog'}"></i> ${Auth.isAdmin() ? 'Admin General' : (user.role || 'Supervisión')}
          </span>
        </div>

        <!-- KPIS TERRITORIALES -->
        <div class="kpi-grid" id="coord-kpis-container">
          <div class="kpi-card">
            <div class="kpi-value" id="kpi-total-red">...</div>
            <div class="kpi-label">Total Red</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-value" id="kpi-total-ml">...</div>
            <div class="kpi-label">MLs Activos</div>
          </div>
          <div class="kpi-card">
            <div class="kpi-value" style="color: var(--secondary);" id="kpi-meta-pct">...</div>
            <div class="kpi-label">Meta Red</div>
          </div>
        </div>

        <!-- ACCIONES RÁPIDAS -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 18px;">
          <button class="btn-pwa btn-primary" onclick="Router.navigate('inscribir')">
            <i class="fa fa-user-plus"></i> Inscribir Elector
          </button>
          <button class="btn-pwa btn-gold" onclick="Router.navigate('consultar')">
            <i class="fa fa-search"></i> Consultar Cédula
          </button>
        </div>

        <!-- MONITOREO DE MILITANTES LÍDERES (ML) -->
        <div class="pwa-card">
          <div class="pwa-card-header">
            <h4 class="pwa-card-title"><i class="fa fa-sitemap"></i> Monitoreo de MLs en Red</h4>
            <span style="font-size: 11px; color: var(--text-gray);" id="coord-count-ml-label">0 líderes</span>
          </div>
          <div id="coord-mls-list">
            <div style="text-align: center; padding: 20px; color: var(--text-muted); font-size: 12px;">
              <i class="fa fa-spinner fa-spin"></i> Cargando líderes de mesa...
            </div>
          </div>
        </div>

        <!-- RESUMEN DE ELECTORES EN RED -->
        <div class="pwa-card">
          <div class="pwa-card-header">
            <h4 class="pwa-card-title"><i class="fa fa-users"></i> Padrón Territorial</h4>
            <a href="#mis-inscritos" style="color: var(--accent-cyan); font-size: 12px; font-weight: 600; text-decoration: none;">Ver Todos</a>
          </div>
          <div id="coord-recent-voters">
            <div style="text-align: center; padding: 15px; color: var(--text-muted); font-size: 12px;">
              <i class="fa fa-spinner fa-spin"></i> Cargando electores...
            </div>
          </div>
        </div>
      </div>
    `;

    this.cargarDatosCoordinador(user);
  },

  async cargarDatosCoordinador(user) {
    try {
      // 1. Obtener estadísticas de coordinadores
      const resStats = await API.getCoordinadoresStats();
      const coords = resStats?.coordinadores || [];

      // Buscar estadísticas del usuario o calcular total
      let miRed = coords.find(c => c.nombre === user.nombre) || {
        total_red: 0,
        total_ml: 0,
        total_directos: 0,
        meta: 200,
        porcentaje_meta: 0
      };

      // Si es Admin o Jefe Electoral, sumar global
      if ((Auth.isAdmin() || user.role === 'Jefe Electoral' || user.role === 'Coordinador General') && coords.length > 0) {
        const sumRed = coords.reduce((acc, c) => acc + (c.total_red || 0), 0);
        const sumML = coords.reduce((acc, c) => acc + (c.total_ml || 0), 0);
        miRed = {
          total_red: sumRed,
          total_ml: sumML,
          meta: 1000,
          porcentaje_meta: Math.min(100, Math.round((sumRed / 1000) * 100))
        };
      }

      const elRed = document.getElementById('kpi-total-red');
      const elML = document.getElementById('kpi-total-ml');
      const elMeta = document.getElementById('kpi-meta-pct');

      if (elRed) elRed.innerText = miRed.total_red;
      if (elML) elML.innerText = miRed.total_ml;
      if (elMeta) elMeta.innerText = `${miRed.porcentaje_meta}%`;

      // 2. Cargar lista de MLs subordinados o de la red
      let mls = coords.filter(c => c.tipo && c.tipo.includes('ML') || (c.rol && c.rol.includes('ML')));
      if (!Auth.isAdmin() && user.role !== 'Coordinador General' && user.role !== 'Jefe Electoral') {
        mls = mls.filter(m => m.coordinador_padre === user.nombre || m.coordinador_padre === user.username);
      }
      const mlsContainer = document.getElementById('coord-mls-list');
      const lblCount = document.getElementById('coord-count-ml-label');

      if (lblCount) lblCount.innerText = `${mls.length} activos`;

      if (mlsContainer) {
        if (mls.length === 0) {
          mlsContainer.innerHTML = `
            <div style="text-align: center; padding: 15px; color: var(--text-gray); font-size: 12px;">
              No hay Militantes Líderes (ML) asignados a tu red actualmente.
            </div>
          `;
        } else {
          mlsContainer.innerHTML = mls.map(m => {
            const pct = Math.min(100, Math.round(((m.total_red || 0) / 25) * 100));
            const telClean = (m.telefono || '').replace(/\D/g, '');
            const msgWA = encodeURIComponent(`¡Hola ${m.nombre}! Te escribo para dar seguimiento a tu meta de 25 electores en PAD-28/32. Llevas ${m.total_red || 0} registrados. ¡Vamos con todo!`);
            const urlWA = telClean ? `https://api.whatsapp.com/send?phone=1${telClean}&text=${msgWA}` : '#';

            return `
              <div class="voter-item-card" style="margin-bottom: 8px;">
                <div class="voter-info">
                  <div class="voter-name">${m.nombre}</div>
                  <div class="voter-cedula" style="color: var(--secondary);">
                    ${m.id?.replace('i_', 'ML-') || 'ML'} • ${m.total_red || 0} / 25 captados (${pct}%)
                  </div>
                  <div style="height: 5px; background: rgba(255,255,255,0.1); border-radius: 10px; margin-top: 4px; overflow: hidden;">
                    <div style="height: 100%; width: ${pct}%; background: ${pct >= 100 ? '#10B981' : 'var(--secondary)'};"></div>
                  </div>
                </div>
                <div class="voter-actions">
                  ${telClean ? `
                    <a href="${urlWA}" target="_blank" class="btn-action-sm" style="background: rgba(37, 211, 102, 0.15); color: #25D366; text-decoration: none;" title="Mensaje de Ánimo WhatsApp">
                      <i class="fab fa-whatsapp"></i>
                    </a>
                    <a href="tel:${telClean}" class="btn-action-sm" style="background: rgba(0, 210, 255, 0.15); color: var(--accent-cyan); text-decoration: none;" title="Llamar">
                      <i class="fa fa-phone"></i>
                    </a>
                  ` : `
                    <span style="font-size: 10px; color: var(--text-muted);">Sin Tel.</span>
                  `}
                </div>
              </div>
            `;
          }).join('');
        }
      }

      // 3. Cargar últimos votantes del padrón territorial
      const resVoters = await API.getVotantes({ limit: 5 });
      const recentContainer = document.getElementById('coord-recent-voters');
      const vList = resVoters?.voters || resVoters?.votantes || [];
      if (recentContainer) {
        if (vList.length === 0) {
          recentContainer.innerHTML = '<div style="text-align: center; padding: 15px; color: var(--text-gray); font-size: 12px;">No hay electores registrados en tu red actualmente.</div>';
        } else {
          recentContainer.innerHTML = vList.slice(0, 5).map(v => `
          <div class="voter-item-card">
            <div class="voter-info">
              <div class="voter-name">${v.nombres} ${v.apellidos}</div>
              <div class="voter-cedula">${API.formatearCedula(v.cedula)}</div>
              <div class="voter-meta">
                <span><i class="fa fa-user-tag"></i> Por: ${v.coordinador || '—'}</span>
                <span><i class="fa fa-map-marker-alt"></i> ${v.sector || 'Circ. 3'}</span>
              </div>
            </div>
            <div class="voter-actions">
              <button class="btn-action-sm" style="background: rgba(37, 211, 102, 0.15); color: #25D366;" 
                      onclick="App.compartirVoucherWhatsApp('${v.id}', '${v.nombres}', '${v.cedula}', '${v.codigo_comprobante || ''}')" title="Compartir WhatsApp">
                <i class="fab fa-whatsapp"></i>
              </button>
            </div>
          </div>
        `).join('');
        }
      }
    } catch (e) {
      console.warn('Error cargando métricas de coordinador:', e);
    }
  }
};

window.CoordinadorView = CoordinadorView;
