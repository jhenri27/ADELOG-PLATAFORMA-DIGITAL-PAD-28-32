/**
 * Inicializador Maestro y Helpers UI PWA PAD-28/32
 * Compatible con Android e iOS Mobile Safari
 */

document.addEventListener('DOMContentLoaded', () => {
  // 1. Registro del Service Worker para PWA Instalable
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js')
      .then(reg => console.log('PWA Service Worker registrado con éxito:', reg.scope))
      .catch(err => console.log('Error registrando Service Worker:', err));
  }

  // 2. Inicializador de la Aplicación
  App.init();
});

const UI = {
  showToast(message, type = 'danger') {
    let container = document.getElementById('pwa-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'pwa-toast-container';
      container.className = 'pwa-toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `pwa-toast ${type}`;

    let iconClass = 'fa-exclamation-circle';
    if (type === 'success') iconClass = 'fa-check-circle';
    if (type === 'warning') iconClass = 'fa-bell';

    toast.innerHTML = `
      <i class="fas ${iconClass}"></i>
      <div style="flex:1;">${message}</div>
      <i class="fas fa-times" style="cursor:pointer; opacity:0.7;" onclick="this.parentElement.remove()"></i>
    `;

    container.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-10px)';
      toast.style.transition = 'all 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  }
};

window.UI = UI;

const App = {
  async init() {
    if (Auth.isLoggedIn()) {
      this.renderShell();
      Router.init();
    } else {
      this.showLoginForm();
    }
  },

  showLoginForm() {
    const root = document.getElementById('app-root');
    root.innerHTML = `
      <div style="min-height: 100vh; display: flex; flex-direction: column; justify-content: center; padding: 24px; max-width: 440px; margin: 0 auto;">
        <!-- Header de Marca -->
        <div style="text-align: center; margin-bottom: 24px;">
          <img src="img/logo.png" alt="Logo ADELOG" style="width: 76px; height: 76px; object-fit: contain; filter: drop-shadow(0 4px 14px rgba(0, 210, 255, 0.4)); margin-bottom: 12px;">
          <h2 style="font-family: var(--font-title); font-size: 22px; font-weight: 800; color: #fff; letter-spacing: 0.5px;">PAD-28/32</h2>
          <p style="color: var(--secondary); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Gestión Electoral Móvil</p>
          <p style="color: var(--text-gray); font-size: 12px; margin-top: 4px;">Pastora Altagracia • Circunscripción 3</p>
        </div>

        <!-- Tarjeta de Login -->
        <div class="pwa-card" style="padding: 24px;">
          <div style="margin-bottom: 18px; text-align: center;">
            <span style="font-family: var(--font-title); font-size: 15px; font-weight: 700; color: #fff;">
              <i class="fa fa-lock" style="color: var(--secondary);"></i> Iniciar Sesión de Campo
            </span>
          </div>

          <form id="pwa-login-form" onsubmit="App.handleLogin(event)">
            <div class="form-group">
              <label class="form-label">Usuario, Cédula o Código ML:</label>
              <input type="text" id="login-identifier" class="form-control" placeholder="Ej: admin / ML-0007 / 001-0000000-0" autocapitalize="none" autocorrect="off" spellcheck="false" required autofocus>
            </div>

            <div class="form-group">
              <label class="form-label">Contraseña:</label>
              <input type="password" id="login-password" class="form-control" placeholder="••••••••" autocapitalize="none" autocorrect="off" spellcheck="false" required>
            </div>

            <button type="submit" id="btn-login" class="btn-pwa btn-primary" style="margin-top: 20px;">
              <i class="fa fa-sign-in-alt"></i> Acceder a la Plataforma
            </button>
          </form>
        </div>

        <div style="text-align: center; margin-top: 16px; font-size: 11px; color: var(--text-muted);">
          <span>Certificación de Calidad Electoral • Normas PLAD v4.0</span>
        </div>
      </div>
    `;
  },

  async handleLogin(event) {
    event.preventDefault();
    const identifier = document.getElementById('login-identifier').value.trim();
    const password = document.getElementById('login-password').value.trim();

    if (!identifier || !password) {
      UI.showToast('Ingrese su usuario/cédula y contraseña.', 'danger');
      return;
    }

    const btn = document.getElementById('btn-login');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Verificando credenciales...';
    btn.disabled = true;

    try {
      await Auth.login(identifier, password);
      UI.showToast('¡Bienvenido! Sesión iniciada con éxito.', 'success');
      this.renderShell();
      Router.init();
    } catch (err) {
      btn.innerHTML = originalText;
      btn.disabled = false;
      UI.showToast(err.message || 'Credenciales incorrectas.', 'danger');
    }
  },

  renderShell() {
    const user = Auth.getUser() || {};
    const root = document.getElementById('app-root');

    root.innerHTML = `
      <!-- TOPBAR FIJA SUPERIOR -->
      <header class="pwa-topbar">
        <div class="topbar-brand">
          <img src="img/logo.png" alt="Logo" class="topbar-logo">
          <div class="topbar-title-box">
            <span class="topbar-app-name">PAD-28/32</span>
            <span class="topbar-sub-name">${user.codigo_ml ? user.codigo_ml : (user.role || 'Móvil')}</span>
          </div>
        </div>
        <div class="topbar-actions">
          <span class="topbar-user-badge" onclick="App.abrirConfiguracion()" style="cursor: pointer;" title="Mi Perfil y Configuración">
            <i class="fa fa-user-circle"></i> ${user.nombre ? user.nombre.split(' ')[0] : 'Usuario'}
          </span>
          <button class="btn-icon-topbar" onclick="App.abrirConfiguracion()" title="Configuración y Enlaces">
            <i class="fa fa-cog"></i>
          </button>
        </div>
      </header>

      <!-- ÁREA DE CONTENIDO DINÁMICO DE LA VISTA -->
      <main id="view-content"></main>

      <!-- BARRA DE NAVEGACIÓN FIJA INFERIOR (FAIL-004) -->
      <nav class="pwa-navbar">
        <a class="nav-item" data-route="dashboard" onclick="Router.navigate('dashboard')">
          <i class="fa fa-chart-pie"></i>
          <span>Inicio</span>
        </a>
        <a class="nav-item" data-route="consultar" onclick="Router.navigate('consultar')">
          <i class="fa fa-search"></i>
          <span>Consultar</span>
        </a>
        <div class="nav-fab" onclick="Router.navigate('inscribir')" title="Nueva Inscripción">
          <i class="fa fa-plus"></i>
        </div>
        <a class="nav-item" data-route="mis-inscritos" onclick="Router.navigate('mis-inscritos')">
          <i class="fa fa-users"></i>
          <span>Inscritos</span>
        </a>
        <a class="nav-item" data-route="perfil" onclick="Router.navigate('perfil')">
          <i class="fa fa-user"></i>
          <span>Perfil</span>
        </a>
      </nav>
    `;
  },

  showModal(title, bodyHtml, footerHtml = '') {
    const overlay = document.getElementById('pwa-modal-overlay');
    const titleEl = document.getElementById('pwa-modal-title');
    const bodyEl = document.getElementById('pwa-modal-body');
    const footerEl = document.getElementById('pwa-modal-footer');

    if (titleEl) titleEl.innerText = title;
    if (bodyEl) bodyEl.innerHTML = bodyHtml;
    if (footerEl) footerEl.innerHTML = footerHtml;

    if (overlay) overlay.style.display = 'flex';
  },

  closeModal() {
    const overlay = document.getElementById('pwa-modal-overlay');
    if (overlay) overlay.style.display = 'none';
  },

  // Generador y compartidor de comprobante vía WhatsApp (Web Share API)
  compartirVoucherWhatsApp(voterId, nombres, cedula, folio) {
    const cleanCed = String(cedula).replace(/\D/g, '');
    const cleanFolio = folio || `PAD2832-${cleanCed}`;
    const idx = window.location.pathname.indexOf('/frontend/pwa');
    const basePath = idx !== -1 ? window.location.pathname.substring(0, idx) : '/PLATAFORMA%20DIGITAL-PAD-28-32';
    const urlValidar = `${window.location.origin}${basePath}/validar.php?folio=${encodeURIComponent(cleanFolio)}&cedula=${encodeURIComponent(cleanCed)}`;
    
    const textoMensaje = `¡Hola ${nombres}! 🎉\n\nHas sido registrado exitosamente en el *Padrón de Apoyo a Pastora Altagracia* (Santo Domingo Este - Circunscripción 3).\n\n📄 *Tu Folio Oficial:* ${cleanFolio}\n🆔 *Cédula:* ${API.formatearCedula(cedula)}\n\nPuedes consultar y certificar tu comprobante oficial con código QR aquí:\n👉 ${urlValidar}\n\n_Plataforma Electoral PAD-28/32 • Normas PLAD_`;

    if (navigator.share) {
      navigator.share({
        title: 'Comprobante de Inscripción Electoral PAD-28/32',
        text: textoMensaje,
        url: urlValidar
      }).catch(() => {
        // Fallback a enlace directo de WhatsApp
        window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(textoMensaje)}`, '_blank');
      });
    } else {
      window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(textoMensaje)}`, '_blank');
    }
  },

  // Ver comprobante con código QR en Modal
  verComprobanteModal(voterId) {
    const idx = window.location.pathname.indexOf('/frontend/pwa');
    const basePath = idx !== -1 ? window.location.pathname.substring(0, idx) : '/PLATAFORMA%20DIGITAL-PAD-28-32';
    const urlValidar = `${window.location.origin}${basePath}/comprobante.php?id=${voterId}`;
    
    const bodyHtml = `
      <div class="voucher-box">
        <div class="voucher-header">
          <h4>COMPROBANTE OFICIAL</h4>
          <p style="font-size: 11px; color: var(--text-muted);">Pastora Altagracia • Circ. 3</p>
        </div>

        <div class="voucher-qr-wrap" id="modal-qr-target"></div>

        <p style="font-size: 11px; color: #475569;">
          Código QR de validación en tiempo real (PLAD-CERT-QR-01).
        </p>

        <div style="margin-top: 14px;">
          <a href="${urlValidar}" target="_blank" class="btn-pwa btn-outline" style="color: #002F6C; border-color: #002F6C; height: 38px; font-size: 12px; text-decoration: none;">
            <i class="fa fa-external-link-alt"></i> Abrir Comprobante Completo
          </a>
        </div>
      </div>
    `;

    this.showModal('Auditoría de Elector', bodyHtml, `<button class="btn-pwa btn-primary" onclick="App.closeModal()">Cerrar</button>`);

    setTimeout(() => {
      const qrTarget = document.getElementById('modal-qr-target');
      if (qrTarget && typeof QRCode !== 'undefined') {
        qrTarget.innerHTML = '';
        new QRCode(qrTarget, {
          text: urlValidar,
          width: 140,
          height: 140,
          colorDark: '#002F6C',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      }
    }, 100);
  },

  // Abrir vista de Perfil, Configuración y Control de Valor
  abrirConfiguracion() {
    Router.navigate('perfil');
    if (window.location.hash === '#perfil') {
      Router.handleRoute();
    }
  },

  // Copiar al portapapeles con fallback y Toast feedback
  copiarAlPortapapeles(texto, label = 'Enlace') {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(texto).then(() => {
        UI.showToast(`¡${label} copiado al portapapeles!`, 'success');
      }).catch(() => {
        this.fallbackCopiar(texto, label);
      });
    } else {
      this.fallbackCopiar(texto, label);
    }
  },

  fallbackCopiar(texto, label) {
    const input = document.createElement('textarea');
    input.value = texto;
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.select();
    try {
      document.execCommand('copy');
      UI.showToast(`¡${label} copiado al portapapeles!`, 'success');
    } catch (e) {
      prompt(`Copie el siguiente ${label}:`, texto);
    }
    document.body.removeChild(input);
  },

  // Compartir Enlace vía Web Share API o WhatsApp directo
  compartirEnlace(titulo, texto, url) {
    const fullText = `${texto}\n👉 ${url}`;
    if (navigator.share) {
      navigator.share({
        title: titulo,
        text: texto,
        url: url
      }).catch(() => {
        window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(fullText)}`, '_blank');
      });
    } else {
      window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(fullText)}`, '_blank');
    }
  },

  // Modal para Visualizar Código QR de Enlaces de Captación
  mostrarQRModal(titulo, url, subtitulo) {
    const bodyHtml = `
      <div class="voucher-box" style="text-align: center;">
        <div class="voucher-header">
          <h4>${titulo}</h4>
          <p style="font-size: 11px; color: var(--text-muted);">${subtitulo || 'Escanea para acceder directamente'}</p>
        </div>

        <div class="voucher-qr-wrap" id="modal-qr-enlace" style="margin: 15px auto;"></div>

        <div style="background: rgba(0,0,0,0.05); padding: 8px 12px; border-radius: 8px; font-family: monospace; font-size: 11px; word-break: break-all; color: #1e293b; margin-top: 10px;">
          ${url}
        </div>

        <div style="margin-top: 15px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
          <button class="btn-pwa btn-primary" style="height: 38px; font-size: 12px;" onclick="App.copiarAlPortapapeles('${url}', 'Enlace')">
            <i class="fa fa-copy"></i> Copiar
          </button>
          <button class="btn-pwa btn-whatsapp" style="height: 38px; font-size: 12px;" onclick="App.compartirEnlace('${titulo}', 'Regístrate aquí: ', '${url}')">
            <i class="fab fa-whatsapp"></i> WhatsApp
          </button>
        </div>
      </div>
    `;

    this.showModal(titulo, bodyHtml, `<button class="btn-pwa btn-primary" onclick="App.closeModal()">Cerrar</button>`);

    setTimeout(() => {
      const qrTarget = document.getElementById('modal-qr-enlace');
      if (qrTarget && typeof QRCode !== 'undefined') {
        qrTarget.innerHTML = '';
        new QRCode(qrTarget, {
          text: url,
          width: 160,
          height: 160,
          colorDark: '#002F6C',
          colorLight: '#ffffff',
          correctLevel: QRCode.CorrectLevel.M
        });
      }
    }, 100);
  }
};

window.App = App;
