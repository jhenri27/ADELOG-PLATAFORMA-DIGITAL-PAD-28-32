/**
 * API Wrapper & Validadores Dominicanos PWA PAD-28/32
 * Conforme a NOFTRAB Standard y Normas PLAD
 */

const API = {
  // Determina dinámicamente la URL base de los endpoints del backend
  getBaseUrl() {
    const origin = window.location.origin;
    const pathname = window.location.pathname;
    
    // Si estamos dentro de /frontend/pwa/
    const idx = pathname.indexOf('/frontend/pwa');
    if (idx !== -1) {
      return origin + pathname.substring(0, idx) + '/backend/api';
    }
    
    // Fallback relativo seguro
    return '../../backend/api';
  },

  // Determina la URL pública absoluta para enlaces de captación y QR
  getPublicUrl(path = '') {
    const origin = window.location.origin;
    const pathname = window.location.pathname;
    const idx = pathname.indexOf('/frontend/pwa');
    const basePath = idx !== -1 ? pathname.substring(0, idx) : '';
    const cleanPath = String(path).replace(/^\//, '');
    return `${origin}${basePath}/${cleanPath}`;
  },

  // Validador de Cédula Dominicana (11 dígitos, Luhn Mod 10)
  validarCedula(cedula) {
    if (!cedula) return false;
    const clean = String(cedula).replace(/\D/g, '');
    if (clean.length !== 11) return false;

    const pesos = [1, 2, 1, 2, 1, 2, 1, 2, 1, 2];
    let suma = 0;

    for (let i = 0; i < 10; i++) {
      let mult = parseInt(clean.charAt(i), 10) * pesos[i];
      if (mult >= 10) {
        suma += Math.floor(mult / 10) + (mult % 10);
      } else {
        suma += mult;
      }
    }

    const digitoCalculado = (10 - (suma % 10)) % 10;
    const digitoReal = parseInt(clean.charAt(10), 10);
    return digitoCalculado === digitoReal;
  },

  // Validador de Teléfono Dominicano (10 dígitos, prefijos 809, 829, 849)
  validarTelefono(telefono) {
    if (!telefono) return false;
    const clean = String(telefono).replace(/\D/g, '');
    if (clean.length !== 10) return false;
    const prefijo = clean.substring(0, 3);
    return ['809', '829', '849'].includes(prefijo);
  },

  // Formateador visual de cédula 000-0000000-0
  formatearCedula(cedula) {
    const clean = String(cedula || '').replace(/\D/g, '').substring(0, 11);
    if (clean.length <= 3) return clean;
    if (clean.length <= 10) return `${clean.substring(0, 3)}-${clean.substring(3)}`;
    return `${clean.substring(0, 3)}-${clean.substring(3, 10)}-${clean.substring(10, 11)}`;
  },

  // Formateador de teléfono (000) 000-0000
  formatearTelefono(tel) {
    const clean = String(tel || '').replace(/\D/g, '').substring(0, 10);
    if (clean.length <= 3) return clean;
    if (clean.length <= 6) return `(${clean.substring(0, 3)}) ${clean.substring(3)}`;
    return `(${clean.substring(0, 3)}) ${clean.substring(3, 6)}-${clean.substring(6, 10)}`;
  },

  // Cliente HTTP Fetch Centralizado con Resiliencia
  async request(endpoint, options = {}) {
    const baseUrl = this.getBaseUrl();
    const url = `${baseUrl}/${endpoint}`;

    const headers = {
      'Accept': 'application/json',
      ...(options.headers || {})
    };

    if (options.body && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }

    // Inyección de credenciales para sesión PHP
    options.credentials = 'same-origin';
    options.headers = headers;

    try {
      const response = await fetch(url, options);
      const data = await response.json().catch(() => null);

      if (!response.ok) {
        // Manejo específico del error HTTP 409 (Restricción UNIQUE Cédula Duplicada)
        if (response.status === 409) {
          const errMsg = data?.mensaje || 'La cédula ya está registrada en la plataforma.';
          const error = new Error(errMsg);
          error.status = 409;
          error.data = data;
          throw error;
        }

        // Manejo de 401 (No autorizado / sesión expirada)
        if (response.status === 401) {
          const error = new Error(data?.mensaje || 'Sesión expirada.');
          error.status = 401;
          throw error;
        }

        const error = new Error(data?.mensaje || `Error del servidor (${response.status})`);
        error.status = response.status;
        error.data = data;
        throw error;
      }

      return data;
    } catch (err) {
      if (!window.navigator.onLine) {
        throw new Error('Sin conexión a internet. Verifique su red móvil.');
      }
      throw err;
    }
  },

  // Consultar Cédula en Padrón Maestro (JCE / PRM)
  async lookupCedula(cedula) {
    const clean = String(cedula).replace(/\D/g, '');
    return this.request(`padron_lookup.php?cedula=${clean}`);
  },

  // Registrar Nuevo Elector (Inscripción Móvil)
  async registrarVotante(voterData) {
    return this.request('voters.php?action=register', {
      method: 'POST',
      body: voterData
    });
  },

  // Listar Votantes con Filtros
  async getVotantes(params = {}) {
    const query = new URLSearchParams(params).toString();
    return this.request(`voters.php?action=list&${query}`);
  },

  // Consulta Dual de Estatus (4 Estados Electorales)
  async queryDualEstatus(cedula) {
    const clean = String(cedula).replace(/\D/g, '');
    return this.request(`voters.php?action=query_2024&search=${clean}&cedula=${clean}`);
  },

  // Subir Foto para OCR (Google Vision API)
  async procesarOCR(file) {
    const formData = new FormData();
    formData.append('imagen', file);
    return this.request('ocr.php', {
      method: 'POST',
      body: formData
    });
  },

  // Estadísticas para Coordinadores
  async getCoordinadoresStats() {
    return this.request('voters.php?action=coordinators_stats');
  }
};

window.API = API;
