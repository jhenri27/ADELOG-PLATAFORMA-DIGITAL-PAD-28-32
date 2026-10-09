/**
 * Enrutador SPA Hash-based para PWA PAD-28/32
 */

const Router = {
  currentRoute: 'dashboard',

  init() {
    window.addEventListener('hashchange', () => this.handleRoute());
    this.handleRoute();
  },

  navigate(route) {
    window.location.hash = '#' + route;
  },

  handleRoute() {
    if (!Auth.isLoggedIn()) {
      App.showLoginForm();
      return;
    }

    const hash = window.location.hash.replace('#', '').trim();
    this.currentRoute = hash || 'dashboard';

    // Actualizar clase activa en la barra de navegación inferior
    document.querySelectorAll('.pwa-navbar .nav-item').forEach(el => {
      const target = el.getAttribute('data-route');
      if (target === this.currentRoute) {
        el.classList.add('active');
      } else {
        el.classList.remove('active');
      }
    });

    const contentArea = document.getElementById('view-content');
    if (!contentArea) {
      App.renderShell();
      return;
    }

    // Montar la vista correspondiente
    switch (this.currentRoute) {
      case 'dashboard':
        if (Auth.isML() || Auth.isDigitador()) {
          MLView.render(contentArea);
        } else {
          CoordinadorView.render(contentArea);
        }
        break;

      case 'inscribir':
        InscripcionView.render(contentArea);
        break;

      case 'consultar':
        ConsultaView.render(contentArea);
        break;

      case 'mis-inscritos':
        MisInscritosView.render(contentArea);
        break;

      case 'perfil':
        this.renderPerfil(contentArea);
        break;

      default:
        this.navigate('dashboard');
        break;
    }

    window.scrollTo(0, 0);
  },

  async renderPerfil(container) {
    const user = Auth.getUser() || {};
    const refCode = user.codigo_ml || user.username || ('USER-' + user.id);
    const urlSimpatizante = API.getPublicUrl('registro.php?canal=pwa_simpatizante&ref=' + encodeURIComponent(refCode));
    const urlML = API.getPublicUrl('registro.php?tipo=ml&canal=red_ml&ref=' + encodeURIComponent(refCode));
    const metaAsignada = (Auth.isML() ? 25 : (Auth.isDigitador() ? 50 : 200));

    container.innerHTML = `
      <div class="pwa-container" style="padding-bottom: 30px;">
        
        <!-- ENCABEZADO DE VISTA -->
        <div style="margin-bottom: 16px;">
          <h2 style="font-family: var(--font-title); font-size: 20px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
            <i class="fa fa-user-cog" style="color: var(--secondary);"></i> Perfil y Configuración
          </h2>
          <p style="color: var(--text-gray); font-size: 12px; margin-top: 2px;">
            Gestión de cuenta, desempeño electoral en vivo y enlaces oficiales
          </p>
        </div>

        <!-- 1. TARJETA DE IDENTIDAD DEL USUARIO -->
        <div class="pwa-card" style="padding: 24px 18px; text-align: center; border: 1px solid var(--border-highlight); background: linear-gradient(180deg, var(--card-bg-light) 0%, var(--card-bg) 100%); margin-bottom: 16px;">
          <div style="position: relative; display: inline-block; margin-bottom: 12px;">
            <div style="width: 76px; height: 76px; border-radius: 50%; background: linear-gradient(135deg, var(--primary-light), var(--primary)); display: flex; align-items: center; justify-content: center; font-size: 32px; color: #fff; margin: 0 auto; border: 3px solid var(--border-highlight); box-shadow: 0 4px 16px rgba(0, 84, 166, 0.4);">
              <i class="fa ${Auth.isAdmin() ? 'fa-user-shield' : (Auth.isCoordinator() ? 'fa-user-tie' : 'fa-user-check')}"></i>
            </div>
            <span style="position: absolute; bottom: 0; right: -4px; background: var(--secondary); color: #000; font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 10px; border: 2px solid var(--card-bg);">
              ${user.codigo_ml || 'OFICIAL'}
            </span>
          </div>

          <h3 style="font-family: var(--font-title); font-size: 19px; font-weight: 700; color: #fff; margin-bottom: 4px;">
            ${user.nombre || user.username}
          </h3>

          <div style="display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; margin-bottom: 10px;">
            <span style="background: rgba(227, 161, 21, 0.15); border: 1px solid rgba(227, 161, 21, 0.4); color: var(--secondary); font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 12px; text-transform: uppercase;">
              ${user.role || 'Militante Líder'}
            </span>
            ${user.codigo_ml ? `<span style="background: rgba(0, 210, 255, 0.12); border: 1px solid rgba(0, 210, 255, 0.3); color: var(--accent-cyan); font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 12px;">${user.codigo_ml}</span>` : ''}
          </div>

          <p style="color: var(--text-gray); font-size: 12px;">
            <i class="fa fa-map-marker-alt" style="color: var(--accent-red); margin-right: 4px;"></i>
            Santo Domingo Este • Circunscripción 3
          </p>
          <p style="color: var(--text-muted); font-size: 11px; margin-top: 2px;">
            Proyecto Político Diputada Pastora Altagracia De Los Santos
          </p>

          <!-- Desglose de Datos -->
          <div style="margin-top: 18px; text-align: left; background: rgba(0,0,0,0.25); border-radius: 10px; padding: 14px; font-size: 13px; line-height: 2.1;">
            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed rgba(255,255,255,0.08);">
              <span style="color: var(--text-gray);"><i class="fa fa-id-card" style="width: 18px; color: var(--accent-cyan);"></i> Cédula:</span>
              <span style="color: #fff; font-weight: 600; font-family: monospace;">${API.formatearCedula(user.cedula) || '—'}</span>
            </div>
            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed rgba(255,255,255,0.08);">
              <span style="color: var(--text-gray);"><i class="fa fa-user" style="width: 18px; color: var(--accent-cyan);"></i> Usuario:</span>
              <span style="color: #fff; font-weight: 600;">${user.username || '—'}</span>
            </div>
            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed rgba(255,255,255,0.08);">
              <span style="color: var(--text-gray);"><i class="fa fa-network-wired" style="width: 18px; color: var(--secondary);"></i> Código de Red:</span>
              <span style="color: var(--accent-cyan); font-weight: 700;">${refCode}</span>
            </div>
            <div style="display: flex; justify-content: space-between;">
              <span style="color: var(--text-gray);"><i class="fa fa-award" style="width: 18px; color: var(--secondary);"></i> Nivel de Avance:</span>
              <span style="color: var(--secondary); font-weight: 700;">${user.nivel_avance_label || user.role}</span>
            </div>
          </div>
        </div>

        <!-- 2. CONTROL DE VALOR ELECTORAL (MÉTRICAS Y GAMIFICACIÓN) -->
        <div class="pwa-card" style="padding: 18px; margin-bottom: 16px;">
          <div class="pwa-card-header" style="margin-bottom: 14px;">
            <h4 class="pwa-card-title">
              <i class="fa fa-chart-line" style="color: var(--secondary);"></i> Control de Valor Electoral
            </h4>
            <span id="cv-status-badge" style="font-size: 11px; color: var(--accent-cyan); font-weight: 600;">
              <i class="fa fa-sync fa-spin"></i> Cargando...
            </span>
          </div>

          <!-- Grid de Métricas Principales -->
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
            <div style="background: rgba(0, 84, 166, 0.15); border: 1px solid var(--border-highlight); border-radius: 10px; padding: 12px; text-align: center;">
              <span style="color: var(--text-gray); font-size: 11px; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 4px;">Electores Aportados</span>
              <span id="cv-total-electores" style="font-family: var(--font-title); font-size: 26px; font-weight: 800; color: #fff;">—</span>
              <span style="font-size: 10px; color: var(--accent-green); display: block; margin-top: 2px;">
                <i class="fa fa-check-circle"></i> En Padrón 2028
              </span>
            </div>

            <div style="background: rgba(227, 161, 21, 0.12); border: 1px solid rgba(227, 161, 21, 0.3); border-radius: 10px; padding: 12px; text-align: center;">
              <span style="color: var(--text-gray); font-size: 11px; text-transform: uppercase; font-weight: 700; display: block; margin-bottom: 4px;">Líderes en Red (ML)</span>
              <span id="cv-total-ml" style="font-family: var(--font-title); font-size: 26px; font-weight: 800; color: var(--secondary);">—</span>
              <span style="font-size: 10px; color: var(--secondary); display: block; margin-top: 2px;">
                <i class="fa fa-users"></i> Multiplicadores
              </span>
            </div>
          </div>

          <!-- Barra de Meta y Cumplimiento -->
          <div style="background: rgba(0,0,0,0.25); border-radius: 10px; padding: 14px; margin-bottom: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; font-size: 12px;">
              <span style="color: var(--text-gray);">Meta Asignada: <strong style="color: #fff;" id="cv-meta-num">${metaAsignada} electores</strong></span>
              <span id="cv-porcentaje" style="color: var(--accent-cyan); font-weight: 800; font-size: 13px;">0%</span>
            </div>
            <div style="height: 10px; background: rgba(255,255,255,0.1); border-radius: 5px; overflow: hidden;">
              <div id="cv-progress-bar" style="width: 0%; height: 100%; background: linear-gradient(90deg, var(--primary-light), var(--accent-cyan)); transition: width 0.6s ease; border-radius: 5px;"></div>
            </div>
          </div>

          <!-- Nivel Gamificado de Liderazgo -->
          <div id="cv-nivel-box" style="display: flex; align-items: center; gap: 12px; background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 10px; padding: 12px;">
            <div id="cv-nivel-icono" style="width: 44px; height: 44px; border-radius: 50%; background: rgba(0, 210, 255, 0.15); border: 1px solid var(--accent-cyan); display: flex; align-items: center; justify-content: center; font-size: 20px; color: var(--accent-cyan); flex-shrink: 0;">
              <i class="fa fa-medal"></i>
            </div>
            <div style="flex: 1;">
              <div style="font-size: 11px; color: var(--text-gray); text-transform: uppercase; font-weight: 700;">Nivel de Liderazgo Político</div>
              <div id="cv-nivel-titulo" style="font-family: var(--font-title); font-size: 14px; font-weight: 700; color: #fff;">Calculando desempeño...</div>
              <div id="cv-nivel-desc" style="font-size: 11px; color: var(--text-muted); margin-top: 1px;">Evaluando cuota en Circunscripción 3.</div>
            </div>
          </div>
        </div>

        <!-- 3. ENLACES OFICIALES DE CAPTACIÓN Y RED TERRITORIAL -->
        <div class="pwa-card" style="padding: 18px; margin-bottom: 16px;">
          <div class="pwa-card-header" style="margin-bottom: 8px;">
            <h4 class="pwa-card-title">
              <i class="fa fa-share-alt" style="color: var(--secondary);"></i> Enlaces Oficiales de Captación
            </h4>
          </div>
          <p style="font-size: 12px; color: var(--text-gray); margin-bottom: 18px; line-height: 1.4;">
            Comparte tus enlaces exclusivos. Cada elector o líder que se registre quedará vinculado automáticamente a tu red electoral.
          </p>

          <!-- ENLACE 1: MILITANTE SIMPATIZANTE (VOTANTE GENERAL) -->
          <div style="background: rgba(0,0,0,0.25); border: 1px solid var(--border-highlight); border-radius: 12px; padding: 14px; margin-bottom: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
              <span style="font-size: 13px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 6px;">
                <i class="fa fa-user-plus" style="color: var(--accent-green);"></i> 1. Militante Simpatizante
              </span>
              <span style="background: rgba(16, 185, 129, 0.15); border: 1px solid #10B981; color: #10B981; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 8px;">
                Votante General
              </span>
            </div>
            <p style="font-size: 11px; color: var(--text-gray); margin-bottom: 10px;">
              Enlace público para captar votantes directos de tu sector, amigos y familiares en apoyo a Pastora Altagracia.
            </p>

            <div style="background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 8px 10px; font-family: monospace; font-size: 11px; color: var(--accent-cyan); word-break: break-all; margin-bottom: 10px;">
              ${urlSimpatizante}
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px;">
              <button class="btn-pwa btn-outline" style="height: 38px; font-size: 11px; padding: 0 6px;" onclick="App.copiarAlPortapapeles('${urlSimpatizante}', 'Enlace de Militante Simpatizante')">
                <i class="fa fa-copy"></i> Copiar
              </button>
              <button class="btn-pwa btn-whatsapp" style="height: 38px; font-size: 11px; padding: 0 6px;" onclick="App.compartirEnlace('Inscripción Electoral PAD-28/32', '¡Hola! Te invito a inscribirte en apoyo a la candidatura de Pastora Altagracia (Circ. 3 Santo Domingo Este). Regístrate aquí:', '${urlSimpatizante}')">
                <i class="fab fa-whatsapp"></i> WhatsApp
              </button>
              <button class="btn-pwa btn-primary" style="height: 38px; font-size: 11px; padding: 0 6px;" onclick="App.mostrarQRModal('Militante Simpatizante', '${urlSimpatizante}', 'Escanea para inscribirte en apoyo a Pastora Altagracia')">
                <i class="fa fa-qrcode"></i> Ver QR
              </button>
            </div>
          </div>

          <!-- ENLACE 2: MILITANTE LÍDER (ML) CON ACCESO A LA PWA -->
          <div style="background: rgba(227, 161, 21, 0.05); border: 1.5px solid rgba(227, 161, 21, 0.4); border-radius: 12px; padding: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
              <span style="font-size: 13px; font-weight: 700; color: var(--secondary); display: flex; align-items: center; gap: 6px;">
                <i class="fa fa-star" style="color: var(--secondary);"></i> 2. Militante Líder (ML)
              </span>
              <span style="background: rgba(227, 161, 21, 0.2); border: 1px solid var(--secondary); color: var(--secondary); font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 8px;">
                Acceso PWA
              </span>
            </div>
            <p style="font-size: 11px; color: var(--text-gray); margin-bottom: 10px;">
              Para inscribir a nuevos líderes multiplicadores. Al registrarse, el sistema les genera su <strong>Código ML</strong> y contraseña (su cédula) para que ingresen de inmediato a esta PWA y lideren su propia red.
            </p>

            <div style="background: rgba(0,0,0,0.35); border: 1px solid rgba(227, 161, 21, 0.25); border-radius: 8px; padding: 8px 10px; font-family: monospace; font-size: 11px; color: var(--secondary); word-break: break-all; margin-bottom: 10px;">
              ${urlML}
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px;">
              <button class="btn-pwa btn-outline" style="height: 38px; font-size: 11px; padding: 0 6px;" onclick="App.copiarAlPortapapeles('${urlML}', 'Enlace de Militante Líder (ML)')">
                <i class="fa fa-copy"></i> Copiar
              </button>
              <button class="btn-pwa btn-whatsapp" style="height: 38px; font-size: 11px; padding: 0 6px;" onclick="App.compartirEnlace('Únete como Militante Líder (ML)', '¡Hola! Te invito a formar parte del equipo de líderes de Pastora Altagracia (Circ. 3 SDE). Al registrarte tendrás acceso directo a la App móvil PWA para gestionar tu red:', '${urlML}')">
                <i class="fab fa-whatsapp"></i> WhatsApp
              </button>
              <button class="btn-pwa btn-primary" style="height: 38px; font-size: 11px; padding: 0 6px; background: linear-gradient(135deg, #E3A115, #B87E00); border-color: #E3A115; color: #000; font-weight: 700;" onclick="App.mostrarQRModal('Militante Líder (ML)', '${urlML}', 'Escanea para registrarte como Líder y acceder a la PWA')">
                <i class="fa fa-qrcode"></i> Ver QR
              </button>
            </div>
          </div>
        </div>

        <!-- 4. SESIÓN Y SOPORTE TÉCNICO -->
        <div class="pwa-card" style="padding: 16px; text-align: center;">
          <div style="font-size: 11px; color: var(--text-muted); margin-bottom: 12px;">
            <span>PAD-28/32 PWA v1.5 • Normas PLAD v4.0</span><br>
            <span>Certificación Electoral • Circunscripción 3 (SDE)</span>
          </div>

          <button class="btn-pwa" style="background: rgba(239, 68, 68, 0.15); border: 1.5px solid rgba(239, 68, 68, 0.5); color: #EF4444; font-weight: 700; height: 46px; font-size: 14px;" onclick="Auth.logout()">
            <i class="fa fa-sign-out-alt"></i> Cerrar Sesión Segura
          </button>
        </div>

      </div>
    `;

    // Cargar métricas en vivo para el Control de Valor
    this.cargarControlDeValor(user, metaAsignada);
  },

  async cargarControlDeValor(user, meta = 25) {
    try {
      const res = await API.getVotantes();
      const voters = (res && res.votantes) ? res.votantes : ((res && res.data) ? res.data : []);

      const totalElectores = voters.length;
      const totalML = voters.filter(v => v.es_militante_lider == 1).length;

      const elTotal = document.getElementById('cv-total-electores');
      const elML = document.getElementById('cv-total-ml');
      const elStatus = document.getElementById('cv-status-badge');
      const elPorcentaje = document.getElementById('cv-porcentaje');
      const elProgress = document.getElementById('cv-progress-bar');
      const elNivelTitulo = document.getElementById('cv-nivel-titulo');
      const elNivelDesc = document.getElementById('cv-nivel-desc');
      const elNivelIcono = document.getElementById('cv-nivel-icono');

      if (elTotal) elTotal.innerText = totalElectores;
      if (elML) elML.innerText = totalML;
      if (elStatus) elStatus.innerHTML = '<i class="fa fa-check-circle" style="color: var(--accent-green);"></i> Sincronizado';

      const pct = Math.min(100, Math.round((totalElectores / meta) * 100));
      if (elPorcentaje) elPorcentaje.innerText = `${pct}%`;
      if (elProgress) elProgress.style.width = `${pct}%`;

      // Nivel gamificado de Liderazgo
      if (elNivelTitulo && elNivelDesc && elNivelIcono) {
        if (totalElectores >= meta) {
          elNivelTitulo.innerText = 'Nivel Diamante — Meta Cumplida';
          elNivelTitulo.style.color = 'var(--secondary)';
          elNivelDesc.innerText = `¡Extraordinario! Has alcanzado ${totalElectores} electores aportados. Tu liderazgo territorial está plenamente consolidado.`;
          elNivelIcono.innerHTML = '<i class="fa fa-crown" style="color: #FFD700;"></i>';
          elNivelIcono.style.borderColor = '#FFD700';
          elNivelIcono.style.background = 'rgba(255, 215, 0, 0.15)';
        } else if (totalElectores >= 20) {
          elNivelTitulo.innerText = 'Nivel Oro — Liderazgo Consolidado';
          elNivelTitulo.style.color = '#F59E0B';
          elNivelDesc.innerText = `A solo ${meta - totalElectores} electores de completar tu meta total. Continúa multiplicando tu red.`;
          elNivelIcono.innerHTML = '<i class="fa fa-medal" style="color: #F59E0B;"></i>';
          elNivelIcono.style.borderColor = '#F59E0B';
          elNivelIcono.style.background = 'rgba(245, 158, 11, 0.15)';
        } else if (totalElectores >= 10) {
          elNivelTitulo.innerText = 'Nivel Plata — En Crecimiento Activo';
          elNivelTitulo.style.color = 'var(--accent-cyan)';
          elNivelDesc.innerText = `Llevas un excelente ritmo con ${totalElectores} electores. Sigue compartiendo tus enlaces oficiales.`;
          elNivelIcono.innerHTML = '<i class="fa fa-chart-line" style="color: var(--accent-cyan);"></i>';
          elNivelIcono.style.borderColor = 'var(--accent-cyan)';
          elNivelIcono.style.background = 'rgba(0, 210, 255, 0.15)';
        } else {
          elNivelTitulo.innerText = 'Nivel Bronce — Iniciador de Red';
          elNivelTitulo.style.color = '#9BB0CD';
          elNivelDesc.innerText = `Empieza compartiendo tus enlaces por WhatsApp para sumar tus primeros electores y líderes.`;
          elNivelIcono.innerHTML = '<i class="fa fa-seedling" style="color: #10B981;"></i>';
          elNivelIcono.style.borderColor = '#10B981';
          elNivelIcono.style.background = 'rgba(16, 185, 129, 0.15)';
        }
      }
    } catch (err) {
      console.log('Error al cargar Control de Valor:', err);
      const elStatus = document.getElementById('cv-status-badge');
      if (elStatus) elStatus.innerHTML = '<span style="color: var(--text-muted);">Modo Local</span>';
    }
  }
};

window.Router = Router;
