/**
 * Motor de Gestión de Perfiles y Accesos Granulares (RBAC)
 * PAD/28-32 - Plataforma Electoral
 */

(function () {
    let perfilesLista = [];
    let currentPerfilId = 1;
    let currentMallaData = [];

    document.addEventListener('DOMContentLoaded', () => {
        setupRbacSubtabs();
        setupRbacEventListeners();
        cargarListaPerfiles();
    });

    // ─── NAvegación de subPESTAÑAS ───────────────────────────────────────────
    function setupRbacSubtabs() {
        const btnMalla = document.getElementById('subtab-btn-malla');
        const btnMantenimiento = document.getElementById('subtab-btn-mantenimiento');
        const viewMalla = document.getElementById('rbac-view-malla');
        const viewMantenimiento = document.getElementById('rbac-view-mantenimiento');

        if (btnMalla && btnMantenimiento) {
            btnMalla.addEventListener('click', (e) => {
                e.preventDefault();
                btnMalla.classList.add('active');
                btnMantenimiento.classList.remove('active');
                viewMalla.style.display = 'block';
                viewMantenimiento.style.display = 'none';
            });

            btnMantenimiento.addEventListener('click', (e) => {
                e.preventDefault();
                btnMantenimiento.classList.add('active');
                btnMalla.classList.remove('active');
                viewMantenimiento.style.display = 'block';
                viewMalla.style.display = 'none';
            });
        }
    }

    function setupRbacEventListeners() {
        // Selector de Perfil en la Malla
        const selectMalla = document.getElementById('select-perfil-malla');
        if (selectMalla) {
            selectMalla.addEventListener('change', (e) => {
                currentPerfilId = parseInt(e.target.value, 10);
                cargarMallaPerfil(currentPerfilId);
            });
        }

        // Botón Guardar Malla
        const btnSaveMalla = document.getElementById('btn-save-malla');
        if (btnSaveMalla) {
            btnSaveMalla.addEventListener('click', () => {
                guardarMallaPerfil();
            });
        }

        // Buscador de Perfiles en Mantenimiento
        const searchInput = document.getElementById('search-perfil');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                filtrarYRenderizarPerfiles();
            });
        }

        const filterEstado = document.getElementById('filter-estado-perfil');
        if (filterEstado) {
            filterEstado.addEventListener('change', () => {
                filtrarYRenderizarPerfiles();
            });
        }

        // Botón Crear Perfil
        const btnOpenCreate = document.getElementById('btn-open-create-perfil');
        if (btnOpenCreate) {
            btnOpenCreate.addEventListener('click', () => {
                abrirModalPerfil();
            });
        }

        // Formulario Guardar Perfil
        const formPerfil = document.getElementById('form-perfil-modal');
        if (formPerfil) {
            formPerfil.addEventListener('submit', (e) => {
                e.preventDefault();
                guardarPerfilModal();
            });
        }

        // Formulario Copiar Permisos
        const formCopy = document.getElementById('form-copiar-permisos');
        if (formCopy) {
            formCopy.addEventListener('submit', (e) => {
                e.preventDefault();
                ejecutarCopiarPermisos();
            });
        }

        // Delegación de Toggles Masivos por Columna
        const toggleBar = document.getElementById('malla-actions-bar');
        if (toggleBar) {
            toggleBar.addEventListener('click', (e) => {
                const btn = e.target.closest('.btn-malla-toggle');
                if (!btn) return;
                const col = btn.getAttribute('data-col');
                const action = btn.getAttribute('data-action');
                if (col) {
                    toggleColumnaMalla(col, action === 'check');
                }
            });
        }
    }

    // ─── CARGAR LISTA DE PERFILES DE LA API ─────────────────────────────────
    function cargarListaPerfiles() {
        fetch('../backend/api/perfiles.php?action=listar')
            .then(res => res.json())
            .then(data => {
                if (data.exito && Array.isArray(data.perfiles)) {
                    perfilesLista = data.perfiles;
                    poblarSelectsPerfiles();
                    filtrarYRenderizarPerfiles();
                    
                    if (perfilesLista.length > 0) {
                        const selectMalla = document.getElementById('select-perfil-malla');
                        if (selectMalla && (!selectMalla.value || selectMalla.value === "")) {
                            selectMalla.value = perfilesLista[0].id;
                            currentPerfilId = perfilesLista[0].id;
                        }
                        cargarMallaPerfil(currentPerfilId);
                    }
                }
            })
            .catch(err => console.error("Error al cargar lista de perfiles:", err));
    }

    function poblarSelectsPerfiles() {
        const selectMalla = document.getElementById('select-perfil-malla');
        const selectOrigenCopy = document.getElementById('copy-perfil-origen');
        const selectOrigenModal = document.getElementById('modal-perfil-origen');

        let optionsHtml = '';
        perfilesLista.forEach(p => {
            optionsHtml += `<option value="${p.id}">${p.nombre} (Nivel ${p.nivel_jerarquico})</option>`;
        });

        if (selectMalla) selectMalla.innerHTML = optionsHtml;
        if (selectOrigenCopy) selectOrigenCopy.innerHTML = '<option value="">-- Seleccionar Perfil Origen --</option>' + optionsHtml;
        if (selectOrigenModal) selectOrigenModal.innerHTML = '<option value="0">-- Matriz Vacía por Defecto --</option>' + optionsHtml;
    }

    // ─── CARGAR Y RENDERIZAR MALLA DE PERMISOS ──────────────────────────────
    function cargarMallaPerfil(perfilId) {
        fetch(`../backend/api/perfiles.php?action=obtener&id=${perfilId}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito && data.perfil) {
                    renderizarHeroPerfil(data.perfil);
                    currentMallaData = data.perfil.malla_permisos || [];
                    renderizarTablaMalla(currentMallaData);
                }
            })
            .catch(err => console.error("Error al cargar malla de perfil:", err));
    }

    function renderizarHeroPerfil(perfil) {
        const heroName = document.getElementById('hero-profile-name');
        const heroLevel = document.getElementById('hero-profile-level');
        const heroStatus = document.getElementById('hero-profile-status');
        const heroAvatar = document.getElementById('hero-profile-avatar');

        if (heroName) heroName.textContent = perfil.nombre;
        if (heroLevel) heroLevel.textContent = `Nivel Jerárquico: ${perfil.nivel_jerarquico}`;
        if (heroAvatar) heroAvatar.textContent = perfil.nombre.charAt(0).toUpperCase();

        if (heroStatus) {
            heroStatus.className = perfil.estado === 1 ? 'badge badge-success' : 'badge badge-danger';
            heroStatus.textContent = perfil.estado === 1 ? 'ACTIVO' : 'INACTIVO';
        }
    }

    function renderizarTablaMalla(malla) {
        const tbody = document.getElementById('malla-tbody');
        if (!tbody) return;

        let html = '';
        malla.forEach(m => {
            html += `
                <tr data-modulo-id="${m.modulo_id}">
                    <td style="font-weight: 600; color: var(--text-white);">
                        <i class="fa ${m.icono}" style="color: var(--secondary); margin-right: 8px; width: 16px;"></i>
                        ${m.nombre_modulo} <span style="font-size: 11px; color: var(--text-gray); font-family: monospace;">[${m.codigo_modulo}]</span>
                    </td>
                    <td><input type="checkbox" class="switch-checkbox col-ejecutar" ${m.ejecutar ? 'checked' : ''}></td>
                    <td><input type="checkbox" class="switch-checkbox col-ver_datos" ${m.ver_datos ? 'checked' : ''}></td>
                    <td><input type="checkbox" class="switch-checkbox col-crear" ${m.crear ? 'checked' : ''}></td>
                    <td><input type="checkbox" class="switch-checkbox col-editar" ${m.editar ? 'checked' : ''}></td>
                    <td><input type="checkbox" class="switch-checkbox col-eliminar" ${m.eliminar ? 'checked' : ''}></td>
                    <td><input type="checkbox" class="switch-checkbox col-reportes" ${m.reportes ? 'checked' : ''}></td>
                    <td><input type="checkbox" class="switch-checkbox col-exportar" ${m.exportar ? 'checked' : ''}></td>
                    <td><input type="checkbox" class="switch-checkbox col-importar" ${m.importar ? 'checked' : ''}></td>
                    <td><input type="checkbox" class="switch-checkbox col-imprimir" ${m.imprimir ? 'checked' : ''}></td>
                    <td><input type="checkbox" class="switch-checkbox col-solo_propios" ${m.solo_propios ? 'checked' : ''}></td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    function toggleColumnaMalla(colName, checkState) {
        const checkboxes = document.querySelectorAll(`#malla-tbody .col-${colName}`);
        checkboxes.forEach(cb => cb.checked = checkState);
    }

    function guardarMallaPerfil() {
        const rows = document.querySelectorAll('#malla-tbody tr');
        const permisos = [];

        rows.forEach(r => {
            const moduloId = parseInt(r.getAttribute('data-modulo-id'), 10);
            permisos.push({
                modulo_id: moduloId,
                ejecutar: r.querySelector('.col-ejecutar').checked ? 1 : 0,
                ver_datos: r.querySelector('.col-ver_datos').checked ? 1 : 0,
                crear: r.querySelector('.col-crear').checked ? 1 : 0,
                editar: r.querySelector('.col-editar').checked ? 1 : 0,
                eliminar: r.querySelector('.col-eliminar').checked ? 1 : 0,
                reportes: r.querySelector('.col-reportes').checked ? 1 : 0,
                exportar: r.querySelector('.col-exportar').checked ? 1 : 0,
                importar: r.querySelector('.col-importar').checked ? 1 : 0,
                imprimir: r.querySelector('.col-imprimir').checked ? 1 : 0,
                solo_propios: r.querySelector('.col-solo_propios').checked ? 1 : 0
            });
        });

        const btnSave = document.getElementById('btn-save-malla');
        if (btnSave) btnSave.disabled = true;

        fetch(`../backend/api/perfiles.php?action=asignar_permisos&id=${currentPerfilId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ permisos: permisos })
        })
        .then(res => res.json())
        .then(data => {
            if (btnSave) btnSave.disabled = false;
            if (data.exito) {
                alert("✓ Malla de accesos y permisos guardada exitosamente en la base de datos.", "Éxito");
            } else {
                alert("Error: " + data.mensaje, "Atención");
            }
        })
        .catch(err => {
            if (btnSave) btnSave.disabled = false;
            console.error("Error al guardar permisos:", err);
        });
    }

    // ─── MANTENIMIENTO DE PERFILES (SUBTAB 2) ────────────────────────────────
    function filtrarYRenderizarPerfiles() {
        const tbody = document.getElementById('perfiles-table-tbody');
        if (!tbody) return;

        const search = (document.getElementById('search-perfil').value || '').toLowerCase();
        const estadoFilter = document.getElementById('filter-estado-perfil').value;

        const filtrados = perfilesLista.filter(p => {
            const matchName = p.nombre.toLowerCase().includes(search) || (p.descripcion && p.descripcion.toLowerCase().includes(search));
            const matchState = estadoFilter === 'all' || p.estado.toString() === estadoFilter;
            return matchName && matchState;
        });

        let html = '';
        if (filtrados.length === 0) {
            html = `<tr><td colspan="6" style="text-align: center; color: var(--text-gray); padding: 20px;">No se encontraron perfiles coincidentes.</td></tr>`;
        } else {
            filtrados.forEach(p => {
                const badgeState = p.estado === 1 
                    ? '<span class="badge badge-success">Activo</span>' 
                    : '<span class="badge badge-danger">Inactivo</span>';

                html += `
                    <tr>
                        <td style="font-weight: 700; color: var(--secondary);">#${p.id}</td>
                        <td style="font-weight: 600; color: var(--text-white);">${p.nombre}</td>
                        <td style="font-size: 12px; color: var(--text-gray); max-width: 250px;">${p.descripcion || 'Sin descripción'}</td>
                        <td><span class="badge badge-info">Nivel ${p.nivel_jerarquico}</span></td>
                        <td>${badgeState}</td>
                        <td>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <button class="btn-action-sm btn-edit-perf" onclick="window.editarPerfilModal(${p.id})"><i class="fa fa-edit"></i> Editar</button>
                                <button class="btn-action-sm btn-copy-perf" onclick="window.copiarPermisosModal(${p.id})"><i class="fa fa-copy"></i> Copiar</button>
                                <button class="btn-action-sm btn-toggle-perf" onclick="window.togglePerfilEstado(${p.id}, ${p.estado})"><i class="fa fa-power-off"></i> ${p.estado === 1 ? 'Desactivar' : 'Activar'}</button>
                            </div>
                        </td>
                    </tr>
                `;
            });
        }

        tbody.innerHTML = html;
    }

    function safeOpenModal(id) {
        if (typeof window.openModal === 'function') {
            window.openModal(id);
        } else {
            const modal = document.getElementById(id);
            if (modal) modal.style.display = 'flex';
        }
    }

    function safeCloseModal(id) {
        if (typeof window.closeModal === 'function') {
            window.closeModal(id);
        } else {
            const modal = document.getElementById(id);
            if (modal) modal.style.display = 'none';
        }
    }

    // ─── MODALES DE MANTENIMIENTO ────────────────────────────────────────────
    window.abrirModalPerfil = function(id = 0) {
        document.getElementById('form-perfil-modal').reset();
        document.getElementById('modal-perfil-id').value = id;

        if (id > 0) {
            document.getElementById('modal-perfil-title').textContent = "Editar Perfil de Usuario";
            const perf = perfilesLista.find(p => p.id === id);
            if (perf) {
                document.getElementById('modal-perfil-nombre').value = perf.nombre;
                document.getElementById('modal-perfil-desc').value = perf.descripcion || '';
                document.getElementById('modal-perfil-nivel').value = perf.nivel_jerarquico;
                document.getElementById('group-perfil-origen').style.display = 'none';
            }
        } else {
            document.getElementById('modal-perfil-title').textContent = "Crear Nuevo Perfil de Usuario";
            document.getElementById('group-perfil-origen').style.display = 'block';
        }

        safeOpenModal('modal-perfil-crud');
    };

    window.editarPerfilModal = function(id) {
        window.abrirModalPerfil(id);
    };

    function guardarPerfilModal() {
        const id = parseInt(document.getElementById('modal-perfil-id').value, 10);
        const payload = {
            nombre: document.getElementById('modal-perfil-nombre').value,
            descripcion: document.getElementById('modal-perfil-desc').value,
            nivel_jerarquico: parseInt(document.getElementById('modal-perfil-nivel').value, 10),
            perfil_origen: parseInt(document.getElementById('modal-perfil-origen').value || 0, 10)
        };

        const action = id > 0 ? `editar&id=${id}` : 'crear';

        fetch(`../backend/api/perfiles.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                safeCloseModal('modal-perfil-crud');
                alert(data.mensaje, "Éxito");
                cargarListaPerfiles();
            } else {
                alert("Error: " + data.mensaje, "Atención");
            }
        })
        .catch(err => console.error("Error al guardar perfil:", err));
    }

    window.copiarPermisosModal = function(destinoId) {
        document.getElementById('form-copiar-permisos').reset();
        document.getElementById('copy-perfil-destino').value = destinoId;
        const perfDest = perfilesLista.find(p => p.id === destinoId);
        document.getElementById('copy-target-name').textContent = perfDest ? perfDest.nombre : `#${destinoId}`;

        safeOpenModal('modal-copiar-permisos-dialog');
    };

    function ejecutarCopiarPermisos() {
        const origen = parseInt(document.getElementById('copy-perfil-origen').value, 10);
        const destino = parseInt(document.getElementById('copy-perfil-destino').value, 10);

        if (!origen || !destino) {
            alert("Por favor seleccione un perfil de origen válido.", "Atención");
            return;
        }

        fetch('../backend/api/perfiles.php?action=copiar_permisos', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ perfil_origen: origen, perfil_destino: destino })
        })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                safeCloseModal('modal-copiar-permisos-dialog');
                alert("✓ Permisos y malla de accesos clonada exitosamente.", "Éxito");
                cargarMallaPerfil(currentPerfilId);
            } else {
                alert("Error: " + data.mensaje, "Atención");
            }
        })
        .catch(err => console.error("Error al copiar permisos:", err));
    }

    window.togglePerfilEstado = function(id, estadoActual) {
        const nuevoEstado = estadoActual === 1 ? 0 : 1;
        const msg = estadoActual === 1 ? "¿Desea desactivar este perfil?" : "¿Desea activar este perfil?";

        const confirmPromise = (typeof window.showCustomConfirm === 'function') 
            ? window.showCustomConfirm(msg, "Confirmación de Perfil")
            : Promise.resolve(confirm(msg));

        confirmPromise.then(aceptado => {
            if (aceptado) {
                fetch(`../backend/api/perfiles.php?action=editar&id=${id}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ estado: nuevoEstado })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.exito) {
                        alert(data.mensaje, "Éxito");
                        cargarListaPerfiles();
                    } else {
                        alert("Error: " + data.mensaje, "Atención");
                    }
                })
                .catch(err => console.error("Error al cambiar estado:", err));
            }
        });
    };
})();
