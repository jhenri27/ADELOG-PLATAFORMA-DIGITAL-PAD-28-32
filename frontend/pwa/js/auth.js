/**
 * Módulo de Autenticación & Control de Roles PWA PAD-28/32
 * Soporta acceso unificado por Usuario, Cédula o Código ML
 */

const Auth = {
  userKey: 'pad_pwa_user',

  getUser() {
    try {
      const data = localStorage.getItem(this.userKey);
      return data ? JSON.parse(data) : null;
    } catch (e) {
      return null;
    }
  },

  setUser(user) {
    if (user) {
      localStorage.setItem(this.userKey, JSON.stringify(user));
    } else {
      localStorage.removeItem(this.userKey);
    }
  },

  isLoggedIn() {
    return this.getUser() !== null;
  },

  isDigitador() {
    const user = this.getUser();
    if (!user) return false;
    return user.role === 'Digitador' || user.perfil_id === 6;
  },

  isAdmin() {
    const user = this.getUser();
    if (!user) return false;
    return user.perfil_id === 1 || user.role === 'Administrador';
  },

  isCoordinator() {
    const user = this.getUser();
    if (!user) return false;
    if (this.isAdmin() || this.isDigitador()) return false;
    return (
      (user.role && (user.role.toLowerCase().includes('coordinador') || user.role.toLowerCase().includes('jefe'))) ||
      [2, 3, 4].includes(user.perfil_id)
    );
  },

  isML() {
    const user = this.getUser();
    if (!user) return false;
    if (this.isAdmin() || this.isCoordinator() || this.isDigitador()) return false;
    return (
      user.perfil_id === 5 ||
      (user.role && user.role.toLowerCase().includes('militante')) ||
      (user.codigo_ml && user.codigo_ml.startsWith('ML-'))
    );
  },

  async login(identifier, password) {
    const payload = {
      username: identifier.trim(),
      password: password.trim()
    };

    const res = await API.request('auth.php?action=login', {
      method: 'POST',
      body: payload
    });

    if (res && res.exito && res.usuario) {
      this.setUser(res.usuario);
      return res.usuario;
    }

    throw new Error(res?.mensaje || 'Error al iniciar sesión.');
  },

  async checkServerSession() {
    try {
      const res = await API.request('auth.php?action=check');
      if (res && res.autenticado && res.usuario) {
        this.setUser(res.usuario);
        return res.usuario;
      } else {
        this.setUser(null);
        return null;
      }
    } catch (e) {
      // Si está offline, mantenemos la sesión cacheada
      return this.getUser();
    }
  },

  async logout() {
    try {
      await API.request('auth.php?action=logout');
    } catch (e) {
      // Ignorar error al cerrar sesión
    }
    this.setUser(null);
    window.location.hash = '';
    window.location.reload();
  }
};

window.Auth = Auth;
