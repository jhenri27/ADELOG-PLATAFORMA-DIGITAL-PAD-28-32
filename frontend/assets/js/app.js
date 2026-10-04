/**
 * SPA Controller: Lógica de la Plataforma Electoral PAD/28-32
 */

// Variable global para referencia de stream de cámara web (Anti-TDZ Normas PLAD)
var streamRef = null;
window.streamRef = null;

document.addEventListener('DOMContentLoaded', () => {
    // --- DIÁLOGOS Y ALERTAS ESTILIZADOS PERSONALIZADOS ---
    const originalAlert = window.alert;
    
    window.alert = function(message, title = "Notificación") {
        const modal = document.getElementById('custom-alert-modal');
        if (!modal) {
            originalAlert(message);
            return;
        }
        document.getElementById('custom-alert-title').innerText = title;
        document.getElementById('custom-alert-message').innerText = message;
        
        const btn = document.getElementById('btn-custom-alert-accept');
        btn.onclick = function() {
            closeModal('custom-alert-modal');
        };
        
        openModal('custom-alert-modal');
    };

    function showCustomConfirm(message, title = "Confirmación") {
        return new Promise((resolve) => {
            const modal = document.getElementById('custom-confirm-modal');
            if (!modal) {
                resolve(confirm(message));
                return;
            }
            document.getElementById('custom-confirm-title').innerText = title;
            document.getElementById('custom-confirm-message').innerText = message;
            
            const btnAccept = document.getElementById('btn-custom-confirm-accept');
            const btnCancel = document.getElementById('btn-custom-confirm-cancel');
            
            btnAccept.onclick = function() {
                closeModal('custom-confirm-modal');
                resolve(true);
            };
            
            btnCancel.onclick = function() {
                closeModal('custom-confirm-modal');
                resolve(false);
            };
            
            openModal('custom-confirm-modal');
        });
    }
    window.showCustomConfirm = showCustomConfirm;

    // Inicializar estado global
    const State = {
        user: null,
        perms: null,
        activeTab: 'dashboard',
        activeChatUser: null,
        chatsInterval: null,
        dashboardInterval: null,
        campanaCodigo: null // Para trackeo de campañas masivas
    };

    // --- OCR Y WEBCAM ESTADO GLOBAL ---
    window.streamRef = null;
    streamRef = null;
    const ocrState = {
        admin: { front: false, dorsal: false },
        public: { front: false, dorsal: false }
    };

    // --- GESTIÓN DE TEMA CLARO/OSCURO ---
    const savedTheme = localStorage.getItem('theme') || 'dark';
    if (savedTheme === 'light') {
        document.body.classList.add('light-theme');
    }

    const themeToggleBtn = document.getElementById('theme-toggle');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const isLight = document.body.classList.toggle('light-theme');
            localStorage.setItem('theme', isLight ? 'light' : 'dark');
            if (isDashboardPage && typeof loadDashboardData === 'function') {
                loadDashboardData();
            }
        });
    }

    // Detectar si venimos de un código de campaña QR en la URL
    const urlParams = new URLSearchParams(window.location.search);
    const campaignCode = urlParams.get('c');
    if (campaignCode) {
        State.campanaCodigo = campaignCode;
        // Registrar clic en la campaña
        trackCampaignClick(campaignCode);
    }

    // Inicializar vistas según la página abierta
    const isDashboardPage = document.getElementById('dashboard-view') !== null;
    const isLandingPage = document.getElementById('public-landing') !== null;

    if (isDashboardPage) {
        checkAuth();
        setupDashboardEventListeners();
    } else if (isLandingPage) {
        setupPublicEventListeners();
    }

    // ─── LÓGICA DE AUTENTICACIÓN ────────────────────────────────────────────
    function checkAuth() {
        fetch('../backend/api/auth.php?action=check')
            .then(res => res.json())
            .then(data => {
                if (data.autenticado) {
                    State.user = data.usuario;
                    State.perms = data.permisos;
                    document.getElementById('nav-user-name').textContent = data.usuario.nombre + " (" + data.usuario.role + ")";
                    
                    const progressBadge = document.getElementById('nav-user-progress');
                    if (progressBadge) {
                        if (typeof data.usuario.total_inscritos !== 'undefined') {
                            progressBadge.textContent = "Mi Progreso: " + data.usuario.total_inscritos + " inscritos";
                            progressBadge.style.display = 'inline-block';
                        } else {
                            progressBadge.style.display = 'none';
                        }
                    }
                    
                    applyUserPermissions();
                    loadDashboardData();
                    poblarRolesSelects();
                    startDashboardPolling();
                } else {
                    window.location.href = 'login.html';
                }
            })
            .catch(() => {
                window.location.href = 'login.html';
            });
    }

    function applyUserPermissions() {
        if (!State.perms) return;
        
        // Mostrar/Ocultar pestañas según roles/permisos
        const isAdmin = State.user.role === 'Administrador';
        const isGerente = State.user.role === 'Gerente';
        const isJefe = State.user.role === 'Jefe Electoral';
        const isDigitadorOrAdmin = State.user.role === 'Administrador' || State.user.role === 'Digitador';
        const isDigitadorOrAdminOrGerente = State.user.role === 'Administrador' || State.user.role === 'Digitador' || State.user.role === 'Gerente';
        
        const tabPermissions = document.getElementById('tab-permissions');
        const tabHelpdesk = document.getElementById('tab-helpdesk');
        const tabChat = document.getElementById('tab-chat');
        const tabConfig = document.getElementById('tab-config');
        
        // Solo administradores o digitadores o gerentes pueden acceder a la pestaña de ajustes (para ver la lista de usuarios y crear enlaces QR)
        if (tabPermissions) {
            tabPermissions.style.display = isDigitadorOrAdminOrGerente ? 'block' : 'none';
        }
        
        // Solo Administradores pueden entrar al panel de Configuración completa
        if (tabConfig) {
            tabConfig.style.display = isAdmin ? 'block' : 'none';
        }
        
        // Ocultar formulario de permisos (actualizar) en la pestaña ajustes si no es administrador
        const userForm = document.getElementById('user-form');
        if (userForm) {
            userForm.style.display = isAdmin ? 'block' : 'none';
        }
        
        // Ocultar/mostrar el botón de "Registrar Nuevo Colaborador"
        const btnColab = document.getElementById('btn-dashboard-register-collaborator');
        if (btnColab) {
            btnColab.style.display = isDigitadorOrAdminOrGerente ? 'inline-block' : 'none';
        }
        
        // Ocultar/mostrar selector de período según permisos de padrón histórico
        const canViewHist = State.perms.can_view_historical == 1 || isAdmin || isGerente;
        const filterPeriodo = document.getElementById('filter-periodo');
        if (filterPeriodo) {
            filterPeriodo.style.display = canViewHist ? 'inline-block' : 'none';
        }
        
        // Mostrar/ocultar el buscador del padrón histórico 2024 en el Dashboard
        const lookupCard = document.getElementById('historical-lookup-card');
        if (lookupCard) {
            lookupCard.style.display = canViewHist ? 'block' : 'none';
        }
        
        // Jefes electorales y coordinadores no crean registros directos en la base
        const btnOpenCreate = document.getElementById('btn-open-create');
        if (btnOpenCreate) {
            btnOpenCreate.style.display = (State.perms.can_create == 1 || isGerente || isAdmin) ? 'inline-flex' : 'none';
        }
    }

    // Autocompletado y Validación en Padrón Circunscripción 3 (Compartido Admin y Público)
    function setupCedulaPadronListener(inputId, targetPrefix) {
        const inputEl = document.getElementById(inputId);
        if (!inputEl) return;

        inputEl.addEventListener('input', (e) => {
            const val = e.target.value.replace(/\D/g, '');
            if (val.length === 11) {
                buscarEnPadronCirc3(e.target.value, targetPrefix);
            } else if (val.length > 0) {
                let feedbackEl = document.getElementById(targetPrefix + 'cedula-feedback') || document.getElementById(targetPrefix + 'cedula-validation');
                if (feedbackEl) {
                    feedbackEl.style.display = 'block';
                    feedbackEl.innerHTML = '<span style="color:#f59e0b; font-size:12px;"><i class="fa fa-info-circle"></i> Ingrese los 11 dígitos de su cédula</span>';
                }
            } else {
                let feedbackEl = document.getElementById(targetPrefix + 'cedula-feedback') || document.getElementById(targetPrefix + 'cedula-validation');
                if (feedbackEl) {
                    feedbackEl.style.display = 'none';
                    feedbackEl.innerHTML = '';
                }
            }
        });

        inputEl.addEventListener('blur', (e) => {
            const val = e.target.value.replace(/\D/g, '');
            if (val.length === 11) {
                buscarEnPadronCirc3(e.target.value, targetPrefix);
            }
        });
    }

    // ─── EVENT LISTENERS DEL DASHBOARD ──────────────────────────────────────
    function setupDashboardEventListeners() {
        // Tab switching
        const tabs = document.querySelectorAll('.tab-link');
        tabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                e.preventDefault();
                const targetTab = tab.getAttribute('data-tab');
                switchTab(targetTab);
            });
        });

        // Logout button
        const btnLogout = document.getElementById('btn-logout');
        if (btnLogout) {
            btnLogout.addEventListener('click', (e) => {
                e.preventDefault();
                fetch('../backend/api/auth.php?action=logout')
                    .then(() => {
                        window.location.href = 'login.html';
                    });
            });
        }

        // Abrir formulario de inscripción vacío
        const btnOpenCreate = document.getElementById('btn-open-create');
        if (btnOpenCreate) {
            btnOpenCreate.addEventListener('click', () => {
                document.getElementById('voter-form-title').textContent = "Nueva Inscripción de Votante";
                document.getElementById('voter-id').value = "";
                document.getElementById('voter-form').reset();
                resetOcrButtonState(false);
                openModal('voter-modal');
            });
        }

        // OCR Upload File selector
        const fileOcr = document.getElementById('ocr-file');
        if (fileOcr) {
            fileOcr.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    processOcrImage(file);
                }
                e.target.value = '';
            });
        }

        // Inicializar Cámara web (Dashboard)
        const btnCamera = document.getElementById('btn-camera-ocr');
        if (btnCamera) {
            btnCamera.addEventListener('click', () => {
                initWebcam(false);
            });
        }

        const btnCapture = document.getElementById('btn-capture-photo');
        if (btnCapture) {
            btnCapture.addEventListener('click', () => {
                capturePhoto(false);
            });
        }

        const btnCloseCam = document.getElementById('btn-close-camera');
        if (btnCloseCam) {
            btnCloseCam.addEventListener('click', () => {
                stopWebcam(false);
            });
        }

        // Autocompletado y Validación en Padrón Circunscripción 3 (Admin)
        setupCedulaPadronListener('cedula', '');

        // Formulario Guardar Votante
        const voterForm = document.getElementById('voter-form');
        if (voterForm) {
            voterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                saveVoter();
            });
        }

        // Respaldos y Push Update
        const btnActionBackup = document.getElementById('btn-action-backup');
        if (btnActionBackup) {
            btnActionBackup.addEventListener('click', () => {
                const consoleEl = document.getElementById('backup-console');
                consoleEl.textContent = ">>> Iniciando volcado de base de datos y preparando commit Git...\n";
                btnActionBackup.disabled = true;
                
                fetch('../backend/api/settings.php?action=run_backup', { method: 'POST' })
                    .then(res => res.json())
                    .then(data => {
                        btnActionBackup.disabled = false;
                        if (data.exito) {
                            consoleEl.textContent = data.console;
                            showNotification("✓ Copia de seguridad ejecutada con éxito.", "success");
                        } else {
                            consoleEl.textContent = ">>> ERROR:\n" + data.console;
                            alert(data.mensaje);
                        }
                    })
                    .catch(err => {
                        btnActionBackup.disabled = false;
                        consoleEl.textContent += ">>> ERROR de red al ejecutar copia de seguridad.\n";
                        console.error(err);
                    });
            });
        }
        
        const btnActionPush = document.getElementById('btn-action-push');
        if (btnActionPush) {
            btnActionPush.addEventListener('click', () => {
                const consoleEl = document.getElementById('backup-console');
                consoleEl.textContent = ">>> Conectando con GitHub y ejecutando git push origin main...\n";
                btnActionPush.disabled = true;
                
                fetch('../backend/api/settings.php?action=push_update', { method: 'POST' })
                    .then(res => res.json())
                    .then(data => {
                        btnActionPush.disabled = false;
                        consoleEl.textContent = data.console;
                        if (data.exito) {
                            showNotification("✓ Push Update completado.", "success");
                        } else {
                            alert(data.mensaje);
                        }
                    })
                    .catch(err => {
                        btnActionPush.disabled = false;
                        consoleEl.textContent += ">>> ERROR de red al sincronizar con GitHub.\n";
                        console.error(err);
                    });
            });
        }
        
        const btnClearConsole = document.getElementById('btn-clear-console');
        if (btnClearConsole) {
            btnClearConsole.addEventListener('click', () => {
                const consoleEl = document.getElementById('backup-console');
                if (consoleEl) consoleEl.textContent = "Consola limpia. Esperando comando...";
            });
        }

        // Búsqueda en Padrón
        const searchInput = document.getElementById('padron-search');
        if (searchInput) {
            searchInput.addEventListener('input', debounce(() => {
                loadPadron();
            }, 300));
        }

        const filterRegion = document.getElementById('filter-region');
        if (filterRegion) {
            filterRegion.addEventListener('change', () => loadPadron());
        }

        // Chat send message
        const btnSendMsg = document.getElementById('btn-send-msg');
        const chatInput = document.getElementById('chat-input');
        if (btnSendMsg && chatInput) {
            btnSendMsg.addEventListener('click', () => sendChatMessage());
            chatInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') sendChatMessage();
            });
        }

        // Helpdesk crear ticket
        const btnOpenTicket = document.getElementById('btn-open-ticket-modal');
        if (btnOpenTicket) {
            btnOpenTicket.addEventListener('click', () => {
                openModal('ticket-modal');
            });
        }

        const ticketForm = document.getElementById('ticket-form');
        if (ticketForm) {
            ticketForm.addEventListener('submit', (e) => {
                e.preventDefault();
                createTicket();
            });
        }

        // Permisos: Crear usuario
        const userForm = document.getElementById('user-form');
        if (userForm) {
            userForm.addEventListener('submit', (e) => {
                e.preventDefault();
                createUser();
            });
        }

        // Permisos: Crear campaña QR
        const qrForm = document.getElementById('qr-form');
        if (qrForm) {
            qrForm.addEventListener('submit', (e) => {
                e.preventDefault();
                createCampaign();
            });
        }

        // Registrar Colaborador button (Dashboard quick action)
        const btnDashboardRegColab = document.getElementById('btn-dashboard-register-collaborator');
        if (btnDashboardRegColab) {
            btnDashboardRegColab.addEventListener('click', () => {
                document.getElementById('collaborator-form').reset();
                poblarRolesSelects();
                openModal('collaborator-modal');
            });
        }

        // Registrar Votante button (Dashboard quick action)
        const btnDashboardRegVoter = document.getElementById('btn-dashboard-register-voter');
        if (btnDashboardRegVoter) {
            btnDashboardRegVoter.addEventListener('click', () => {
                document.getElementById('voter-form-title').textContent = "Nueva Inscripción de Votante";
                document.getElementById('voter-id').value = "";
                document.getElementById('voter-form').reset();
                resetOcrButtonState(false);
                openModal('voter-modal');
            });
        }

        // Exportar Excel button
        const btnExportExcel = document.getElementById('btn-export-excel');
        if (btnExportExcel) {
            btnExportExcel.addEventListener('click', () => {
                const region = document.getElementById('filter-region').value;
                const periodo = document.getElementById('filter-periodo').value || '2028';
                window.open(`../backend/api/voters.php?action=export_excel&region=${encodeURIComponent(region)}&periodo=${encodeURIComponent(periodo)}`, '_blank');
            });
        }

        // filter-periodo dropdown change listener
        const filterPeriodo = document.getElementById('filter-periodo');
        if (filterPeriodo) {
            filterPeriodo.addEventListener('change', () => {
                loadPadron();
            });
        }

        // filter-padron-tipo dropdown change listener
        const filterPadronTipo = document.getElementById('filter-padron-tipo');
        if (filterPadronTipo) {
            filterPadronTipo.addEventListener('change', () => {
                loadPadron();
            });
        }

        // --- LISTENERS DE RED TERRITORIAL Y COORDINADORES ---
        const filterNetLevel = document.getElementById('filter-network-level');
        if (filterNetLevel) {
            filterNetLevel.addEventListener('change', () => {
                loadRedTerritorial(true);
            });
        }

        const filterNetCoord = document.getElementById('filter-network-coord');
        if (filterNetCoord) {
            filterNetCoord.addEventListener('change', () => {
                const selectedCoord = filterNetCoord.value;
                const level = filterNetLevel ? filterNetLevel.value : 'all';
                const search = document.getElementById('filter-network-search') ? document.getElementById('filter-network-search').value : '';
                loadNetworkVoters(selectedCoord, level, search);
            });
        }

        const filterNetSearch = document.getElementById('filter-network-search');
        if (filterNetSearch) {
            filterNetSearch.addEventListener('input', () => {
                const selectedCoord = filterNetCoord ? filterNetCoord.value : '';
                const level = filterNetLevel ? filterNetLevel.value : 'all';
                const search = filterNetSearch.value;
                loadNetworkVoters(selectedCoord, level, search);
            });
        }

        // Botones de impresión y exportación de Red Territorial
        const btnPrintNetPdf = document.getElementById('btn-print-network-pdf');
        if (btnPrintNetPdf) {
            btnPrintNetPdf.addEventListener('click', () => {
                printNetworkSeccionedPDF();
            });
        }

        const btnPrintGenPdf = document.getElementById('btn-print-general-pdf');
        if (btnPrintGenPdf) {
            btnPrintGenPdf.addEventListener('click', () => {
                printNetworkGeneralPDF();
            });
        }

        const btnExportNetExcel = document.getElementById('btn-export-network-excel');
        if (btnExportNetExcel) {
            btnExportNetExcel.addEventListener('click', () => {
                exportNetworkExcel();
            });
        }

        // Toggle ML Checkbox in voter registration modal
        const chkEsML = document.getElementById('voter-es-ml');
        if (chkEsML) {
            chkEsML.addEventListener('change', () => {
                const detailsBox = document.getElementById('voter-ml-details');
                if (detailsBox) {
                    detailsBox.style.display = chkEsML.checked ? 'block' : 'none';
                }
            });
        }

        // Imprimir Toda la Documentación button
        const btnPrintAllDocs = document.getElementById('btn-print-all-docs');
        if (btnPrintAllDocs) {
            btnPrintAllDocs.addEventListener('click', () => {
                printAllDocuments();
            });
        }

        // Iniciar conversación chat button (+ Iniciar in sidebar)
        const btnChatStart = document.getElementById('btn-chat-start-conversation');
        if (btnChatStart) {
            btnChatStart.addEventListener('click', () => {
                document.getElementById('start-chat-form').reset();
                fetchCollaboratorsForChat();
                openModal('start-chat-modal');
            });
        }

        // Consultar Padrón Histórico 2024
        const btnSubmitQuery2024 = document.getElementById('btn-submit-query-2024');
        if (btnSubmitQuery2024) {
            btnSubmitQuery2024.addEventListener('click', () => {
                consultarEstatus2024();
            });
        }
        const inputQuery2024 = document.getElementById('input-query-2024');
        if (inputQuery2024) {
            inputQuery2024.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    consultarEstatus2024();
                }
            });
        }

        // Exportar Padrón Histórico 2024 Completo (Excel)
        const btnExportExcel2024 = document.getElementById('btn-export-excel-2024');
        if (btnExportExcel2024) {
            btnExportExcel2024.addEventListener('click', () => {
                window.open(`../backend/api/voters.php?action=export_excel&periodo=2024`, '_blank');
            });
        }

        // Imprimir Padrón Histórico 2024 Completo (PDF)
        const btnExportPdf2024 = document.getElementById('btn-export-pdf-2024');
        if (btnExportPdf2024) {
            btnExportPdf2024.addEventListener('click', () => {
                window.open(`../backend/api/voters.php?action=export_pdf_2024`, '_blank');
            });
        }

        // Enviar WhatsApp Externo (Mensaje click-to-chat)
        const btnExtChatSend = document.getElementById('btn-ext-chat-send');
        if (btnExtChatSend) {
            btnExtChatSend.addEventListener('click', () => {
                sendExternalWhatsApp(true);
            });
        }

        // Abrir WhatsApp Web General
        const btnExtChatWeb = document.getElementById('btn-ext-chat-web');
        if (btnExtChatWeb) {
            btnExtChatWeb.addEventListener('click', () => {
                sendExternalWhatsApp(false);
            });
        }

        // Simulador de Entrada de Chat/WhatsApp (Pruebas del Bot)
        const btnSimChatSend = document.getElementById('btn-sim-chat-send');
        if (btnSimChatSend) {
            btnSimChatSend.addEventListener('click', () => {
                simulateIncomingWhatsApp();
            });
        }

        // --- BINDINGS PARA EL MÓDULO DE CONFIGURACIÓN ADELOG ---
        // 1. Navegación de Subpestañas
        const subtabs = document.querySelectorAll('.subtab-link');
        subtabs.forEach(subtab => {
            subtab.addEventListener('click', (e) => {
                e.preventDefault();
                const targetSub = subtab.getAttribute('data-subtab');
                if (targetSub === 'perfiles_rbac') {
                    switchTab('permissions');
                    return;
                }
                document.querySelectorAll('.subtab-link').forEach(s => s.classList.remove('active'));
                document.querySelectorAll('.subtab-content').forEach(sc => sc.classList.remove('active'));
                subtab.classList.add('active');
                const scContent = document.getElementById(`subtab-content-${targetSub}`);
                if (scContent) scContent.classList.add('active');
            });
        });

        // 2. Guardar Ajustes de Marca y Políticas
        const formMarca = document.getElementById('form-config-marca');
        if (formMarca) {
            formMarca.addEventListener('submit', (e) => {
                e.preventDefault();
                saveMarcaConfig();
            });
        }

        // 2b. Gestor de Feed de Redes Sociales
        const btnOpenModalSocial = document.getElementById('btn-open-modal-social');
        if (btnOpenModalSocial) {
            btnOpenModalSocial.addEventListener('click', () => {
                openSocialPostModal(null);
            });
        }

        const formSocialPost = document.getElementById('form-social-post');
        if (formSocialPost) {
            formSocialPost.addEventListener('submit', (e) => {
                e.preventDefault();
                saveSocialPost();
            });
        }

        // 3. Guardar Ajustes SMTP
        const formSmtp = document.getElementById('form-config-smtp');
        if (formSmtp) {
            formSmtp.addEventListener('submit', (e) => {
                e.preventDefault();
                saveSmtpConfig();
            });
        }

        // 4. Probar SMTP
        const btnTestSmtp = document.getElementById('btn-test-smtp');
        if (btnTestSmtp) {
            btnTestSmtp.addEventListener('click', () => {
                runSmtpTest();
            });
        }

        // 5. Guardar Ajustes APIs
        const formApis = document.getElementById('form-config-apis');
        if (formApis) {
            formApis.addEventListener('submit', (e) => {
                e.preventDefault();
                saveApisConfig();
            });
        }

        // 5b. Probar APIs
        document.querySelectorAll('.btn-test-api').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const service = e.currentTarget.getAttribute('data-service');
                runApiTest(service);
            });
        });

        // 5c. Administrador de Flujos de Notificaciones
        document.querySelectorAll('.btn-flow-toggle').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const currentBtn = e.currentTarget;
                const flowKey = currentBtn.getAttribute('data-flow');
                const currentActive = currentBtn.getAttribute('data-active');
                const nextActive = currentActive === '1' ? '0' : '1';
                
                // Cambiar estado visual inmediatamente
                setFlowButtonState(currentBtn.id, nextActive);
                
                // Guardar en la base de datos
                const payload = {};
                payload[flowKey] = nextActive;
                
                fetch('../backend/api/settings.php?action=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(res => res.json())
                .then(data => {
                    if (data.exito) {
                        showNotification("✓ Flujo de notificación actualizado.", "success");
                    } else {
                        showNotification("❌ Error al guardar estado del flujo.", "danger");
                        setFlowButtonState(currentBtn.id, currentActive);
                    }
                })
                .catch(() => {
                    showNotification("❌ Error de red al guardar estado del flujo.", "danger");
                    setFlowButtonState(currentBtn.id, currentActive);
                });
            });
        });

        // 5d. Configuración y Modificación de Flujos Modal
        const btnConfigFlows = document.getElementById('btn-configure-flows-modal');
        if (btnConfigFlows) {
            btnConfigFlows.addEventListener('click', () => {
                openModal('modal-configure-flows');
            });
        }

        const selectConfigFlow = document.getElementById('select-config-flow-type');
        if (selectConfigFlow) {
            selectConfigFlow.addEventListener('change', (e) => {
                const type = e.target.value;
                document.querySelectorAll('.flow-config-panel').forEach(panel => {
                    panel.style.display = 'none';
                });
                const targetPanel = document.getElementById(`flow-config-panel-${type}`);
                if (targetPanel) targetPanel.style.display = 'block';
            });
        }

        const btnSaveFlows = document.getElementById('btn-save-flows-templates');
        if (btnSaveFlows) {
            btnSaveFlows.addEventListener('click', () => {
                const payload = {
                    flow_email_subject: document.getElementById('flow-email-subject').value,
                    flow_email_body: document.getElementById('flow-email-body').value,
                    flow_whatsapp_body: document.getElementById('flow-whatsapp-body').value,
                    flow_helpdesk_subject: document.getElementById('flow-helpdesk-subject').value,
                    flow_helpdesk_body: document.getElementById('flow-helpdesk-body').value
                };

                fetch('../backend/api/settings.php?action=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(res => res.json())
                .then(data => {
                    if (data.exito) {
                        showNotification("✓ Plantillas de flujo guardadas correctamente.", "success");
                        closeModal('modal-configure-flows');
                    } else {
                        showNotification("❌ Error al guardar plantillas.", "danger");
                    }
                })
                .catch(err => {
                    console.error("Error al guardar plantillas:", err);
                    showNotification("❌ Error de conexión.", "danger");
                });
            });
        }

        // 6. Motor ETL: Upload de Archivo (Clic y Drag & Drop)
        const etlUploadArea = document.getElementById('etl-upload-area');
        const etlFileInput = document.getElementById('etl-file-input');
        
        if (etlUploadArea && etlFileInput) {
            etlUploadArea.addEventListener('click', () => etlFileInput.click());
            
            etlUploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                etlUploadArea.style.borderColor = 'var(--secondary)';
                etlUploadArea.style.background = 'rgba(227, 161, 19, 0.05)';
            });
            
            etlUploadArea.addEventListener('dragleave', () => {
                etlUploadArea.style.borderColor = 'var(--border-color)';
                etlUploadArea.style.background = 'transparent';
            });
            
            etlUploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                etlUploadArea.style.borderColor = 'var(--border-color)';
                etlUploadArea.style.background = 'transparent';
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    etlFileInput.files = files;
                    handleEtlUpload(files[0]);
                }
            });
            
            etlFileInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    handleEtlUpload(file);
                }
            });
        }

        // 7. Motor ETL: Cancelar Mapeo
        const btnCancelEtl = document.getElementById('btn-cancel-etl');
        if (btnCancelEtl) {
            btnCancelEtl.addEventListener('click', () => {
                cancelEtlMapping();
            });
        }

        // 8. Motor ETL: Ejecutar Carga
        const btnRunEtl = document.getElementById('btn-run-etl');
        if (btnRunEtl) {
            btnRunEtl.addEventListener('click', () => {
                runEtlImport();
            });
        }
    }

    function switchTab(tabId) {
        document.querySelectorAll('.tab-link').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

        const targetLink = document.querySelector(`.tab-link[data-tab="${tabId}"]`);
        const targetContent = document.getElementById(`tab-content-${tabId}`);

        if (targetLink && targetContent) {
            targetLink.classList.add('active');
            targetContent.classList.add('active');
            State.activeTab = tabId;
        }

        // Disparar cargas dinámicas según pestaña
        if (tabId === 'padron') {
            loadPadron();
        } else if (tabId === 'red_territorial') {
            loadRedTerritorial();
        } else if (tabId === 'chat') {
            loadChats();
            startChatPolling();
        } else if (tabId === 'helpdesk') {
            loadTickets();
        } else if (tabId === 'docs') {
            loadDocs();
        } else if (tabId === 'permissions') {
            loadUsersList();
            loadCampaignsList();
        } else if (tabId === 'config') {
            loadConfigData();
        } else {
            // Detener intervalos innecesarios si salimos de chat
            stopChatPolling();
        }
    }

    // ─── LÓGICA DE LANDING PÚBLICA ──────────────────────────────────────────
    function setupPublicEventListeners() {
        // Enviar formulario público
        const publicForm = document.getElementById('public-voter-form');
        if (publicForm) {
            publicForm.addEventListener('submit', (e) => {
                e.preventDefault();
                savePublicVoter();
            });
        }

        // OCR en público (Carga de archivo)
        const publicOcrFile = document.getElementById('public-ocr-file');
        if (publicOcrFile) {
            publicOcrFile.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    processOcrImage(file, true);
                }
                e.target.value = '';
            });
        }

        // Inicializar Cámara web en público
        const btnPublicCamera = document.getElementById('btn-public-camera-ocr');
        if (btnPublicCamera) {
            btnPublicCamera.addEventListener('click', () => {
                initWebcam(true);
            });
        }

        const btnPublicCapture = document.getElementById('btn-public-capture-photo');
        if (btnPublicCapture) {
            btnPublicCapture.addEventListener('click', () => {
                capturePhoto(true);
            });
        }

        const btnPublicCloseCam = document.getElementById('btn-public-close-camera');
        if (btnPublicCloseCam) {
            btnPublicCloseCam.addEventListener('click', () => {
                stopWebcam(true);
            });
        }

        // Autocompletado y Validación en Padrón Circunscripción 3 (Público)
        setupCedulaPadronListener('public-cedula', 'public-');

        // Si al cargar o refrescar la página el input ya contiene una cédula válida (ej: 11 dígitos), consultarla de inmediato
        const pubCedInput = document.getElementById('public-cedula');
        if (pubCedInput && pubCedInput.value.replace(/\D/g, '').length === 11) {
            buscarEnPadronCirc3(pubCedInput.value, 'public-');
        }
    }

    function trackCampaignClick(code) {
        fetch('../backend/api/campaigns.php?action=track_click', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ codigo: code })
        }).catch(err => console.log("Error tracking campaign:", err));
    }

    // ─── DASHBOARD ESTADÍSTICOS ─────────────────────────────────────────────
    let myChart = null;

    function loadDashboardData() {
        fetch('../backend/api/dashboard.php')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    // Cargar números de métricas
                    document.getElementById('stat-total-inscritos').textContent = data.resumen.total_inscritos;
                    document.getElementById('stat-total-manual').textContent = data.resumen.origenes.Manual;
                    document.getElementById('stat-total-ocr').textContent = data.resumen.origenes.OCR + data.resumen.origenes['WhatsApp Bot'] + data.resumen.origenes['QR Campaign'];
                    document.getElementById('stat-incidencias').textContent = data.resumen.incidencias_abiertas;

                    // Renderizar gráficos comparativos
                    renderChart(data.regiones);
                }
            });
    }

    function renderChart(regiones) {
        const ctx = document.getElementById('growthChart');
        if (!ctx) return;

        const labels = regiones.map(r => r.region);
        const votos2024 = regiones.map(r => r.votos_2024);
        const inscritos2026 = regiones.map(r => r.inscritos_2026);

        if (myChart) {
            myChart.destroy();
        }

        // Determinar tema activo
        const isLight = document.body.classList.contains('light-theme');
        const gridColor = isLight ? 'rgba(0, 0, 0, 0.1)' : 'rgba(255, 255, 255, 0.05)';
        const textColor = isLight ? '#475569' : '#94a3b8';
        const legendColor = isLight ? '#0f172a' : '#f8fafc';
        const dataset1Bg = isLight ? 'rgba(0, 84, 166, 0.15)' : 'rgba(255, 255, 255, 0.15)';
        const dataset1Border = isLight ? 'rgba(0, 84, 166, 0.3)' : 'rgba(255, 255, 255, 0.3)';

        // @ts-ignore
        myChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Votos Obtenidos 2024 (Histórico)',
                        data: votos2024,
                        backgroundColor: dataset1Bg,
                        borderColor: dataset1Border,
                        borderWidth: 1
                    },
                    {
                        label: 'Meta/Inscritos Actuales 2028 (Tiempo Real)',
                        data: inscritos2026,
                        backgroundColor: '#E3A113', // Oro PRM
                        borderColor: '#c4870c',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor },
                        ticks: { color: textColor }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: textColor }
                    }
                },
                plugins: {
                    legend: {
                        labels: { color: legendColor, font: { family: 'Outfit' } }
                    }
                }
            }
        });
    }

    function startDashboardPolling() {
        State.dashboardInterval = setInterval(() => {
            if (State.activeTab === 'dashboard') {
                loadDashboardData();
            }
        }, 15000); // Refrescar cada 15 segundos
    }

    // ─── OCR Y WEBCAM ───────────────────────────────────────────────────────
    function resetOcrButtonState(isPublic = false) {
        const mode = isPublic ? 'public' : 'admin';
        ocrState[mode].front = false;
        ocrState[mode].dorsal = false;
        const ocrBtnId = isPublic ? 'btn-public-ocr' : 'btn-admin-ocr';
        const ocrBtn = document.getElementById(ocrBtnId);
        if (ocrBtn) {
            ocrBtn.innerHTML = `<i class="fa fa-upload"></i> ${isPublic ? 'Subir foto frontal de cédula' : 'Cargar foto frontal de cédula'}`;
        }
        const prefix = isPublic ? 'public-' : '';
        const feedbackEl = document.getElementById(prefix + 'cedula-feedback') || document.getElementById(prefix + 'cedula-validation');
        if (feedbackEl) {
            feedbackEl.style.display = 'none';
            feedbackEl.innerHTML = '';
        }
    }

    function initWebcam(isPublic = false) {
        const containerId = isPublic ? 'public-camera-container' : 'camera-container';
        const videoId = isPublic ? 'public-webcam' : 'webcam';
        const camContainer = document.getElementById(containerId);
        const video = document.getElementById(videoId);
        if (!video || !camContainer) return;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert("No se pudo iniciar la cámara: El navegador o el contexto no permite acceso a la cámara (requiere localhost o conexión segura HTTPS).");
            camContainer.style.display = 'none';
            return;
        }

        camContainer.style.display = 'block';

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(stream => {
                streamRef = stream;
                window.streamRef = stream;
                video.srcObject = stream;
                video.play();
            })
            .catch(err => {
                alert("No se pudo iniciar la cámara: " + err.message);
                camContainer.style.display = 'none';
            });
    }

    function capturePhoto(isPublic = false) {
        const videoId = isPublic ? 'public-webcam' : 'webcam';
        const video = document.getElementById(videoId);
        const activeStream = streamRef || window.streamRef;
        if (!video || !activeStream) return;

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 480;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

        canvas.toBlob(blob => {
            const file = new File([blob], "captured_ocr.jpg", { type: "image/jpeg" });
            processOcrImage(file, isPublic);
            
            // Detener cámara
            stopWebcam(isPublic);
        }, 'image/jpeg');
    }

    function stopWebcam(isPublic = false) {
        const activeStream = streamRef || window.streamRef;
        if (activeStream) {
            try {
                activeStream.getTracks().forEach(track => track.stop());
            } catch(e) {
                console.warn("Error stopping stream tracks:", e);
            }
            streamRef = null;
            window.streamRef = null;
        }
        const containerId = isPublic ? 'public-camera-container' : 'camera-container';
        const camContainer = document.getElementById(containerId);
        if (camContainer) camContainer.style.display = 'none';
    }

    function processOcrImage(file, isPublic = false) {
        const loader = document.getElementById(isPublic ? 'public-ocr-loader' : 'ocr-loader');
        if (loader) loader.style.display = 'inline-flex';

        const formData = new FormData();
        formData.append('imagen', file);

        const apiPath = '../backend/api/ocr.php';

        fetch(apiPath, {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (loader) loader.style.display = 'none';
                if (data.exito) {
                    const mode = isPublic ? 'public' : 'admin';
                    const lado = (data.datos && data.datos.lado_detectado) ? data.datos.lado_detectado : 'frontal';

                    // Actualizar formulario preservando datos existentes
                    fillVoterForm(data.datos, isPublic);

                    // Registrar avance secuencial según el lado detectado por el OCR
                    if (lado === 'ambos') {
                        ocrState[mode].front = true;
                        ocrState[mode].dorsal = true;
                    } else if (lado === 'reverso') {
                        ocrState[mode].dorsal = true;
                    } else {
                        // Es frontal
                        ocrState[mode].front = true;
                    }

                    const ocrBtnId = isPublic ? 'btn-public-ocr' : 'btn-admin-ocr';
                    const ocrBtn = document.getElementById(ocrBtnId);

                    if (ocrState[mode].front && ocrState[mode].dorsal) {
                        if (ocrBtn) {
                            ocrBtn.innerHTML = `<i class="fa fa-check-circle" style="color:#10b981;"></i> ${isPublic ? '✓ Frente y Reverso cargados' : '✓ Cédula Completa (Frente y Reverso)'}`;
                        }
                        showNotification("✓ Cédula completa (frente y reverso) procesada con éxito.", "success", isPublic);
                    } else if (ocrState[mode].front) {
                        if (ocrBtn) {
                            ocrBtn.innerHTML = `<i class="fa fa-upload"></i> ${isPublic ? 'Subir foto dorsal de cédula' : 'Cargar foto dorsal de cédula'}`;
                        }
                        showNotification("✓ Datos frontales extraídos con éxito. Ahora suba la foto dorsal (reverso) de la cédula.", "info", isPublic);
                    } else if (ocrState[mode].dorsal) {
                        if (ocrBtn) {
                            ocrBtn.innerHTML = `<i class="fa fa-upload"></i> ${isPublic ? 'Subir foto frontal de cédula' : 'Cargar foto frontal de cédula'}`;
                        }
                        showNotification("✓ Datos de votación del reverso extraídos. Ahora suba la foto frontal de la cédula.", "info", isPublic);
                    }
                } else {
                    showNotification("✗ Error OCR: " + data.mensaje, "danger", isPublic);
                }
            })
            .catch(err => {
                if (loader) loader.style.display = 'none';
                showNotification("✗ Error de red al ejecutar OCR.", "danger", isPublic);
            });
    }

    function validarCedulaLuhn(cedula) {
        if (!cedula) return false;
        const clean = String(cedula).replace(/\D/g, '');
        if (clean.length !== 11) return false;
        const weights = [1, 2, 1, 2, 1, 2, 1, 2, 1, 2];
        let sum = 0;
        for (let i = 0; i < 10; i++) {
            let mult = parseInt(clean[i], 10) * weights[i];
            if (mult >= 10) {
                sum += Math.floor(mult / 10) + (mult % 10);
            } else {
                sum += mult;
            }
        }
        const checkDigit = (10 - (sum % 10)) % 10;
        return checkDigit === parseInt(clean[10], 10);
    }

    function buscarEnPadronCirc3(cedulaInput, targetFormPrefix = '') {
        const cleanCed = cedulaInput.replace(/\D/g, '');
        if (cleanCed.length === 0) return;

        let feedbackEl = document.getElementById(targetFormPrefix + 'cedula-feedback') || document.getElementById(targetFormPrefix + 'cedula-validation');
        if (!feedbackEl) {
            feedbackEl = document.createElement('div');
            feedbackEl.id = targetFormPrefix + 'cedula-feedback';
            feedbackEl.style.fontSize = '12px';
            feedbackEl.style.marginTop = '4px';
            feedbackEl.style.fontWeight = 'bold';
            const cedInputEl = document.getElementById(targetFormPrefix + 'cedula');
            if (cedInputEl && cedInputEl.parentNode) {
                cedInputEl.parentNode.appendChild(feedbackEl);
            }
        }
        feedbackEl.style.display = 'block';

        if (cleanCed.length < 11) {
            feedbackEl.innerHTML = '<span style="color:#f59e0b; font-size:12px;"><i class="fa fa-info-circle"></i> Ingrese los 11 dígitos de su cédula</span>';
            return;
        }

        if (!validarCedulaLuhn(cleanCed)) {
            feedbackEl.innerHTML = '<span style="color:var(--danger); font-size:12px;"><i class="fa fa-times-circle"></i> Cédula con dígito verificador inválido</span>';
            return;
        }

        feedbackEl.innerHTML = '<span style="color:var(--secondary); font-size:12px;"><i class="fa fa-spinner fa-spin"></i> Consultado Padrón Máster Circunscripción 3...</span>';

        fetch(`../backend/api/padron_lookup.php?cedula=${encodeURIComponent(cedulaInput)}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito && data.encontrado && data.elector) {
                    const v = data.elector;
                    
                    const elemNombres = document.getElementById(targetFormPrefix + 'nombres');
                    if (elemNombres) elemNombres.value = v.nombres;

                    const elemApellidos = document.getElementById(targetFormPrefix + 'apellidos');
                    if (elemApellidos) elemApellidos.value = v.apellidos;

                    const elemColegio = document.getElementById(targetFormPrefix + 'colegio_electoral');
                    if (elemColegio) elemColegio.value = v.colegio_electoral;

                    const elemSector = document.getElementById(targetFormPrefix + 'sector');
                    if (elemSector && v.sector) elemSector.value = v.sector;

                    const elemMunicipio = document.getElementById(targetFormPrefix + 'municipio');
                    if (elemMunicipio && v.municipio) elemMunicipio.value = v.municipio;

                    const elemRecinto = document.getElementById(targetFormPrefix + 'recinto_ubicacion');
                    if (elemRecinto && v.recinto) elemRecinto.value = v.recinto;

                    const elemDireccion = document.getElementById(targetFormPrefix + 'direccion');
                    if (elemDireccion && v.direccion && !elemDireccion.value) elemDireccion.value = v.direccion;

                    const elemTelefono = document.getElementById(targetFormPrefix + 'telefono');
                    if (elemTelefono) {
                        if (v.celular) {
                            elemTelefono.value = v.celular;
                        } else if (v.telefono_sugerido && !elemTelefono.value) {
                            elemTelefono.value = v.telefono_sugerido;
                        }
                        elemTelefono.dispatchEvent(new Event('input'));
                    }

                    const elemTelFijo = document.getElementById(targetFormPrefix + 'telefono_fijo');
                    if (elemTelFijo && v.telefono_fijo) {
                        elemTelFijo.value = v.telefono_fijo;
                    }

                    const fuenteBadge = v.region ? ` (${v.region})` : '';
                    const posBadge = v.posicion_recinto ? ` [Posición: ${v.posicion_recinto}]` : (v.numero_orden ? ` [Posición: ${v.numero_orden}]` : '');
                    const mesaBadge = v.colegio_electoral ? ` [Mesa: ${v.colegio_electoral}]` : '';
                    
                    let htmlFeedback = `<span style="color:#10b981;"><i class="fa fa-check-circle"></i> Verificado en Padrón Oficial JCE / Circ. 3${fuenteBadge}${mesaBadge}${posBadge}</span>`;
                    
                    if (v.telefono_sugerido) {
                        htmlFeedback += ` <small style="color:#E3A113; font-weight:bold;">[Tel. Sugerido: ${v.telefono_sugerido}]</small>`;
                    }
                    if (v.militancia_historica && v.militancia_historica.includes('RATIFICADO')) {
                        htmlFeedback += ` <span class="badge badge-success" style="font-size:10px; margin-left:4px;">RATIFICADO</span>`;
                    }

                    feedbackEl.innerHTML = htmlFeedback;
                } else if (data.exito && !data.encontrado) {
                    feedbackEl.innerHTML = `<span style="color:#f59e0b; font-size:12px;"><i class="fa fa-info-circle"></i> ${data.mensaje || 'Cédula no localizada en Padrón Circ. 3 (Registro manual activo).'}</span>`;
                } else {
                    feedbackEl.innerHTML = `<span style="color:var(--danger); font-size:12px;"><i class="fa fa-times-circle"></i> ${data.mensaje || 'Error al validar cédula en padrón.'}</span>`;
                }
            })
            .catch(err => {
                console.error("Error al consultar padrón:", err);
                if (feedbackEl) feedbackEl.innerHTML = '<span style="color:var(--danger); font-size:12px;"><i class="fa fa-times-circle"></i> Error de conexión con el servicio del padrón.</span>';
            });
    }

    function fillVoterForm(datos, isPublic = false) {
        const prefix = isPublic ? 'public-' : '';
        
        // Actualización inteligente no destructiva (Normas PLAD):
        // Solo actualiza los campos cuando el valor entrante del OCR no esté vacío,
        // preservando los datos ya cargados previamente (ej: al pasar de frontal a dorsal).
        const updateField = (id, val) => {
            if (val !== undefined && val !== null) {
                const strVal = String(val).trim();
                if (strVal !== '') {
                    const el = document.getElementById(prefix + id);
                    if (el) el.value = strVal;
                }
            }
        };

        updateField('cedula', datos.cedula);
        updateField('nombres', datos.nombres);
        updateField('apellidos', datos.apellidos);
        updateField('colegio_electoral', datos.colegio_electoral);
        updateField('recinto_ubicacion', datos.recinto_ubicacion);
        updateField('direccion', datos.direccion);
        updateField('sector', datos.sector);
        updateField('municipio', datos.municipio);
        updateField('telefono_fijo', datos.telefono_fijo);

        // Si se extrajo o completó una cédula válida, ejecutar búsqueda automática en padrón circ 3
        const cedInputEl = document.getElementById(prefix + 'cedula');
        if (cedInputEl && cedInputEl.value) {
            const cleanCed = cedInputEl.value.replace(/\D/g, '');
            if (cleanCed.length === 11) {
                buscarEnPadronCirc3(cedInputEl.value, prefix);
            }
        }
    }

    // ─── GESTIÓN DE VOTANTES (PADRÓN) ───────────────────────────────────────
    function loadPadron() {
        const search = document.getElementById('padron-search') ? document.getElementById('padron-search').value : '';
        const region = document.getElementById('filter-region') ? document.getElementById('filter-region').value : '';
        const periodo = document.getElementById('filter-periodo') ? document.getElementById('filter-periodo').value : '2028';
        const tipoElector = document.getElementById('filter-padron-tipo') ? document.getElementById('filter-padron-tipo').value : '';
        
        fetch(`../backend/api/voters.php?action=list&search=${encodeURIComponent(search)}&region=${encodeURIComponent(region)}&periodo=${encodeURIComponent(periodo)}&tipo_elector=${encodeURIComponent(tipoElector)}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    renderPadronTable(data.votantes);
                }
            });
    }

    function renderPadronTable(votantes) {
        const tbody = document.getElementById('padron-tbody');
        if (!tbody) return;

        tbody.innerHTML = '';
        
        if (votantes.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; color: var(--text-muted);">No se encontraron votantes inscritos.</td></tr>`;
            return;
        }

        votantes.forEach(v => {
            const tr = document.createElement('tr');
            const badgeIrregular = v.estado_datos === 'pendiente-reg-data' 
                ? ' <span class="badge badge-danger" style="font-size:10px; padding: 2px 6px;" title="Datos Irregulares - Requiere Regularización JCE"><i class="fa fa-exclamation-triangle"></i> IRREGULAR</span>' 
                : '';
            const badgeML = (v.es_militante_lider == 1)
                ? ' <span class="badge" style="background:#E3A113; color:#000; font-size:10px; font-weight:bold; padding:2px 6px;"><i class="fa fa-user-tag"></i> ML</span>'
                : ' <span class="badge" style="background:rgba(16, 185, 129, 0.15); color:#10b981; border:1px solid #10b981; font-size:10px; padding:2px 6px;">Nuevo Elector</span>';

            tr.innerHTML = `
                <td><strong>#${v.numero_lista}</strong></td>
                <td><span style="font-family:monospace; font-weight:600;">${v.cedula}</span></td>
                <td>${v.nombres} ${v.apellidos} ${badgeML}${badgeIrregular}</td>
                <td><span class="badge badge-primary">${v.colegio_electoral}</span></td>
                <td>${v.sector}, ${v.municipio}</td>
                <td>${v.coordinador}</td>
                <td><span class="badge ${v.canal_origen === 'Manual' ? 'badge-warning' : 'badge-success'}">${v.canal_origen}</span></td>
                <td>
                    <button class="btn btn-outline btn-sm btn-print-row" data-id="${v.id}" title="Imprimir Comprobante Oficial"><i class="fa fa-print"></i></button>
                    <button class="btn btn-outline btn-sm btn-email-row" data-id="${v.id}" data-email="${v.email || ''}" title="Enviar Comprobante por Correo"><i class="fa fa-envelope"></i></button>
                    ${State.perms.can_edit == 1 ? `<button class="btn btn-outline btn-sm btn-edit-row" data-id="${v.id}" title="Editar Votante"><i class="fa fa-edit"></i></button>` : ''}
                </td>
            `;
            
            // Evento imprimir voucher individual
            tr.querySelector('.btn-print-row').addEventListener('click', () => printVoterVoucher(v.id));
            
            // Evento enviar comprobante por correo
            tr.querySelector('.btn-email-row').addEventListener('click', () => {
                const defaultEmail = v.email || '';
                const emailPrompt = prompt("Confirme o ingrese el correo del votante para despachar el comprobante oficial:", defaultEmail);
                if (emailPrompt && emailPrompt.trim()) {
                    showNotification("⏳ Enviando comprobante por correo...", "info");
                    fetch(`../backend/api/voters.php?action=email_voucher&id=${v.id}&email=${encodeURIComponent(emailPrompt.trim())}`)
                        .then(res => res.json())
                        .then(resData => {
                            if (resData.exito) {
                                showNotification(`✓ Comprobante [${resData.codigo_comprobante || ''}] enviado exitosamente a ${emailPrompt}.`, "success");
                            } else {
                                alert("Error al enviar comprobante: " + resData.mensaje);
                            }
                        })
                        .catch(() => alert("Error de red al enviar comprobante."));
                }
            });

            if (State.perms.can_edit == 1) {
                tr.querySelector('.btn-edit-row').addEventListener('click', () => openEditModal(v.id));
            }
            
            tbody.appendChild(tr);
        });
    }

    function isCircunscripcionValida(municipio, sector, recinto) {
        const muniUpper = (municipio || '').toUpperCase();
        const sectUpper = (sector || '').toUpperCase();
        const recUpper = (recinto || '').toUpperCase();
        
        let pertenece = false;
        
        if (muniUpper.includes('BOCA CHICA') || sectUpper.includes('BOCA CHICA') || recUpper.includes('BOCA CHICA') ||
            muniUpper.includes('GUERRA') || sectUpper.includes('GUERRA') || recUpper.includes('GUERRA') ||
            muniUpper.includes('SAN LUIS') || sectUpper.includes('SAN LUIS') || sectUpper.includes('BONITO') || recUpper.includes('SAN LUIS') ||
            muniUpper.includes('CALETA') || sectUpper.includes('CALETA') || recUpper.includes('CALETA')) {
            pertenece = true;
        }
        
        if (muniUpper.includes('SANTO DOMINGO ESTE') || muniUpper.includes('SDE') || muniUpper.includes('ESTE')) {
            if (!muniUpper.includes('OESTE') && !muniUpper.includes('NORTE')) {
                pertenece = true;
            }
        }
        
        return pertenece;
    }

    function saveVoter() {
        const id = document.getElementById('voter-id').value;
        const municipioVal = document.getElementById('municipio').value;
        const sectorVal = document.getElementById('sector').value;
        const recintoVal = document.getElementById('recinto_ubicacion').value;

        if (!isCircunscripcionValida(municipioVal, sectorVal, recintoVal)) {
            alert("Error de Data Sucia: El elector no pertenece a la Circunscripción 3 de Santo Domingo. La plataforma solo permite inscribir votantes de SDE Región 3, Guerra, Boca Chica, San Luis y La Caleta para evitar datos erróneos.");
            return;
        }

        const esML = document.getElementById('voter-es-ml') && document.getElementById('voter-es-ml').checked ? 1 : 0;
        const nivelEst = document.getElementById('voter-nivel-estructura') ? document.getElementById('voter-nivel-estructura').value : (esML ? 'ML - Militante Líder' : 'Votante');
        const coordPadre = document.getElementById('voter-coordinador-padre') ? document.getElementById('voter-coordinador-padre').value : '';

        const voterData = {
            cedula: document.getElementById('cedula').value,
            nombres: document.getElementById('nombres').value,
            apellidos: document.getElementById('apellidos').value,
            colegio_electoral: document.getElementById('colegio_electoral').value,
            recinto_ubicacion: recintoVal,
            direccion: document.getElementById('direccion').value,
            sector: sectorVal,
            municipio: municipioVal,
            telefono: document.getElementById('telefono').value,
            telefono_fijo: document.getElementById('telefono_fijo') ? document.getElementById('telefono_fijo').value.trim() : '',
            email: document.getElementById('email').value,
            coordinador: document.getElementById('coordinador').value,
            centro_acopio: document.getElementById('centro_acopio').value,
            es_militante_lider: esML,
            nivel_estructura: nivelEst,
            coordinador_padre_id: coordPadre,
            canal_origen: id ? 'Manual' : (document.getElementById('ocr-file').files.length > 0 ? 'OCR' : 'Manual')
        };

        const action = id ? 'edit' : 'register';
        if (id) {
            voterData.id = id;
        }

        fetch(`../backend/api/voters.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(voterData)
        })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    closeModal('voter-modal');
                    loadPadron();
                    loadDashboardData();
                    if (State.activeTab === 'red_territorial') {
                        loadRedTerritorial();
                    }
                    showNotification(id ? "✓ Votante corregido correctamente." : "✓ Votante registrado exitosamente.", "success");
                    
                    resetOcrButtonState(false);
                    
                    if (!id && data.datos) {
                        showCustomConfirm("¿Desea imprimir el comprobante de inscripción ahora?").then(confirmed => {
                            if (confirmed) {
                                printVoterVoucher(data.datos.id);
                            }
                        });
                    }
                } else if (data.codigo_irregular) {
                    const proposalText = `${data.mensaje}\n\nSe propone registrar al elector en estado 'Pendiente de Regularización' (con alertas al elector y al coordinador ${data.coordinador_nombre}). ¿Desea proceder con este registro especial?`;
                    showCustomConfirm(proposalText).then(confirmed => {
                        if (confirmed) {
                            voterData.force_irregular = true;
                            fetch(`../backend/api/voters.php?action=${action}`, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(voterData)
                            })
                            .then(res => res.json())
                            .then(data2 => {
                                if (data2.exito) {
                                    closeModal('voter-modal');
                                    loadPadron();
                                    loadDashboardData();
                                    if (State.activeTab === 'red_territorial') {
                                        loadRedTerritorial();
                                    }
                                    showNotification("✓ Elector irregular registrado como 'Pendiente Regularización'. Notificaciones enviadas.", "warning");
                                } else {
                                    alert(data2.mensaje);
                                }
                            })
                            .catch(() => alert("Error de red al forzar el registro."));
                        }
                    });
                } else {
                    alert(data.mensaje);
                }
            })
            .catch(err => alert("Error de red al guardar el registro."));
    }

    function savePublicVoter() {
        const municipioVal = document.getElementById('public-municipio').value;
        const sectorVal = document.getElementById('public-sector').value;
        const recintoVal = document.getElementById('public-recinto_ubicacion').value;

        if (!isCircunscripcionValida(municipioVal, sectorVal, recintoVal)) {
            alert("Error de Data Sucia: El elector no pertenece a la Circunscripción 3 de Santo Domingo. La plataforma solo permite inscribir votantes de SDE Región 3, Guerra, Boca Chica, San Luis y La Caleta para evitar datos erróneos.");
            return;
        }

        const voterData = {
            cedula: document.getElementById('public-cedula').value,
            nombres: document.getElementById('public-nombres').value,
            apellidos: document.getElementById('public-apellidos').value,
            colegio_electoral: document.getElementById('public-colegio_electoral').value,
            recinto_ubicacion: document.getElementById('public-recinto_ubicacion').value,
            direccion: document.getElementById('public-direccion').value,
            sector: document.getElementById('public-sector').value,
            municipio: document.getElementById('public-municipio').value,
            telefono: document.getElementById('public-telefono').value,
            telefono_fijo: document.getElementById('public-telefono_fijo') ? document.getElementById('public-telefono_fijo').value.trim() : '',
            email: document.getElementById('public-email').value,
            coordinador: document.getElementById('public-coordinador').value,
            centro_acopio: 'Campaña Digital QR',
            canal_origen: 'QR Campaign',
            campana_codigo: State.campanaCodigo
        };

        fetch('../backend/api/voters.php?action=public_register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(voterData)
        })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    showNotification("¡Inscripción Exitosa! Su número de lista oficial es: " + data.datos.numero_lista, "success", true);
                    document.getElementById('public-voter-form').reset();
                    
                    resetOcrButtonState(true);
                    
                    // Mostrar comprobante imprimible en pantalla
                    renderPublicVoucher(data.datos);
                } else {
                    alert(data.mensaje);
                }
            })
            .catch(() => alert("Error de red al guardar el registro."));
    }

    function openEditModal(id) {
        resetOcrButtonState(false);
        fetch(`../backend/api/voters.php?action=detail&id=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    const v = data.votante;
                    document.getElementById('voter-form-title').textContent = "Corregir Registro de Votante";
                    document.getElementById('voter-id').value = v.id;
                    
                    document.getElementById('cedula').value = v.cedula;
                    document.getElementById('nombres').value = v.nombres;
                    document.getElementById('apellidos').value = v.apellidos;
                    document.getElementById('colegio_electoral').value = v.colegio_electoral;
                    document.getElementById('recinto_ubicacion').value = v.recinto_ubicacion;
                    document.getElementById('direccion').value = v.direccion;
                    document.getElementById('sector').value = v.sector;
                    document.getElementById('municipio').value = v.municipio;
                    document.getElementById('telefono').value = v.telefono;
                    if (document.getElementById('telefono_fijo')) {
                        document.getElementById('telefono_fijo').value = v.telefono_fijo || '';
                    }
                    document.getElementById('email').value = v.email || '';
                    document.getElementById('coordinador').value = v.coordinador;
                    document.getElementById('centro_acopio').value = v.centro_acopio;

                    if (document.getElementById('voter-es-ml')) {
                        document.getElementById('voter-es-ml').checked = (v.es_militante_lider == 1);
                        const detailsBox = document.getElementById('voter-ml-details');
                        if (detailsBox) {
                            detailsBox.style.display = (v.es_militante_lider == 1) ? 'block' : 'none';
                        }
                        if (document.getElementById('voter-nivel-estructura')) {
                            document.getElementById('voter-nivel-estructura').value = v.nivel_estructura || 'ML - Militante Líder';
                        }
                        if (document.getElementById('voter-coordinador-padre')) {
                            document.getElementById('voter-coordinador-padre').value = v.coordinador_padre_id || '';
                        }
                    }

                    openModal('voter-modal');
                }
            });
    }

    // ─── COMPROBANTES DE INSCRIPCIÓN (VOUCHERS) ─────────────────────────────
    function printVoterVoucher(id) {
        fetch(`../backend/api/voters.php?action=detail&id=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    const v = data.votante;
                    let printArea = document.getElementById('print-voucher-area');
                    if (!printArea) {
                        const div = document.createElement('div');
                        div.id = 'print-voucher-area';
                        document.body.appendChild(div);
                        printArea = div;
                    }
                    printArea.className = 'print-hidden-container';
                    
                    const combinedId = v.codigo_comprobante || (`PAD2832-${v.numero_lista}-${v.cedula}`);
                    const validUrl = `${window.location.protocol}//${window.location.host}/PLATAFORMA%20DIGITAL-PAD-28-32/validar.php?cedula=${encodeURIComponent(v.cedula)}&folio=${encodeURIComponent(combinedId)}`;
                    const isFuera = v.es_fuera_circ3 || (v.region && v.region.includes('1')) || (v.sector && v.sector.toUpperCase().includes('ISABELITA')) || (v.colegio_electoral == '1823');
                    const tagElector = v.tipo_elector || (isFuera ? 'Nuevo Elector (Simpatizante Externo)' : (v.es_militante_lider == 1 ? 'Nuevo Elector (ML)' : 'Nuevo Elector'));
                    const estatusPRM = v.militancia_partido_label || (isFuera ? 'No figura en Padrón Circ. 3 (Elector Circunscripción 1 - Santo Domingo Este)' : 'Padrón Maestro JCE / PRM');
                    const registradorStr = (v.registrado_por_nombre || 'Sistema') + (v.perfil_registrador ? ` (${v.perfil_registrador})` : '');
                    const fechaIngesta = v.fecha_registro || new Date().toLocaleString('es-DO');
                    const direccionStr = v.direccion || 'N/A';
                    const demarcaStr = v.circunscripcion_elector || (isFuera ? 'Circunscripción 1 (Santo Domingo Este)' : 'Circunscripción 3 (Santo Domingo Este)');

                    printArea.innerHTML = `
                        <div style="font-family: Arial, sans-serif; max-width: 780px; margin: 0 auto; padding: 12px 16px; background: #ffffff; color: #000000; box-sizing: border-box;">
                            <!-- Header Banner matching the official campaign art spanning full header width -->
                            <div style="width: 100%; margin-bottom: 8px;">
                                <img src="../GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png" alt="Pastora Altagracia" style="width: 100%; height: auto; display: block; border-bottom: 3px solid #E3A113; border-radius: 6px;">
                            </div>
                            
                            <!-- White Metadata Section with boxes using table for strict print alignment -->
                            <table style="width: 100%; border-collapse: separate; border-spacing: 6px; margin-bottom: 6px;">
                                <tr>
                                    <td style="width: 58%; border: 1.5px solid #cbd5e1; padding: 5px 8px; border-radius: 6px; background: #f8fafc; font-size: 11.5px;">
                                        <strong style="color: #0054A6;">Coordinador:</strong>
                                        <span style="color: #334155; font-weight: 600; text-transform: uppercase;">${v.coordinador}</span>
                                    </td>
                                    <td style="width: 42%; border: 1.5px solid #cbd5e1; padding: 5px 8px; border-radius: 6px; background: #f8fafc; font-size: 11.5px;">
                                        <strong style="color: #0054A6;">Región / Sector:</strong>
                                        <span style="color: #334155; font-weight: 600; text-transform: uppercase;">${v.sector || 'SDE Circ. 3'}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="width: 58%; border: 1.5px solid #cbd5e1; padding: 5px 8px; border-radius: 6px; background: #f8fafc; font-size: 11.5px;">
                                        <strong style="color: #0054A6;">Teléfono:</strong>
                                        <span style="color: #334155; font-weight: 500;">${v.telefono}</span>
                                    </td>
                                    <td style="width: 42%; border: 1.5px solid #cbd5e1; padding: 5px 8px; border-radius: 6px; background: #f8fafc; font-size: 11.5px;">
                                        <strong style="color: #0054A6;">Zona / Municipio:</strong>
                                        <span style="color: #334155; font-weight: 500; text-transform: uppercase;">${v.municipio || 'Santo Domingo Este'}</span>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Centered White Voucher Card (Ahorro de Tinta para Impresión) -->
                            <div style="background-color: #ffffff; border: 2px solid #0054A6; border-radius: 10px; padding: 12px 14px; color: #000000; margin-bottom: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                                
                                <div style="text-align: center; margin-bottom: 8px;">
                                    <div style="display: inline-flex; align-items: center; gap: 5px; background-color: #ecfdf5; color: #059669; border: 1px solid #10b981; padding: 2px 10px; border-radius: 9999px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                                        ✓ Registro Completado & Verificado
                                    </div>
                                    <h2 style="font-size: 17px; font-weight: 800; color: #0054A6; margin: 4px 0 2px 0; font-family: sans-serif;">Comprobante Oficial de Inscripción</h2>
                                    <div style="display: inline-block; background-color: #fef3c7; color: #b45309; border: 1px solid #f59e0b; padding: 1px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase;">
                                        Periodo Electoral: ${v.periodo || '2028'}
                                    </div>
                                    ${isFuera ? `
                                    <div style="margin-top: 4px; background: #fffbeb; color: #b45309; border: 1px solid #f59e0b; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: bold; display: inline-block;">
                                        ⚠️ Simpatizante Externo (Votante Circunscripción 1 - Santo Domingo Este)
                                    </div>` : ''}
                                </div>
                                
                                <!-- Two column table layout: Data Block (68%) + QR Section (32%) -->
                                <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
                                    <tr>
                                        <!-- Left Data Block -->
                                        <td style="width: 68%; vertical-align: top; padding-right: 10px;">
                                            <div style="background-color: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; font-size: 11px;">
                                                <table style="width: 100%; border-collapse: collapse;">
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold; width: 36%;">Folio Oficial:</td>
                                                        <td style="padding: 2.5px 0; color: #0054A6; font-weight: bold; font-family: monospace; font-size: 11px;">${combinedId}</td>
                                                    </tr>
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Número de Lista:</td>
                                                        <td style="padding: 2.5px 0; color: #059669; font-weight: bold; font-size: 12.5px;">#${v.numero_lista}</td>
                                                    </tr>
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Cédula:</td>
                                                        <td style="padding: 2.5px 0; color: #000000; font-weight: 800; font-family: monospace;">${v.cedula}</td>
                                                    </tr>
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Nombre:</td>
                                                        <td style="padding: 2.5px 0; color: #000000; font-weight: 700; text-transform: uppercase;">${v.nombres} ${v.apellidos}</td>
                                                    </tr>
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Demarcación:</td>
                                                        <td style="padding: 2.5px 0; color: #b45309; font-weight: 700;">${demarcaStr}</td>
                                                    </tr>
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Etiqueta Elector:</td>
                                                        <td style="padding: 2.5px 0;"><span style="background: #dcfce7; color: #15803d; border: 1px solid #86efac; padding: 1px 6px; border-radius: 3px; font-weight: bold; font-size: 10px;">${tagElector}</span></td>
                                                    </tr>
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Padrón Partido (PRM):</td>
                                                        <td style="padding: 2.5px 0; color: #0054A6; font-weight: 700;">${estatusPRM}</td>
                                                    </tr>
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Padrón Candidata:</td>
                                                        <td style="padding: 2.5px 0; color: #15803d; font-weight: 700;">Padrón Activo Pastora Altagracia (2028)</td>
                                                    </tr>
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Colegio / Recinto:</td>
                                                        <td style="padding: 2.5px 0; color: #000000; font-weight: 600;">Col. ${v.colegio_electoral} — ${v.recinto_ubicacion}${v.posicion_recinto ? ' (' + v.posicion_recinto + ')' : ''}</td>
                                                    </tr>
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Dirección:</td>
                                                        <td style="padding: 2.5px 0; color: #000000; font-weight: 600;">${direccionStr}</td>
                                                    </tr>
                                                    <tr style="border-bottom: 1px solid #e2e8f0;">
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Registrado Por:</td>
                                                        <td style="padding: 2.5px 0; color: #334155; font-weight: 500;">${registradorStr}</td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding: 2.5px 0; color: #475569; font-weight: bold;">Fecha de Ingesta:</td>
                                                        <td style="padding: 2.5px 0; color: #334155; font-weight: 500;">${fechaIngesta}</td>
                                                    </tr>
                                                </table>
                                            </div>
                                        </td>
                                        
                                        <!-- Right QR Code Box -->
                                        <td style="width: 32%; vertical-align: middle; text-align: center;">
                                            <div style="background-color: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 10px 6px; text-align: center; box-sizing: border-box;">
                                                <div id="voucher-qr-target" style="background: #ffffff; border: 1px solid #cbd5e1; padding: 4px; border-radius: 6px; width: 100px; height: 100px; margin: 0 auto; display: block; box-shadow: 0 1px 4px rgba(0,0,0,0.08); box-sizing: border-box;"></div>
                                                <div style="font-size: 9px; color: #475569; margin-top: 6px; line-height: 1.3; text-align: center;">
                                                    <strong style="color: #0054A6; font-size: 10px; display: block; margin-bottom: 1px;">VALIDACIÓN EN TIEMPO REAL</strong>
                                                    <span style="color: #0f172a; font-weight: 700;">Norma PLAD-CERT-QR-01</span><br>
                                                    <span style="font-size: 8px; color: #64748b;">Escanee para certificar estatus sincronizado</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <!-- Footer disclaimer -->
                            <div style="text-align: center; margin-top: 6px; border-top: 1px solid #e2e8f0; padding-top: 4px; font-size: 9px; color: #64748b; line-height: 1.3;">
                                <p style="margin: 0 0 1px 0;">Plataforma Electoral ADELOG - Comando de Campaña Pastora Altagracia 2028. Validación y Auditoría Electoral ISO 54001 / ISO 27001.</p>
                                <p style="margin: 0;">Desarrollado para la administración logística de comandos de campañas en RD por Sypempresariales. Copyright © 2026-2028.</p>
                            </div>
                        </div>
                    `;
                    
                    // Render QR Code dynamically
                    setTimeout(() => {
                        const qrContainer = document.getElementById('voucher-qr-target');
                        if (qrContainer) {
                            qrContainer.innerHTML = '';
                            if (typeof QRCode !== 'undefined') {
                                try {
                                    new QRCode(qrContainer, {
                                        text: validUrl,
                                        width: 92,
                                        height: 92,
                                        colorDark: "#0b1320",
                                        colorLight: "#ffffff",
                                        correctLevel: QRCode.CorrectLevel.M
                                    });
                                    // Convert canvas to image for clean, non-colliding print rendering
                                    const canvas = qrContainer.querySelector('canvas');
                                    if (canvas) {
                                        const dataUrl = canvas.toDataURL('image/png');
                                        qrContainer.innerHTML = `<img src="${dataUrl}" style="width:92px;height:92px;display:block;margin:0 auto;" alt="QR">`;
                                    }
                                } catch (e) {
                                    qrContainer.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=92x92&data=${encodeURIComponent(validUrl)}" style="width:92px;height:92px;display:block;margin:0 auto;" alt="QR">`;
                                }
                            } else {
                                qrContainer.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=92x92&data=${encodeURIComponent(validUrl)}" style="width:92px;height:92px;display:block;margin:0 auto;" alt="QR">`;
                            }
                        }

                        const prevTitle = document.title;
                        document.title = combinedId;
                        setTimeout(() => {
                            window.print();
                            setTimeout(() => {
                                document.title = prevTitle;
                            }, 1000);
                        }, 250);
                    }, 50);
                }
            });
    }

    function renderPublicVoucher(v) {
        const resultDiv = document.getElementById('public-voucher-result');
        if (!resultDiv) return;
        
        resultDiv.innerHTML = `
            <div class="card" style="max-width: 500px; margin: 30px auto; border-color: var(--secondary);">
                <div style="text-align: center; margin-bottom: 20px;">
                    <span class="badge badge-success" style="font-size: 14px; padding: 6px 12px;"><i class="fa fa-check"></i> Registro Completado</span>
                    <h3 style="margin-top: 12px;">¡Gracias por su apoyo!</h3>
                    <p style="color: var(--text-muted); font-size: 14px;">Guarde su número de lista oficial</p>
                </div>
                <div style="background-color: rgba(255,255,255,0.03); padding: 16px; border-radius: 8px; font-size: 15px; margin-bottom: 20px;">
                    <p><strong>Número de Lista:</strong> <span style="font-size: 22px; color: var(--secondary); font-weight: bold;">${v.numero_lista}</span></p>
                    <p><strong>Cédula:</strong> ${v.cedula}</p>
                    <p><strong>Nombre:</strong> ${v.nombres} ${v.apellidos}</p>
                    <p><strong>Colegio Electoral:</strong> ${v.colegio_electoral}</p>
                </div>
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <button class="btn btn-primary btn-sm" onclick="printVoterVoucher(${v.id})"><i class="fa fa-print"></i> Imprimir Constancia</button>
                    <a href="index.html" class="btn btn-outline btn-sm"><i class="fa fa-redo"></i> Inscribir Otro</a>
                </div>
            </div>
        `;
    }

    // ─── CHAT EN VIVO DE WHATSAPP ───────────────────────────────────────────
    function loadChats() {
        fetch('../backend/api/chat.php?action=list_chats')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    renderChatsList(data.chats);
                }
            });
    }

    function renderChatsList(chats) {
        const listDiv = document.getElementById('chat-list-users');
        if (!listDiv) return;

        listDiv.innerHTML = '';
        if (chats.length === 0) {
            listDiv.innerHTML = `<div style="padding: 20px; text-align: center; color: var(--text-muted);">No hay chats activos.</div>`;
            return;
        }

        chats.forEach(c => {
            const div = document.createElement('div');
            div.className = `chat-user-item ${State.activeChatUser === c.telefono ? 'active' : ''}`;
            div.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #25D366; display: inline-block;" title="Activo"></span>
                        <strong>${c.nombre}</strong>
                    </div>
                    ${c.sin_leer > 0 ? `<span class="badge badge-danger">${c.sin_leer}</span>` : ''}
                </div>
                <div style="font-size: 13px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    ${c.direccion === 'saliente' ? 'Tú: ' : ''}${c.ultimo_mensaje}
                </div>
                <div style="font-size: 11px; color: rgba(255,255,255,0.3); text-align: right; margin-top: 4px;">
                    ${formatDate(c.fecha)}
                </div>
            `;
            
            div.addEventListener('click', () => {
                selectChat(c.telefono, c.nombre);
            });
            
            listDiv.appendChild(div);
        });
    }

    function selectChat(telefono, nombre) {
        State.activeChatUser = telefono;
        document.getElementById('chat-active-name').textContent = nombre + " (" + telefono + ")";
        document.getElementById('chat-active-window').style.display = 'flex';
        
        // Cargar mensajes
        loadChatMessages(telefono);
        loadChats(); // Actualizar indicadores
    }

    function loadChatMessages(telefono) {
        if (!telefono) return;
        
        fetch(`../backend/api/chat.php?action=get_messages&telefono=${telefono}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    const messagesDiv = document.getElementById('chat-messages-container');
                    messagesDiv.innerHTML = '';
                    
                    data.mensajes.forEach(m => {
                        const msgDiv = document.createElement('div');
                        msgDiv.className = `message-bubble ${m.direccion === 'entrante' ? 'received' : 'sent'}`;
                        
                        let messageText = m.mensaje;
                        const voucherRegex = /(https?:\/\/[^\s]+comprobante\.php\?id=(\d+))/i;
                        const match = messageText.match(voucherRegex);
                        
                        if (match) {
                            const fullUrl = match[1];
                            const voterId = match[2];
                            
                            msgDiv.innerHTML = `
                                <div style="margin-bottom: 10px;">¡Felicidades! Registro completado con éxito.</div>
                                
                                <!-- Voucher Interactive Card Widget -->
                                <div class="voucher-card-widget" style="background: rgba(255, 255, 255, 0.05); border: 2px solid var(--secondary); border-radius: 12px; overflow: hidden; max-width: 320px; margin: 12px 0; font-family: sans-serif; text-align: left; box-shadow: 0 10px 25px rgba(0,0,0,0.3);">
                                    <img src="../GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png" style="width: 100%; height: auto; display: block; border-bottom: 2px solid var(--secondary);" alt="Banner">
                                    <div style="padding: 12px; display: flex; flex-direction: column; gap: 4px;">
                                        <div style="font-size: 10px; color: var(--secondary); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Comprobante de Inscripción Oficial</div>
                                        <div style="font-size: 15px; font-weight: 700; color: #ffffff;">Votante Registrado #${voterId}</div>
                                        <div style="font-size: 12px; color: var(--text-muted);">Elige una opción para gestionar tu comprobante:</div>
                                    </div>
                                    <div style="background: rgba(0, 0, 0, 0.3); padding: 8px 12px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; justify-content: space-around; align-items: center; gap: 10px;">
                                        <button onclick="printVoterVoucher(${voterId})" title="Imprimir / Guardar PDF" style="background: var(--primary); border: none; width: 34px; height: 34px; border-radius: 50%; color: #ffffff; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
                                            <i class="fa fa-file-pdf"></i>
                                        </button>
                                        <button onclick="sendVoucherEmail(${voterId})" title="Enviar por Correo" style="background: #eab308; border: none; width: 34px; height: 34px; border-radius: 50%; color: #000000; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
                                            <i class="fa fa-envelope"></i>
                                        </button>
                                        <button onclick="shareVoucherWhatsApp(${voterId})" title="Compartir por WhatsApp" style="background: #25D366; border: none; width: 34px; height: 34px; border-radius: 50%; color: #ffffff; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
                                            <i class="fab fa-whatsapp"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div>Gracias por inscribirse y apoyar a la Diputada Pastora Altagracia. ¡Juntos ganamos!</div>
                                <div style="font-size: 10px; opacity: 0.5; text-align: right; margin-top: 4px;">${formatDate(m.fecha)}</div>
                            `;
                        } else {
                            msgDiv.innerHTML = `
                                <div>${m.mensaje}</div>
                                <div style="font-size: 10px; opacity: 0.5; text-align: right; margin-top: 4px;">${formatDate(m.fecha)}</div>
                            `;
                        }
                        messagesDiv.appendChild(msgDiv);
                    });
                    
                    // Auto scroll al fondo
                    messagesDiv.scrollTop = messagesDiv.scrollHeight;
                }
            });
    }

    function sendChatMessage() {
        const input = document.getElementById('chat-input');
        const text = input.value.trim();
        const telefono = State.activeChatUser;
        
        if (empty(text) || !telefono) return;
        
        fetch('../backend/api/chat.php?action=send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                telefono: telefono,
                nombre: document.getElementById('chat-active-name').textContent.split('(')[0].trim(),
                mensaje: text
            })
        })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    input.value = '';
                    loadChatMessages(telefono);
                    loadChats();
                }
            });
    }

    function startChatPolling() {
        stopChatPolling();
        State.chatsInterval = setInterval(() => {
            loadChats();
            if (State.activeChatUser) {
                loadChatMessages(State.activeChatUser);
            }
        }, 5000); // Polling cada 5 segundos para chat vivo
    }

    function stopChatPolling() {
        if (State.chatsInterval) {
            clearInterval(State.chatsInterval);
            State.chatsInterval = null;
        }
    }

    // ─── HELPDESK ───────────────────────────────────────────────────────────
    function loadTickets() {
        fetch('../backend/api/helpdesk.php?action=list')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    renderTicketsTable(data.incidencias);
                }
            });
    }

    function renderTicketsTable(incidencias) {
        const tbody = document.getElementById('helpdesk-tbody');
        if (!tbody) return;

        tbody.innerHTML = '';
        
        if (incidencias.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No hay incidencias reportadas.</td></tr>`;
            return;
        }

        incidencias.forEach(i => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>#${i.id}</strong></td>
                <td>${i.reportado_por} <br><small style="color: var(--text-muted);">${i.rol_reportante}</small></td>
                <td><span class="badge badge-primary">${i.tipo_incidencia}</span></td>
                <td>${i.descripcion}</td>
                <td>
                    <span class="badge ${i.estado === 'Pendiente' ? 'badge-danger' : (i.estado === 'En Proceso' ? 'badge-warning' : 'badge-success')}">
                        ${i.estado}
                    </span>
                </td>
                <td>${i.soporte_asignado}</td>
                <td>
                    ${i.estado !== 'Resuelto' ? `<button class="btn btn-outline btn-sm btn-resolve-ticket" data-id="${i.id}"><i class="fa fa-check"></i> Resolver</button>` : `<small style="color: var(--success);">${i.fecha_resolucion}</small>`}
                </td>
            `;
            
            const btnResolve = tr.querySelector('.btn-resolve-ticket');
            if (btnResolve) {
                btnResolve.addEventListener('click', () => updateTicketStatus(i.id, 'Resuelto'));
            }
            
            tbody.appendChild(tr);
        });
    }

    function createTicket() {
        const desc = document.getElementById('ticket-desc').value.trim();
        const tipo = document.getElementById('ticket-tipo').value;
        
        if (empty(desc)) {
            alert("Por favor, describa el inconveniente.");
            return;
        }
        
        fetch('../backend/api/helpdesk.php?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tipo_incidencia: tipo,
                descripcion: desc
            })
        })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    closeModal('ticket-modal');
                    loadTickets();
                    showNotification("✓ Incidencia reportada exitosamente. Soporte TI ha sido alertado.", "success");
                    document.getElementById('ticket-form').reset();
                } else {
                    alert(data.mensaje);
                }
            });
    }

    function updateTicketStatus(id, estado) {
        fetch('../backend/api/helpdesk.php?action=update_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, estado: estado })
        })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    loadTickets();
                    loadDashboardData();
                    showNotification("✓ Incidencia resuelta.", "success");
                }
            });
    }

    // ─── DOCUMENTOS INFORMATIVOS ───────────────────────────────────────────
    function loadDocs() {
        fetch('../backend/api/docs.php')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    renderDocs(data.documentos);
                }
            });
    }

    function renderDocs(documentos) {
        const container = document.getElementById('docs-container');
        if (!container) return;

        container.innerHTML = '';
        State.documentos = documentos; // Guardar en el estado para poder imprimirlos luego
        
        documentos.forEach(doc => {
            const card = document.createElement('div');
            card.className = 'card';
            card.style.marginBottom = '20px';
            
            let downloadWidget = '';
            if (doc.categoria === 'Descargas') {
                downloadWidget = `
                    <div class="download-widget-box" style="margin-top: 20px; padding: 15px; border-top: 1px solid var(--border-color); background-color: rgba(227, 161, 19, 0.05); border-radius: 8px;">
                        <h5 style="margin-bottom: 12px; color: var(--primary-light);"><i class="fa fa-download"></i> Enlaces de Descargas Directas:</h5>
                        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                            <a href="assets/descargas/formulario_captacion.html" target="_blank" class="btn btn-primary btn-sm" style="display: inline-flex; align-items: center; gap: 8px;"><i class="fa fa-file-pdf"></i> Imprimir Formulario Físico (HTML/PDF)</a>
                            <a href="assets/descargas/manual_promotor.html" target="_blank" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 8px;"><i class="fa fa-book"></i> Instructivo de Capacitación (HTML/PDF)</a>
                            <a href="assets/descargas/manual_plataforma.zip" download class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 8px;"><i class="fa fa-file-archive"></i> Descargar Recursos (ZIP)</a>
                        </div>
                    </div>
                `;
            }

            card.innerHTML = `
                <div style="display: flex; gap: 16px; align-items: flex-start; justify-content: space-between; flex-wrap: wrap;">
                    <div style="display: flex; gap: 16px; align-items: flex-start; flex: 1; min-width: 280px;">
                        <div style="background-color: rgba(0, 84, 166, 0.1); color: var(--primary-light); padding: 12px; border-radius: 8px; font-size: 20px;">
                            <i class="fa ${doc.icon}"></i>
                        </div>
                        <div style="flex: 1;">
                            <h4 style="margin-bottom: 8px;">${doc.titulo}</h4>
                            <div style="font-size: 12px; color: var(--secondary); font-weight: 600; text-transform: uppercase; margin-bottom: 12px;">${doc.categoria}</div>
                            <p style="font-size: 14px; color: var(--text-muted); line-height: 1.6; white-space: pre-line;">${doc.contenido}</p>
                            ${downloadWidget}
                        </div>
                    </div>
                    <button class="btn btn-outline btn-sm btn-print-doc" data-id="${doc.id}" style="align-self: flex-start; white-space: nowrap;"><i class="fa fa-print"></i> Imprimir Descripción</button>
                </div>
            `;
            card.querySelector('.btn-print-doc').addEventListener('click', () => printDocumentDescription(doc.id));
            container.appendChild(card);
        });
    }

    // ─── ADMINISTRACIÓN DE PERMISOS Y CAMPAÑAS ──────────────────────────────
    function loadUsersList() {
        fetch('../backend/api/permissions.php?action=list')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    renderUsersTable(data.usuarios);
                }
            });
    }

    function renderUsersTable(usuarios) {
        const tbody = document.getElementById('users-tbody');
        if (!tbody) return;

        tbody.innerHTML = '';
        
        const isAdmin = State.user.role === 'Administrador';
        
        usuarios.forEach(u => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${u.nombre} <br><small style="color: var(--text-muted);">${u.username}</small></td>
                <td>${u.telefono || '<span style="color: var(--text-muted);">N/A</span>'}</td>
                <td><span class="badge ${u.role === 'Administrador' ? 'badge-danger' : 'badge-primary'}">${u.role}</span></td>
                <td><span class="badge badge-info" style="font-weight:600;">${u.total_inscritos} inscritos</span></td>
                <td>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <label><input type="checkbox" class="perm-check" data-user="${u.id}" data-perm="can_create" ${u.permisos.can_create == 1 ? 'checked' : ''} ${(!isAdmin || u.role === 'Administrador') ? 'disabled' : ''}> Crear</label>
                        <label><input type="checkbox" class="perm-check" data-user="${u.id}" data-perm="can_edit" ${u.permisos.can_edit == 1 ? 'checked' : ''} ${(!isAdmin || u.role === 'Administrador') ? 'disabled' : ''}> Corregir</label>
                        <label><input type="checkbox" class="perm-check" data-user="${u.id}" data-perm="can_view" ${u.permisos.can_view == 1 ? 'checked' : ''} ${(!isAdmin || u.role === 'Administrador') ? 'disabled' : ''}> Consultar</label>
                        <label><input type="checkbox" class="perm-check" data-user="${u.id}" data-perm="can_print" ${u.permisos.can_print == 1 ? 'checked' : ''} ${(!isAdmin || u.role === 'Administrador') ? 'disabled' : ''}> Imprimir</label>
                        <label><input type="checkbox" class="perm-check" data-user="${u.id}" data-perm="can_send" ${u.permisos.can_send == 1 ? 'checked' : ''} ${(!isAdmin || u.role === 'Administrador') ? 'disabled' : ''}> Enviar</label>
                        <label><input type="checkbox" class="perm-check" data-user="${u.id}" data-perm="can_view_historical" ${u.permisos.can_view_historical == 1 ? 'checked' : ''} ${(!isAdmin || u.role === 'Administrador') ? 'disabled' : ''}> Ver Histórico</label>
                    </div>
                </td>
                <td>
                    <span class="badge ${u.estado == 1 ? 'badge-success' : 'badge-danger'}">${u.estado == 1 ? 'Activo' : 'Inactivo'}</span>
                </td>
                <td>
                    ${(isAdmin && u.id !== State.user.id) ? `<button class="btn btn-outline btn-sm btn-toggle-status" data-id="${u.id}"><i class="fa fa-power-off"></i> Alternar</button>` : '<span style="color: var(--text-muted); font-size:12px;">Sin acciones</span>'}
                </td>
            `;
            
            // Eventos checkboxes de permisos
            tr.querySelectorAll('.perm-check').forEach(chk => {
                chk.addEventListener('change', () => {
                    saveUserPermissions(u.id);
                });
            });

            // Evento desactivar usuario
            const btnToggle = tr.querySelector('.btn-toggle-status');
            if (btnToggle) {
                btnToggle.addEventListener('click', () => toggleUserStatus(u.id));
            }
            
            tbody.appendChild(tr);
        });
    }

    function saveUserPermissions(userId) {
        const checks = document.querySelectorAll(`.perm-check[data-user="${userId}"]`);
        const payload = { usuario_id: userId };
        
        checks.forEach(chk => {
            payload[chk.getAttribute('data-perm')] = chk.checked ? 1 : 0;
        });

        fetch('../backend/api/permissions.php?action=update_permissions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(res => res.json())
            .then(data => {
                if (!data.exito) alert(data.mensaje);
            });
    }

    function toggleUserStatus(userId) {
        fetch('../backend/api/permissions.php?action=toggle_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ usuario_id: userId })
        })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    loadUsersList();
                    showNotification("✓ Estado de usuario modificado.", "success");
                }
            });
    }

    function createUser() {
        const username = document.getElementById('user-username').value.trim();
        const pass = document.getElementById('user-pass').value;
        const nombre = document.getElementById('user-nombre').value.trim();
        const role = document.getElementById('user-role').value;

        if (empty(username) || empty(pass) || empty(nombre)) {
            alert("Rellene todos los campos de usuario.");
            return;
        }

        fetch('../backend/api/permissions.php?action=create_user', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                username: username,
                password: pass,
                nombre: nombre,
                role: role
            })
        })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    loadUsersList();
                    document.getElementById('user-form').reset();
                    showNotification("✓ Usuario creado.", "success");
                } else {
                    alert(data.mensaje);
                }
            });
    }

    function loadCampaignsList() {
        fetch('../backend/api/campaigns.php?action=list')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    renderCampaignsTable(data.campanas);
                }
            });
    }

    function renderCampaignsTable(campanas) {
        const tbody = document.getElementById('campaigns-tbody');
        if (!tbody) return;

        tbody.innerHTML = '';
        
        const isAdmin = State.user.role === 'Administrador';
        
        if (campanas.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted);">No hay campañas creadas.</td></tr>`;
            return;
        }

        campanas.forEach(c => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><strong>${c.nombre_campana}</strong> <br><small style="color: var(--secondary);">${c.codigo_campana}</small></td>
                <td>${c.coordinador}</td>
                <td><strong>${c.clics}</strong> clics</td>
                <td><span class="badge badge-success">${c.inscritos} registrados</span></td>
                <td>
                    <button class="btn btn-outline btn-sm btn-view-qr" data-qr="${c.qr_image_url}" data-link="${c.enlace_digital}"><i class="fa fa-qrcode"></i> QR</button>
                </td>
                <td>
                    <span class="badge ${c.activo == 1 ? 'badge-success' : 'badge-danger'}">${c.activo == 1 ? 'Activo' : 'Inactivo'}</span>
                </td>
                <td>
                    ${isAdmin ? `
                        <button class="btn btn-outline btn-sm btn-toggle-camp" title="Alternar Estado" data-id="${c.id}"><i class="fa ${c.activo == 1 ? 'fa-eye-slash' : 'fa-eye'}"></i></button>
                        <button class="btn btn-outline btn-sm btn-delete-camp" title="Eliminar" data-id="${c.id}" style="color: var(--primary-light);"><i class="fa fa-trash"></i></button>
                    ` : '<span style="color: var(--text-muted); font-size:12px;">Sin acciones</span>'}
                </td>
            `;
            
            tr.querySelector('.btn-view-qr').addEventListener('click', () => {
                document.getElementById('qr-modal-image').src = c.qr_image_url;
                document.getElementById('qr-modal-link').value = c.enlace_digital;
                openModal('qr-display-modal');
            });
            
            if (isAdmin) {
                tr.querySelector('.btn-toggle-camp').addEventListener('click', () => toggleCampaignStatus(c.id));
                tr.querySelector('.btn-delete-camp').addEventListener('click', () => deleteCampaign(c.id));
            }
            
            tbody.appendChild(tr);
        });
    }

    function toggleCampaignStatus(id) {
        fetch('../backend/api/campaigns.php?action=toggle_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    loadCampaignsList();
                    showNotification("✓ Estado de campaña actualizado.", "success");
                } else {
                    alert(data.mensaje);
                }
            });
    }

    function deleteCampaign(id) {
        showCustomConfirm("¿Está seguro de que desea eliminar permanentemente esta campaña QR?").then(confirmed => {
            if (!confirmed) return;
            fetch('../backend/api/campaigns.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.exito) {
                        loadCampaignsList();
                        showNotification("✓ Campaña eliminada exitosamente.", "success");
                    } else {
                        alert(data.mensaje);
                    }
                });
        });
    }

    function createCampaign() {
        const nombre = document.getElementById('qr-campana-nombre').value.trim();
        const coord = document.getElementById('qr-campana-coordinador').value.trim();

        if (empty(nombre) || empty(coord)) {
            alert("Rellene nombre y coordinador para la campaña.");
            return;
        }

        fetch('../backend/api/campaigns.php?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                nombre_campana: nombre,
                coordinador: coord
            })
        })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    loadCampaignsList();
                    document.getElementById('qr-form').reset();
                    showNotification("✓ Campaña QR creada.", "success");
                } else {
                    alert(data.mensaje);
                }
            });
    }

    // ─── HELPERS GENERALES ──────────────────────────────────────────────────
    window.openModal = function(id) {
        const modal = document.getElementById(id);
        if (modal) modal.style.display = 'flex';
    };

    window.closeModal = function(id) {
        const modal = document.getElementById(id);
        if (modal) modal.style.display = 'none';
        
        // Detener cámara si se cierra modal del votante
        if (id === 'voter-modal' && streamRef) {
            stopWebcam(false);
        }
    };

    function showNotification(msg, type = 'success', isPublic = false) {
        // Crear alerta tipo toast en esquina
        const toast = document.createElement('div');
        toast.className = `badge badge-${type}`;
        toast.style.position = 'fixed';
        toast.style.bottom = '20px';
        toast.style.right = '20px';
        toast.style.padding = '16px 24px';
        toast.style.fontSize = '15px';
        toast.style.borderRadius = '10px';
        toast.style.zIndex = '9999';
        toast.style.boxShadow = '0 10px 20px rgba(0,0,0,0.5)';
        toast.style.animation = 'slideIn 0.3s ease';
        toast.innerHTML = msg;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.5s ease';
            setTimeout(() => toast.remove(), 500);
        }, 5000);
    }

    // CSS para el toast inyectado
    const style = document.createElement('style');
    style.innerHTML = `
        @keyframes slideIn {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);

    function empty(val) {
        return val === null || val === undefined || val === '';
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr.replace(/-/g, '/'));
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    function poblarRolesSelects() {
        fetch('../backend/api/perfiles.php?action=listar')
            .then(res => res.json())
            .then(data => {
                if (data.exito && Array.isArray(data.perfiles)) {
                    const selectColab = document.getElementById('colab-role');
                    const selectUser = document.getElementById('user-role');
                    const selectVoterNivel = document.getElementById('voter-nivel-estructura');

                    let html = '';
                    data.perfiles.forEach(p => {
                        html += `<option value="${p.nombre}">${p.nombre} (Nivel ${p.nivel_jerarquico})</option>`;
                    });

                    if (selectColab && html) {
                        const cur = selectColab.value;
                        selectColab.innerHTML = html;
                        if (cur) selectColab.value = cur;
                    }
                    if (selectUser && html) {
                        const cur = selectUser.value;
                        selectUser.innerHTML = html;
                        if (cur) selectUser.value = cur;
                    }
                    if (selectVoterNivel && html) {
                        const cur = selectVoterNivel.value;
                        selectVoterNivel.innerHTML = html;
                        if (cur) selectVoterNivel.value = cur;
                    }
                }
            })
            .catch(() => {});
    }

    function saveCollaborator() {
        const nombre = document.getElementById('colab-nombre').value.trim();
        const telefono = document.getElementById('colab-telefono').value.trim();
        const username = document.getElementById('colab-username').value.trim();
        const role = document.getElementById('colab-role').value;
        const password = document.getElementById('colab-password').value.trim();

        if (empty(nombre) || empty(telefono) || empty(username) || empty(password)) {
            alert("Por favor rellene todos los campos obligatorios.");
            return;
        }

        fetch('../backend/api/permissions.php?action=create_user', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                nombre: nombre,
                telefono: telefono,
                username: username,
                role: role,
                password: password
            })
        })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    closeModal('collaborator-modal');
                    showNotification("✓ Colaborador registrado exitosamente.", "success");
                    loadUsersList();
                } else {
                    alert(data.mensaje);
                }
            })
            .catch(() => alert("Error al registrar colaborador."));
    }

    function fetchCollaboratorsForChat() {
        const select = document.getElementById('chat-select-colab');
        if (!select) return;
        
        select.innerHTML = '<option value="">-- Seleccionar o escribir manual --</option>';
        
        fetch('../backend/api/permissions.php?action=list')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    data.usuarios.forEach(u => {
                        if (u.telefono) {
                            const option = document.createElement('option');
                            option.value = u.telefono;
                            option.setAttribute('data-nombre', u.nombre);
                            option.textContent = `${u.nombre} (${u.role} - ${u.telefono})`;
                            select.appendChild(option);
                        }
                    });
                }
            });
    }

    function fillChatModalFields() {
        const select = document.getElementById('chat-select-colab');
        const phoneInput = document.getElementById('chat-manual-telefono');
        const nameInput = document.getElementById('chat-manual-nombre');
        
        if (select && phoneInput && nameInput) {
            const selectedOpt = select.options[select.selectedIndex];
            if (selectedOpt && selectedOpt.value !== '') {
                phoneInput.value = selectedOpt.value;
                nameInput.value = selectedOpt.getAttribute('data-nombre') || '';
                phoneInput.disabled = true;
                nameInput.disabled = true;
            } else {
                phoneInput.value = '';
                nameInput.value = '';
                phoneInput.disabled = false;
                nameInput.disabled = false;
            }
        }
    }

    function triggerNewChat() {
        const phoneInput = document.getElementById('chat-manual-telefono');
        const nameInput = document.getElementById('chat-manual-nombre');
        const phone = phoneInput.value.trim();
        const nombre = nameInput.value.trim();

        if (empty(phone) || empty(nombre)) {
            alert("El nombre y teléfono son obligatorios para iniciar el chat.");
            return;
        }

        closeModal('start-chat-modal');
        selectChat(phone, nombre);
        showNotification("✓ Chat iniciado con " + nombre, "success");
    }

    function printDocumentDescription(docId) {
        if (!State.documentos) return;
        const doc = State.documentos.find(d => d.id === docId);
        if (!doc) return;

        let printArea = document.getElementById('print-voucher-area');
        if (!printArea) {
            const div = document.createElement('div');
            div.id = 'print-voucher-area';
            document.body.appendChild(div);
            printArea = div;
        }
        printArea.className = 'print-hidden-container';

        printArea.innerHTML = `
            <div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; background: #fff; color: #000;">
                <div style="text-align: center; margin-bottom: 25px;">
                    <img src="../GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png" alt="Pastora Altagracia" style="width: 100%; height: auto; display: block; border-bottom: 4px solid #E3A113; border-radius: 8px;">
                </div>
                
                <div style="border: 2px solid #e2e8f0; padding: 25px; border-radius: 12px; background: #f8fafc; margin-bottom: 20px;">
                    <div style="font-size: 13px; color: #0054A6; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.5px;">${doc.categoria}</div>
                    <h2 style="font-size: 24px; color: #0f172a; margin: 0 0 15px 0; font-family: Outfit, Arial, sans-serif;">${doc.titulo}</h2>
                    <hr style="border: none; border-top: 1px solid #cbd5e1; margin-bottom: 20px;">
                    <p style="font-size: 15px; color: #334155; line-height: 1.8; white-space: pre-line;">${doc.contenido}</p>
                </div>
                
                <div style="text-align: center; font-size: 12px; color: #94a3b8; margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 15px;">
                    Plataforma Oficial Digital Pastora Altagracia © 2028 - Santo Domingo Circ. 3
                </div>
            </div>
        `;

        window.print();
    }

    function printAllDocuments() {
        if (!State.documentos || State.documentos.length === 0) {
            alert("No hay documentos disponibles para imprimir.");
            return;
        }

        let printArea = document.getElementById('print-voucher-area');
        if (!printArea) {
            const div = document.createElement('div');
            div.id = 'print-voucher-area';
            document.body.appendChild(div);
            printArea = div;
        }
        printArea.className = 'print-hidden-container';

        let htmlContent = `
            <div style="font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; background: #fff; color: #000;">
                <div style="text-align: center; margin-bottom: 25px;">
                    <img src="../GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png" alt="Pastora Altagracia" style="width: 100%; height: auto; display: block; border-bottom: 4px solid #E3A113; border-radius: 8px;">
                </div>
                <h1 style="text-align: center; font-size: 26px; color: #0054A6; margin-bottom: 30px; font-family: Outfit, Arial, sans-serif;">DOCUMENTACIÓN COMPLETA DE LA PLATAFORMA</h1>
        `;

        State.documentos.forEach((doc, idx) => {
            htmlContent += `
                <div style="border: 2px solid #e2e8f0; padding: 25px; border-radius: 12px; background: #f8fafc; margin-bottom: 20px; page-break-inside: avoid;">
                    <div style="font-size: 13px; color: #0054A6; font-weight: 700; text-transform: uppercase; margin-bottom: 8px;">${doc.categoria}</div>
                    <h2 style="font-size: 20px; color: #0f172a; margin: 0 0 15px 0;">${doc.titulo}</h2>
                    <hr style="border: none; border-top: 1px solid #cbd5e1; margin-bottom: 15px;">
                    <p style="font-size: 14px; color: #334155; line-height: 1.7; white-space: pre-line;">${doc.contenido}</p>
                </div>
            `;
            if (idx < State.documentos.length - 1) {
                htmlContent += `<div style="page-break-after: always;"></div>`;
            }
        });

        htmlContent += `
                <div style="text-align: center; font-size: 12px; color: #94a3b8; margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 15px;">
                    Plataforma Oficial Digital Pastora Altagracia © 2028 - Santo Domingo Circ. 3
                </div>
            </div>
        `;

        printArea.innerHTML = htmlContent;
        window.print();
    }

    function consultarEstatus2024() {
        const input = document.getElementById('input-query-2024');
        const term = input.value.trim();
        
        if (!term) {
            alert("Por favor, ingrese un término de búsqueda (Cédula con o sin guiones, o Nombre).");
            return;
        }

        const resultsDiv = document.getElementById('query-2024-results');
        const alertDiv = document.getElementById('query-2024-status-alert');
        const tbody = document.getElementById('query-2024-tbody');

        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px; color: var(--text-muted);"><i class="fa fa-spinner fa-spin"></i> Consultando en Padrón Maestro del Partido y Padrón de la Candidata...</td></tr>';
        resultsDiv.style.display = 'block';

        fetch(`../backend/api/voters.php?action=query_2024&search=${encodeURIComponent(term)}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    tbody.innerHTML = '';
                    
                    if (data.votantes && data.votantes.length > 0) {
                        const total = data.votantes.length;
                        
                        // Si es búsqueda exacta (1 registro) emitir diagnóstico específico
                        if (total === 1) {
                            const v = data.votantes[0];
                            if (v.estado_diagnostico === 'COMPROMETIDO') {
                                alertDiv.className = 'alert alert-success';
                                alertDiv.style.backgroundColor = 'rgba(16, 185, 129, 0.15)';
                                alertDiv.style.border = '1px solid #10b981';
                                alertDiv.style.color = '#10b981';
                                alertDiv.innerHTML = `<i class="fa fa-check-circle"></i> <strong>✓ ESTADO 1: MILITANTE COMPROMETIDO</strong> — Elector figura en el Padrón del Partido (PRM) y está registrado en el Padrón Activo de la Candidata Pastora Altagracia (2028). Etiqueta: <strong>${v.tipo_elector_label || 'Nuevo Elector'}</strong>. Folio: <strong>${v.codigo_comprobante || 'N/A'}</strong>.`;
                            } else if (v.estado_diagnostico === 'NO_CAPTADO') {
                                alertDiv.className = 'alert alert-warning';
                                alertDiv.style.backgroundColor = 'rgba(227, 161, 19, 0.15)';
                                alertDiv.style.border = '1px solid #E3A113';
                                alertDiv.style.color = '#E3A113';
                                alertDiv.innerHTML = `<i class="fa fa-exclamation-circle"></i> <strong>⚠️ ESTADO 2: OBJETIVO ESTRATÉGICO (NO CAPTADO)</strong> — Elector figura en el Padrón del Partido (${v.militancia_partido_label}), pero <strong>AÚN NO ESTÁ REGISTRADO</strong> en el padrón de la candidata. Puede afiliarlo inmediatamente haciendo clic en <strong>"+ Registrar como Nuevo Elector"</strong>.`;
                            } else if (v.estado_diagnostico === 'EXTERNO') {
                                const demText = v.circunscripcion_elector ? ` (Votante en ${v.circunscripcion_elector})` : '';
                                alertDiv.className = 'alert alert-info';
                                alertDiv.style.backgroundColor = 'rgba(0, 84, 166, 0.15)';
                                alertDiv.style.border = '1px solid #0054A6';
                                alertDiv.style.color = '#0054A6';
                                alertDiv.innerHTML = `<i class="fa fa-info-circle"></i> <strong>ℹ️ ESTADO 3: SIMPATIZANTE EXTERNO / NUEVO ELECTOR INDEPENDIENTE</strong> — Elector registrado en el Padrón de la Candidata${demText}. Etiqueta: <strong>${v.tipo_elector_label || 'Nuevo Elector'}</strong>. Folio: <strong>${v.codigo_comprobante || 'N/A'}</strong>.`;
                            } else {
                                alertDiv.className = 'alert alert-secondary';
                                alertDiv.style.backgroundColor = 'rgba(148, 163, 184, 0.15)';
                                alertDiv.style.border = '1px solid #94a3b8';
                                alertDiv.style.color = '#94a3b8';
                                alertDiv.innerHTML = `<i class="fa fa-search"></i> Coincidencia encontrada en la consulta.`;
                            }
                        } else {
                            alertDiv.className = 'alert alert-info';
                            alertDiv.style.backgroundColor = 'rgba(0, 84, 166, 0.15)';
                            alertDiv.style.border = '1px solid #0054A6';
                            alertDiv.style.color = '#0054A6';
                            alertDiv.innerHTML = `<i class="fa fa-list-check"></i> Se encontraron <strong>${total}</strong> electores en el cruce de padrones. Revise el estatus individual en cada fila:`;
                        }
                        
                        data.votantes.forEach(v => {
                            const tr = document.createElement('tr');
                            
                            // Badge Padrón Partido
                            let badgePartido = '';
                            if (v.en_padron_partido) {
                                badgePartido = `<span class="badge" style="background: rgba(0, 84, 166, 0.15); color: #0054A6; border: 1px solid #0054A6; font-size: 11px; padding: 4px 8px;"><i class="fa fa-check-circle"></i> ${v.militancia_partido_label || 'Padrón PRM'}</span>`;
                            } else if (v.es_fuera_circ3) {
                                badgePartido = `<span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid #f59e0b; font-size: 11px; padding: 4px 8px;"><i class="fa fa-map-marker-alt"></i> Elector Circ. 1 (Externo)</span>`;
                            } else {
                                badgePartido = `<span class="badge" style="background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid #94a3b8; font-size: 11px; padding: 4px 8px;"><i class="fa fa-minus-circle"></i> No figura en Padrón Circ. 3</span>`;
                            }
                            
                            // Badge Padrón Candidata
                            let badgeCandidata = '';
                            if (v.en_padron_candidata) {
                                const tagStr = v.tipo_elector_label || 'Nuevo Elector';
                                const listaStr = v.numero_lista ? ` #${v.numero_lista}` : '';
                                badgeCandidata = `<span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid #10b981; font-size: 11px; padding: 4px 8px;"><i class="fa fa-check-circle"></i> ${tagStr}${listaStr}</span>`;
                            } else {
                                badgeCandidata = `<span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; font-size: 11px; padding: 4px 8px;"><i class="fa fa-times-circle"></i> No Registrado</span>`;
                            }
                            
                            // Acciones
                            let accionesHtml = '';
                            if (v.en_padron_candidata && v.id) {
                                accionesHtml = `
                                    <button class="btn btn-primary btn-sm btn-print-2024" style="padding: 4px 8px; font-size: 11px; margin-right: 6px;" onclick="printVoterVoucher(${v.id})"><i class="fa fa-print"></i> Constancia (PDF)</button>
                                    <button class="btn btn-outline btn-sm" style="padding: 4px 8px; font-size: 11px;" onclick="exportSingleVoterExcel(${v.id})"><i class="fa fa-download"></i> Excel</button>
                                `;
                            } else {
                                const vEncoded = encodeURIComponent(JSON.stringify(v));
                                accionesHtml = `
                                    <button class="btn btn-secondary btn-sm" style="padding: 4px 10px; font-size: 11px; white-space: nowrap;" onclick="prefillVoterModalFromQuery('${vEncoded}')"><i class="fa fa-user-plus"></i> + Registrar como Nuevo Elector</button>
                                `;
                            }
                            
                            tr.innerHTML = `
                                <td style="padding: 10px; border-bottom: 1px solid var(--border-color); font-family: monospace; font-weight: 600;">${v.cedula}</td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--border-color); text-transform: uppercase; font-weight: 600;">
                                    ${v.nombre_completo || (v.nombres + ' ' + v.apellidos)}
                                    ${v.es_militante_lider ? ' <span class="badge" style="background: rgba(227, 161, 19, 0.2); color: #E3A113; font-size: 10px; padding: 2px 6px;">ML</span>' : ''}
                                </td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--border-color);">
                                    <span style="font-weight: 600; color: #0054A6;">Colegio: ${v.colegio_electoral || 'N/A'}</span>
                                    <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase;">${v.recinto_ubicacion || ''}</div>
                                </td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--border-color); font-size: 12px; text-transform: uppercase;">
                                    <div>${v.sector || ''} ${v.municipio ? '(' + v.municipio + ')' : ''}</div>
                                    ${v.es_fuera_circ3 ? '<span class="badge" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid #f59e0b; font-size: 10px; padding: 2px 6px; margin-top: 2px; display: inline-block;">' + (v.circunscripcion_elector || 'Circ. 1') + '</span>' : ''}
                                </td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--border-color);">${badgePartido}</td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--border-color);">${badgeCandidata}</td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--border-color); text-align: right;">${accionesHtml}</td>
                            `;
                            tbody.appendChild(tr);
                        });
                    } else {
                        alertDiv.className = 'alert alert-danger';
                        alertDiv.style.backgroundColor = 'rgba(239, 68, 68, 0.15)';
                        alertDiv.style.border = '1px solid #ef4444';
                        alertDiv.style.color = '#ef4444';
                        alertDiv.innerHTML = `<i class="fa fa-times-circle"></i> <strong>✗ ESTADO 4: NO LOCALIZADO</strong> — El elector consultado no se encuentra en el Padrón Maestro del Partido (Circ. 3) ni en el Padrón Activo de la Candidata.`;
                        tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 20px; color: var(--text-muted);">No se encontraron coincidencias en ninguna de las dos bases de datos para "${term}".</td></tr>`;
                    }
                } else {
                    alert(data.mensaje || "Error al realizar la consulta.");
                }
            })
            .catch(() => alert("Error de red al consultar el padrón."));
    }

    function prefillVoterModalFromQuery(encodedJson) {
        try {
            const v = JSON.parse(decodeURIComponent(encodedJson));
            document.getElementById('voter-form-title').textContent = "Nueva Inscripción: Nuevo Elector (Desde Padrón Partido)";
            document.getElementById('voter-id').value = "";
            document.getElementById('voter-form').reset();
            
            document.getElementById('cedula').value = v.cedula || '';
            document.getElementById('nombres').value = v.nombres || '';
            document.getElementById('apellidos').value = v.apellidos || '';
            document.getElementById('colegio_electoral').value = v.colegio_electoral || '';
            document.getElementById('recinto_ubicacion').value = v.recinto_ubicacion || '';
            document.getElementById('sector').value = v.sector || '';
            document.getElementById('municipio').value = v.municipio || 'SANTO DOMINGO ESTE';
            if (v.telefono) {
                document.getElementById('telefono').value = v.telefono;
            }
            
            openModal('voter-modal');
        } catch (e) {
            console.error("Error parsing voter data:", e);
        }
    }

    function exportSingleVoterExcel(id) {
        fetch(`../backend/api/voters.php?action=detail&id=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    const v = data.votante;
                    let csvContent = "\uFEFF"; // BOM UTF-8
                    csvContent += "Folio Oficial,Número Lista,Cédula,Nombres,Apellidos,Etiqueta Elector,Estatus Partido (PRM),Estatus Candidata,Colegio Electoral,Recinto,Posición Recinto,Sector,Municipio,Teléfono,Email,Coordinador,Registrado Por,Fecha Ingesta\n";
                    csvContent += `"${v.codigo_comprobante || ('PAD2832-' + v.numero_lista + '-' + v.cedula)}","${v.numero_lista}","${v.cedula}","${v.nombres}","${v.apellidos}","${v.tipo_elector || 'Nuevo Elector'}","${v.militancia_partido_label || 'Padrón Maestro'}","Padrón Activo Pastora Altagracia (2028)","${v.colegio_electoral}","${v.recinto_ubicacion}","${v.posicion_recinto || ''}","${v.sector}","${v.municipio}","${v.telefono}","${v.email || ''}","${v.coordinador}","${(v.registrado_por_nombre || 'Usuario') + ' (' + (v.perfil_registrador || 'Operativo') + ')'}","${v.fecha_registro || ''}"\n`;
                    
                    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement("a");
                    const url = URL.createObjectURL(blob);
                    link.setAttribute("href", url);
                    link.setAttribute("download", `PAD2832_elector_${v.cedula}.csv`);
                    link.style.visibility = 'hidden';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } else {
                    alert("Error al cargar datos del elector.");
                }
            });
    }

    function sendExternalWhatsApp(hasMsg) {
        const phoneInput = document.getElementById('ext-chat-phone');
        const msgInput = document.getElementById('ext-chat-msg');
        
        let phone = phoneInput ? phoneInput.value.replace(/\D/g, '') : '';
        const msg = msgInput ? msgInput.value.trim() : '';

        if (hasMsg) {
            if (empty(phone)) {
                alert("Por favor, ingrese el número de teléfono del destinatario.");
                return;
            }
            // Prepend dominican country code (1) if length is 10 digits
            if (phone.length === 10) {
                phone = '1' + phone;
            }
            const url = `https://web.whatsapp.com/send?phone=${phone}&text=${encodeURIComponent(msg)}`;
            window.open(url, '_blank');
        } else {
            window.open('https://web.whatsapp.com/', '_blank');
        }
    }

    function simulateIncomingWhatsApp() {
        const phoneVal = document.getElementById('sim-chat-phone').value.trim();
        const nameVal = document.getElementById('sim-chat-name').value.trim();
        const msgVal = document.getElementById('sim-chat-msg').value.trim();

        if (empty(phoneVal) || empty(nameVal) || empty(msgVal)) {
            alert("Todos los campos del simulador (teléfono, nombre y mensaje) son requeridos.");
            return;
        }

        fetch('../backend/api/whatsapp_webhook.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                telefono: phoneVal,
                nombre: nameVal,
                mensaje: msgVal
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                showNotification("✓ Mensaje simulado recibido por el Webhook.", "success");
                document.getElementById('sim-chat-msg').value = '';
                
                // Recargar interfaz de chats
                loadChats();
                
                // Si la conversación activa es la del número simulado, recargar mensajes
                if (State.activeChatUser === phoneVal) {
                    loadChatMessages(phoneVal);
                } else if (!State.activeChatUser) {
                    // Si no hay chat activo, abrirlo para verificar al instante
                    selectChat(phoneVal, nameVal);
                }
            } else {
                alert(data.mensaje);
            }
        })
        .catch(() => alert("Error al conectar con el Webhook de simulación."));
    }

    function clearActiveChat() {
        if (!State.activeChatUser) {
            alert("No hay ningún chat activo seleccionado.");
            return;
        }
        
        showCustomConfirm(`¿Está seguro de que desea limpiar todo el historial de chat y reiniciar la sesión del bot para el número ${State.activeChatUser}?`).then(confirmed => {
            if (!confirmed) return;
            fetch('../backend/api/chat.php?action=clear_chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ telefono: State.activeChatUser })
            })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    showNotification("✓ Conversación y sesión del bot limpiadas con éxito.", "success");
                    loadChats();
                    loadChatMessages(State.activeChatUser);
                } else {
                    alert(data.mensaje);
                }
            })
            .catch(() => alert("Error de red al limpiar la conversación."));
        });
    }

    function sendVoucherEmail(id) {
        const email = prompt("Ingrese el correo electrónico del elector para enviarle el comprobante:");
        if (!email) return;
        
        fetch(`../backend/api/voters.php?action=email_voucher&id=${id}&email=${encodeURIComponent(email)}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    showNotification("✓ El comprobante de inscripción oficial ha sido enviado por correo con éxito.", "success");
                } else {
                    alert(data.mensaje);
                }
            })
            .catch(() => alert("Error de red al enviar el comprobante por correo."));
    }

    function shareVoucherWhatsApp(id) {
        const currentPath = window.location.pathname;
        const folder = currentPath.split('/')[1];
        const url = `${window.location.protocol}//${window.location.host}/${folder}/comprobante.php?id=${id}`;
        
        const shareText = `¡Hola! Aquí tienes tu comprobante oficial de inscripción en la Plataforma Digital. Puedes descargarlo o imprimirlo aquí: ${url}`;
        const whatsappUrl = `https://api.whatsapp.com/send?text=${encodeURIComponent(shareText)}`;
        window.open(whatsappUrl, '_blank');
    }

    function shareVoucherTelegram(id) {
        const currentPath = window.location.pathname;
        const folder = currentPath.split('/')[1];
        const url = `${window.location.protocol}//${window.location.host}/${folder}/comprobante.php?id=${id}`;
        
        const shareText = `¡Hola! Aquí tienes tu comprobante oficial de inscripción en la Plataforma Digital. Puedes descargarlo o imprimirlo aquí: ${url}`;
        const tgUrl = `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(shareText)}`;
        window.open(tgUrl, '_blank');
    }

    // ==================== FUNCIONES DEL MÓDULO DE CONFIGURACIÓN ADELOG ====================
    function saveMarcaConfig() {
        const payload = {
            candidato_nombre: document.getElementById('config-candidato-nombre').value,
            candidato_cargo: document.getElementById('config-candidato-cargo').value,
            plataforma_nombre: document.getElementById('config-plataforma-nombre').value,
            candidato_logo_url: document.getElementById('config-candidato-logo').value,
            login_banner_url: document.getElementById('config-login-banner').value,
            social_instagram_url: document.getElementById('config-social-instagram') ? document.getElementById('config-social-instagram').value.trim() : '',
            social_facebook_url: document.getElementById('config-social-facebook') ? document.getElementById('config-social-facebook').value.trim() : '',
            social_tiktok_url: document.getElementById('config-social-tiktok') ? document.getElementById('config-social-tiktok').value.trim() : '',
            social_x_url: document.getElementById('config-social-x') ? document.getElementById('config-social-x').value.trim() : '',
            limite_intentos_login: document.getElementById('config-intentos-fallidos').value,
            bloqueo_ip_tiempo: document.getElementById('config-bloqueo-tiempo').value,
            inactividad_sesion: document.getElementById('config-inactividad').value
        };

        fetch('../backend/api/settings.php?action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                showNotification("✓ Ajustes de Marca guardados y recursos compilados correctamente.", "success");
                loadConfigData();
            } else {
                alert(data.mensaje);
            }
        })
        .catch(() => alert("Error de red al guardar los ajustes de marca."));
    }

    function saveSmtpConfig() {
        const payload = {
            smtp: {
                smtp_host: document.getElementById('smtp-host').value,
                smtp_port: document.getElementById('smtp-port').value,
                smtp_user: document.getElementById('smtp-user').value,
                smtp_pass: document.getElementById('smtp-pass').value,
                smtp_secure: document.getElementById('smtp-secure').value,
                from_email: document.getElementById('smtp-from-email').value,
                from_name: document.getElementById('smtp-from-name').value
            }
        };

        fetch('../backend/api/settings.php?action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                showNotification("✓ Servidor SMTP guardado correctamente.", "success");
                loadConfigData();
            } else {
                alert(data.mensaje);
            }
        })
        .catch(() => alert("Error de red al guardar la configuración SMTP."));
    }

    function runSmtpTest() {
        const email = document.getElementById('test-smtp-email').value.trim();
        const consoleLog = document.getElementById('smtp-console-log');
        if (empty(email)) {
            alert("Por favor, ingrese un correo de destino válido.");
            return;
        }

        consoleLog.textContent = "[SYSTEM] Iniciando Handshake SMTP con " + email + "...\n";
        consoleLog.scrollTop = consoleLog.scrollHeight;

        const formData = new FormData();
        formData.append('email', email);

        fetch('../backend/api/settings.php?action=test_smtp', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            consoleLog.textContent += data.debug;
            if (data.exito) {
                showNotification("✓ Prueba SMTP enviada con éxito.", "success");
            } else {
                showNotification("❌ Error: La prueba SMTP falló.", "danger");
            }
            consoleLog.scrollTop = consoleLog.scrollHeight;
            loadConfigData();
        })
        .catch(err => {
            consoleLog.textContent += "\n[ERROR RED] No se pudo conectar con el endpoint de prueba.";
            consoleLog.scrollTop = consoleLog.scrollHeight;
        });
    }

    function runApiTest(service) {
        const consoleLog = document.getElementById('api-console-log');
        if (!consoleLog) return;
        
        consoleLog.textContent = "[SYSTEM] Iniciando test de conexión con la API (" + service + ")...\n";
        consoleLog.style.color = "#10b981";
        
        const badgeId = service === 'google_vision' ? 'status-ocr-badge' : 'status-dgii-badge';
        const badgeEl = document.getElementById(badgeId);
        if (badgeEl) {
            badgeEl.textContent = "PROBANDO...";
            badgeEl.className = "badge";
            badgeEl.style.backgroundColor = "var(--text-muted)";
        }
        
        fetch(`../backend/api/settings.php?action=test_api&service=${service}`)
            .then(res => res.json())
            .then(data => {
                consoleLog.textContent = data.debug;
                if (data.exito) {
                    showNotification(`✓ Conexión con ${service} exitosa.`, "success");
                    consoleLog.style.color = "#10b981";
                    if (badgeEl) {
                        badgeEl.textContent = "ACTIVO";
                        badgeEl.className = "badge badge-success";
                        badgeEl.style.backgroundColor = "";
                    }
                } else {
                    showNotification(`❌ Conexión con ${service} falló (HTTP ${data.http_code}).`, "danger");
                    consoleLog.style.color = "#ef4444";
                    if (badgeEl) {
                        badgeEl.textContent = "INACTIVO";
                        badgeEl.className = "badge badge-danger";
                        badgeEl.style.backgroundColor = "";
                    }
                }
                consoleLog.scrollTop = consoleLog.scrollHeight;
            })
            .catch(err => {
                consoleLog.textContent += "\n[ERROR RED] No se pudo conectar con el endpoint de pruebas de APIs.";
                consoleLog.style.color = "#ef4444";
                consoleLog.scrollTop = consoleLog.scrollHeight;
                if (badgeEl) {
                    badgeEl.textContent = "ERROR";
                    badgeEl.className = "badge badge-danger";
                    badgeEl.style.backgroundColor = "";
                }
            });
    }

    function setFlowButtonState(btnId, value) {
        const btn = document.getElementById(btnId);
        if (!btn) return;
        
        btn.setAttribute('data-active', value);
        if (value === '1') {
            btn.style.backgroundColor = '#10b981';
            btn.innerHTML = '<i class="fa fa-toggle-on"></i> ACTIVO';
        } else {
            btn.style.backgroundColor = '#ef4444';
            btn.innerHTML = '<i class="fa fa-toggle-off"></i> INACTIVO';
        }
    }

    function saveApisConfig() {
        const payload = {
            apis: {
                google_vision: {
                    api_key: document.getElementById('api-ocr-key').value,
                    api_url: document.getElementById('api-ocr-url').value,
                    estado: 1
                },
                dgii_validador: {
                    api_key: document.getElementById('api-dgii-key').value,
                    api_url: document.getElementById('api-dgii-url').value,
                    estado: 1
                }
            }
        };

        fetch('../backend/api/settings.php?action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                showNotification("✓ Credenciales de APIs actualizadas correctamente.", "success");
            } else {
                alert(data.mensaje);
            }
        })
        .catch(() => alert("Error de red al actualizar llaves de APIs."));
    }

    function renderNotifLogs(logs) {
        const tbody = document.getElementById('notif-tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--text-gray);">Ningún correo o mensaje enviado aún.</td></tr>';
            return;
        }
        logs.forEach(log => {
            const tr = document.createElement('tr');
            const colorEstado = (log.estado && log.estado.toLowerCase() === 'enviado') ? '#10b981' : '#ef4444';
            
            let actionHtml = '';
            if (log.votante_id) {
                actionHtml = `
                    <div style="display: flex; gap: 4px; justify-content: center;">
                        <button class="btn btn-outline btn-sm btn-print-log" data-id="${log.votante_id}" title="Ver Constancia (PDF)" style="padding: 4px 6px; font-size: 11px;"><i class="fa fa-file-pdf"></i></button>
                        <button class="btn btn-outline btn-sm btn-email-log" data-id="${log.votante_id}" data-email="${log.destinatario}" title="Reenviar por Correo" style="padding: 4px 6px; font-size: 11px;"><i class="fa fa-envelope"></i></button>
                        <button class="btn btn-outline btn-sm btn-wa-log" data-id="${log.votante_id}" title="Compartir WhatsApp" style="padding: 4px 6px; font-size: 11px; color:#25D366; border-color:#25D366;"><i class="fab fa-whatsapp"></i></button>
                        <button class="btn btn-outline btn-sm btn-tg-log" data-id="${log.votante_id}" title="Compartir Telegram" style="padding: 4px 6px; font-size: 11px; color:#0088cc; border-color:#0088cc;"><i class="fab fa-telegram-plane"></i></button>
                    </div>
                `;
            } else {
                actionHtml = `<span style="color:var(--text-muted); font-size:11px;">Módulo de Prueba</span>`;
            }
            
            tr.innerHTML = `
                <td style="font-size:12px;">${log.fecha_envio || 'N/A'}</td>
                <td><span class="badge badge-info" style="text-transform:uppercase;">${log.tipo}</span></td>
                <td><strong>${log.destinatario}</strong></td>
                <td>${log.asunto || 'N/A'}</td>
                <td><span style="color: ${colorEstado}; font-weight:bold;">${(log.estado || 'PENDIENTE').toUpperCase()}</span></td>
                <td style="text-align: center;">${actionHtml}</td>
            `;
            
            if (log.votante_id) {
                // Adjuntar listeners
                setTimeout(() => {
                    const btnPrint = tr.querySelector('.btn-print-log');
                    const btnEmail = tr.querySelector('.btn-email-log');
                    const btnWa = tr.querySelector('.btn-wa-log');
                    const btnTg = tr.querySelector('.btn-tg-log');
                    
                    if (btnPrint) btnPrint.addEventListener('click', () => printVoterVoucher(log.votante_id));
                    if (btnEmail) btnEmail.addEventListener('click', () => {
                        const customEmail = prompt("Confirme o ingrese el correo para reenviar el comprobante:", log.destinatario);
                        if (customEmail) {
                            fetch(`../backend/api/voters.php?action=email_voucher&id=${log.votante_id}&email=${encodeURIComponent(customEmail)}`)
                                .then(res => res.json())
                                .then(data => {
                                    if (data.exito) {
                                        showNotification("✓ El comprobante de inscripción ha sido reenviado por correo con éxito.", "success");
                                        loadConfigData();
                                    } else {
                                        alert(data.mensaje);
                                    }
                                })
                                .catch(() => alert("Error de red al reenviar el comprobante."));
                        }
                    });
                    if (btnWa) btnWa.addEventListener('click', () => shareVoucherWhatsApp(log.votante_id));
                    if (btnTg) btnTg.addEventListener('click', () => shareVoucherTelegram(log.votante_id));
                }, 0);
            }
            
            tbody.appendChild(tr);
        });
    }

    let etlFileParsedColumns = [];
    
    function handleEtlUpload(file) {
        const uploadArea = document.getElementById('etl-upload-area');
        uploadArea.innerHTML = `<i class="fa fa-spinner fa-spin" style="font-size: 40px; color: var(--secondary); margin-bottom: 15px;"></i>
                                <h4 style="color: var(--text-white);">Analizando estructura del archivo...</h4>`;
        
        const formData = new FormData();
        formData.append('file', file);
        
        fetch('../backend/api/import_etl.php?action=upload', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                showNotification("✓ Estructura de columnas analizada con éxito.", "success");
                etlFileParsedColumns = data.columnas;
                renderEtlColumnMapper(data.columnas, data.muestras);
            } else {
                alert(data.mensaje);
                resetEtlUploadArea();
            }
        })
        .catch(err => {
            alert("Error de red al subir archivo ETL.");
            resetEtlUploadArea();
        });
    }
    
    function resetEtlUploadArea() {
        const uploadArea = document.getElementById('etl-upload-area');
        if (uploadArea) {
            uploadArea.innerHTML = `
                <i class="fa fa-cloud-upload-alt" style="font-size: 40px; color: var(--primary-light); margin-bottom: 15px;"></i>
                <h4 style="margin: 0 0 5px 0; color: var(--text-white);">Arrastra tu archivo aquí o haz clic para buscar</h4>
                <p style="margin: 0; font-size: 12px; color: var(--text-gray);">Formatos permitidos: .xlsx, .csv</p>
                <input type="file" id="etl-file-input" style="display: none;" accept=".xlsx,.csv">
            `;
        }
        document.getElementById('etl-mapping-area').style.display = 'none';
    }
    
    function renderEtlColumnMapper(columns, samples) {
        const container = document.getElementById('mapping-fields-container');
        container.innerHTML = '';
        
        const fields = [
            { id: 'cedula', label: 'Cédula (Luhn)*' },
            { id: 'nombres', label: 'Nombres*' },
            { id: 'apellidos', label: 'Apellidos' },
            { id: 'telefono', label: 'Teléfono' },
            { id: 'colegio_electoral', label: 'Colegio Electoral' },
            { id: 'recinto_ubicacion', label: 'Recinto Electoral' },
            { id: 'sector', label: 'Sector' },
            { id: 'municipio', label: 'Municipio' },
            { id: 'coordinador', label: 'Coordinador Asignado' }
        ];
        
        fields.forEach(field => {
            const div = document.createElement('div');
            div.className = 'form-group';
            
            let optionsHtml = '<option value="">-- Ignorar o No existe --</option>';
            columns.forEach((col, idx) => {
                const selected = col.toLowerCase().includes(field.id) || 
                                 (field.id === 'colegio_electoral' && col.toLowerCase().includes('colegio')) ||
                                 (field.id === 'recinto_ubicacion' && col.toLowerCase().includes('recinto'))
                                 ? 'selected' : '';
                optionsHtml += `<option value="${idx}" ${selected}>${col}</option>`;
            });
            
            div.innerHTML = `
                <label class="form-label">${field.label}</label>
                <select id="etl-map-${field.id}" class="form-control mapping-select">
                    ${optionsHtml}
                </select>
            `;
            container.appendChild(div);
        });
        
        document.getElementById('etl-upload-area').style.display = 'none';
        document.getElementById('etl-mapping-area').style.display = 'block';
    }
    
    function cancelEtlMapping() {
        resetEtlUploadArea();
        document.getElementById('etl-upload-area').style.display = 'block';
    }
    
    function runEtlImport() {
        const mapping = {};
        const fields = ['cedula', 'nombres', 'apellidos', 'telefono', 'colegio_electoral', 'recinto_ubicacion', 'sector', 'municipio', 'coordinador'];
        
        const mapCedula = document.getElementById('etl-map-cedula').value;
        const mapNombres = document.getElementById('etl-map-nombres').value;
        if (mapCedula === "" || mapNombres === "") {
            alert("Los campos Cédula y Nombres son requeridos para la importación.");
            return;
        }
        
        fields.forEach(f => {
            const val = document.getElementById(`etl-map-${f}`).value;
            mapping[f] = val !== "" ? parseInt(val) : null;
        });
        
        const runBtn = document.getElementById('btn-run-etl');
        runBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Procesando, espere por favor...';
        runBtn.disabled = true;
        
        fetch('../backend/api/import_etl.php?action=execute', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mapping })
        })
        .then(res => res.json())
        .then(data => {
            runBtn.innerHTML = '<i class="fa fa-cog"></i> Ejecutar Carga y Saneamiento';
            runBtn.disabled = false;
            
            if (data.exito) {
                alert(`Carga completada con éxito:\n\n✓ Registros importados: ${data.cargados}\n⚠ Registros omitidos (duplicados / inválidos): ${data.omitidos}`, "Importación Completada");
                cancelEtlMapping();
                loadConfigData();
            } else {
                alert("Error durante la importación: " + data.mensaje);
            }
        })
        .catch(err => {
            runBtn.innerHTML = '<i class="fa fa-cog"></i> Ejecutar Carga y Saneamiento';
            runBtn.disabled = false;
            alert("Error de red al ejecutar ETL.");
        });
    }
    
    function loadEtlHistory() {
        fetch('../backend/api/import_etl.php?action=history')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    const tbody = document.getElementById('etl-history-tbody');
                    if (!tbody) return;
                    tbody.innerHTML = '';
                    if (data.historial.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:var(--text-gray);">Ninguna carga ETL registrada.</td></tr>';
                        return;
                    }
                    data.historial.forEach(h => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td style="font-size:12px;">${h.fecha_ejecucion}</td>
                            <td><strong>${h.nombre_archivo}</strong></td>
                            <td><span style="color:#10b981; font-weight:bold;">${h.registros_cargados}</span></td>
                            <td><span style="color:#f59e0b; font-weight:bold;">${h.registros_omitidos}</span></td>
                            <td><pre style="max-height:80px; overflow-y:auto; font-size:11px; margin:0; white-space:pre-wrap; max-width:250px;">${h.detalles_errores || 'Ninguno'}</pre></td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            });
    }
    
    function loadFinanzasData() {
        fetch('../backend/api/settings.php?action=get_finanzas')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    const t = data.totales;
                    document.getElementById('finanzas-bruto').innerText = `USD $${parseFloat(t.bruto).toFixed(2)}`;
                    document.getElementById('finanzas-comisiones').innerText = `USD $${parseFloat(t.comisiones).toFixed(2)}`;
                    document.getElementById('finanzas-neto').innerText = `USD $${parseFloat(t.neto).toFixed(2)}`;
                    
                    const tbody = document.getElementById('finanzas-tbody');
                    if (!tbody) return;
                    tbody.innerHTML = '';
                    if (data.transacciones.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; color:var(--text-gray);">Ninguna venta registrada.</td></tr>';
                        return;
                    }
                    data.transacciones.forEach(tx => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td style="font-size:12px;">${tx.fecha}</td>
                            <td style="font-family:monospace; font-size:12px;">${tx.txn_id}</td>
                            <td><strong>${tx.comprador}</strong></td>
                            <td><span class="badge badge-info">${tx.plan}</span></td>
                            <td>$${tx.monto_bruto}</td>
                            <td style="color:var(--secondary); font-weight:bold;">$${tx.comision_paypal}</td>
                            <td style="color:#10b981; font-weight:bold;">$${tx.monto_neto}</td>
                            <td style="text-align:center;">
                                <a href="../backend/api/invoice.php?txn_id=${encodeURIComponent(tx.txn_id)}" target="_blank" class="btn btn-outline btn-sm" style="padding: 4px 8px; font-size: 11px; display: inline-flex; align-items: center; gap: 4px; border-color: var(--secondary); color: var(--secondary); text-decoration: none;">
                                    <i class="fa fa-file-pdf"></i> PDF
                                </a>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            });
    }

    function loadConfigData() {
        fetch('../backend/api/settings.php?action=get')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    const c = data.configuraciones;
                    document.getElementById('config-candidato-nombre').value = c.candidato_nombre || '';
                    document.getElementById('config-candidato-cargo').value = c.candidato_cargo || '';
                    document.getElementById('config-plataforma-nombre').value = c.plataforma_nombre || '';
                    document.getElementById('config-candidato-logo').value = c.candidato_logo_url || '';
                    
                    // Actualizar el banner superior de la plataforma
                    const topBannerImg = document.getElementById('dashboard-header-banner');
                    if (topBannerImg) {
                        topBannerImg.src = c.candidato_logo_url || '../GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png';
                    }
                    document.getElementById('config-login-banner').value = c.login_banner_url || '';
                    
                    // Cargar plantillas de flujos de notificaciones
                    document.getElementById('flow-email-subject').value = c.flow_email_subject || 'Tu Constancia de Inscripción Padronal PAD/28-32';
                    document.getElementById('flow-email-body').value = c.flow_email_body || `
<div style="background-color: #f1f5f9; padding: 30px 15px; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
        <!-- Header -->
        <div style="background-color: #0054A6; padding: 25px; text-align: center; border-bottom: 4px solid #E3A113;">
            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: bold; letter-spacing: 1px;">ADELOG</h1>
            <p style="color: #cbd5e1; margin: 5px 0 0 0; font-size: 13px; text-transform: uppercase;">Constancia Oficial de Inscripción</p>
        </div>
        
        <!-- Content -->
        <div style="padding: 30px 25px;">
            <h2 style="color: #0f172a; margin-top: 0; margin-bottom: 10px; font-size: 20px; text-align: center;">¡Gracias por tu Apoyo y Lealtad!</h2>
            <p style="font-size: 14px; color: #475569; text-align: center; margin-bottom: 25px; line-height: 1.5;">
                Queremos expresarte nuestro más profundo agradecimiento por tu valioso apoyo y lealtad a la candidatura de la <strong>Pastora Altagracia</strong>. 
                Tu compromiso es el motor que nos impulsa a seguir trabajando incansablemente por el cambio y el desarrollo de nuestra gente.
            </p>
            
            <div style="background-color: #f8fafc; border: 1px solid #0054A6; border-left: 5px solid #0054A6; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                <h4 style="margin-top: 0; margin-bottom: 15px; color: #0054A6; font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">Detalles de la Inscripción</h4>
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; color: #334155;">
                    <tr>
                        <td style="padding: 8px 0; font-weight: bold; width: 40%;">Número de Lista:</td>
                        <td style="padding: 8px 0; text-align: right; font-weight: bold; color: #0f172a; font-size: 16px;">#{numero_lista}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; font-weight: bold;">Cédula:</td>
                        <td style="padding: 8px 0; text-align: right;">{cedula}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; font-weight: bold;">Nombre Completo:</td>
                        <td style="padding: 8px 0; text-align: right; font-weight: bold; color: #0054A6;">{nombre_completo}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; font-weight: bold;">Colegio Electoral:</td>
                        <td style="padding: 8px 0; text-align: right;">{colegio}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; font-weight: bold;">Recinto Electoral:</td>
                        <td style="padding: 8px 0; text-align: right;">{recinto}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; font-weight: bold;">Región / Sector:</td>
                        <td style="padding: 8px 0; text-align: right;">{region}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; font-weight: bold;">Coordinador:</td>
                        <td style="padding: 8px 0; text-align: right;">{coordinador}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; font-weight: bold;">Centro de Acopio:</td>
                        <td style="padding: 8px 0; text-align: right;">{centro_acopio}</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; font-weight: bold;">Fecha de Registro:</td>
                        <td style="padding: 8px 0; text-align: right;">{fecha}</td>
                    </tr>
                </table>
            </div>

            <div style="text-align: center; padding-top: 15px; border-top: 1px solid #f1f5f9;">
                <p style="font-size: 13px; font-weight: bold; color: #0f172a; margin: 0 0 5px 0;">Campaña Pastora Altagracia - PRM 2026</p>
                <p style="font-size: 11px; color: #94a3b8; margin: 0;">Unidos por la transparencia, el cambio y el desarrollo.</p>
            </div>
        </div>
        
        <!-- Footer -->
        <div style="background-color: #0f172a; padding: 15px; text-align: center; font-size: 11px; color: #64748b;">
            Este documento constituye una constancia de inscripción oficial registrada en ADELOG.<br>
            Desarrollado para la administración de logisticas de comandos de campañas en RD, por sypempresariales . Copyright © 2026 Sypempresariales.
        </div>
    </div>
</div>`;
                    document.getElementById('flow-whatsapp-body').value = c.flow_whatsapp_body || '¡Hola {nombre_completo}! Tu inscripción ha sido procesada con éxito. Número de Lista: #{numero_lista}. Gracias por tu lealtad y apoyo. Recinto: {recinto}. Campaña Pastora Altagracia.';
                    document.getElementById('flow-helpdesk-subject').value = c.flow_helpdesk_subject || '[Alerta ADELOG] Nueva Incidencia Reportada: {titulo}';
                    document.getElementById('flow-helpdesk-body').value = c.flow_helpdesk_body || `Se ha registrado una nueva incidencia de soporte técnico.\n\nID Ticket: {ticket_id}\nTítulo: {titulo}\nDescripción: {descripcion}\nCreado por: {usuario_creador}\nFecha: {fecha}`;
                    document.getElementById('config-intentos-fallidos').value = c.limite_intentos_login || 5;
                    document.getElementById('config-bloqueo-tiempo').value = c.bloqueo_ip_tiempo || 15;
                    document.getElementById('config-inactividad').value = c.inactividad_sesion || 30;
                    
                    // Cargar estado de los flujos de notificaciones
                    setFlowButtonState('btn-flow-email', c.flow_email_voucher || '0');
                    setFlowButtonState('btn-flow-whatsapp', c.flow_whatsapp_voucher || '0');
                    setFlowButtonState('btn-flow-helpdesk', c.flow_helpdesk_alert || '0');
                    
                    const smtp = data.smtp || {};
                    document.getElementById('smtp-host').value = smtp.smtp_host || '';
                    document.getElementById('smtp-port').value = smtp.smtp_port || 587;
                    document.getElementById('smtp-user').value = smtp.smtp_user || '';
                    document.getElementById('smtp-pass').value = smtp.smtp_pass || '';
                    document.getElementById('smtp-secure').value = smtp.smtp_secure || 'tls';
                    document.getElementById('smtp-from-email').value = smtp.from_email || '';
                    document.getElementById('smtp-from-name').value = smtp.from_name || '';
                    
                    const apis = data.apis || {};
                    if (apis.google_vision) {
                        document.getElementById('api-ocr-key').value = apis.google_vision.api_key || '';
                        document.getElementById('api-ocr-url').value = apis.google_vision.api_url || '';
                        const statusOcrUrl = document.getElementById('status-ocr-url');
                        if (statusOcrUrl) statusOcrUrl.innerText = apis.google_vision.api_url || 'No configurada';
                    }
                    if (apis.dgii_validador) {
                        document.getElementById('api-dgii-key').value = apis.dgii_validador.api_key || '';
                        document.getElementById('api-dgii-url').value = apis.dgii_validador.api_url || '';
                        const statusDgiiUrl = document.getElementById('status-dgii-url');
                        if (statusDgiiUrl) statusDgiiUrl.innerText = apis.dgii_validador.api_url || 'No configurada';
                    }
                    
                    renderNotifLogs(data.logs_notificaciones);
                    loadFinanzasData();
                    loadEtlHistory();

                    if (document.getElementById('config-social-instagram')) document.getElementById('config-social-instagram').value = c.social_instagram_url || '';
                    if (document.getElementById('config-social-facebook')) document.getElementById('config-social-facebook').value = c.social_facebook_url || '';
                    if (document.getElementById('config-social-tiktok')) document.getElementById('config-social-tiktok').value = c.social_tiktok_url || '';
                    if (document.getElementById('config-social-x')) document.getElementById('config-social-x').value = c.social_x_url || '';
                    loadSocialFeedAdmin();
                }
            });
    }

    // ─── GESTOR DE REDES SOCIALES (FACEBOOK & INSTAGRAM FEED) ────────────────
    function loadSocialFeedAdmin() {
        fetch('../backend/api/settings.php?action=get_social_feed')
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    renderSocialFeedAdminTable(data.publicaciones || []);
                }
            })
            .catch(err => console.error("Error al cargar feed de redes:", err));
    }

    function renderSocialFeedAdminTable(posts) {
        const tbody = document.getElementById('social-feed-tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        
        if (posts.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:var(--text-gray); padding:15px;">No hay publicaciones registradas en el feed.</td></tr>';
            return;
        }

        posts.forEach(p => {
            const tr = document.createElement('tr');
            let redIcon = 'fab fa-instagram';
            let redColor = '#E1306C';
            if (p.red_social === 'Facebook') { redIcon = 'fab fa-facebook'; redColor = '#1877F2'; }
            else if (p.red_social === 'TikTok') { redIcon = 'fab fa-tiktok'; redColor = '#00f2fe'; }
            else if (p.red_social === 'X') { redIcon = 'fab fa-twitter'; redColor = '#1DA1F2'; }

            const estadoBadge = p.activo == 1 
                ? '<span class="badge badge-success" style="cursor:pointer;" title="Clic para pausar">ACTIVO</span>' 
                : '<span class="badge badge-danger" style="cursor:pointer;" title="Clic para activar">PAUSADO</span>';

            tr.innerHTML = `
                <td><span style="color:${redColor}; font-weight:600;"><i class="${redIcon}"></i> ${p.red_social}</span></td>
                <td style="font-size:12px; color:var(--text-muted);">${p.tiempo_publicacion}</td>
                <td style="max-width:280px; font-size:12px; color:var(--text-white);">${p.contenido}</td>
                <td style="font-size:11px; color:var(--secondary);">${p.hashtags || ''}</td>
                <td style="text-align:center;">${estadoBadge}</td>
                <td style="text-align:center;">
                    <div style="display:flex; gap:4px; justify-content:center;">
                        <button type="button" class="btn btn-outline btn-sm btn-edit-social" title="Editar Publicación" style="padding:4px 6px; font-size:11px;"><i class="fa fa-edit"></i></button>
                        <button type="button" class="btn btn-outline btn-sm btn-del-social" title="Eliminar Publicación" style="padding:4px 6px; font-size:11px; color:#ef4444; border-color:#ef4444;"><i class="fa fa-trash"></i></button>
                    </div>
                </td>
            `;

            // Listeners
            tr.querySelector('td:nth-child(5)').addEventListener('click', () => {
                toggleSocialPost(p.id, p.activo);
            });
            tr.querySelector('.btn-edit-social').addEventListener('click', () => {
                openSocialPostModal(p);
            });
            tr.querySelector('.btn-del-social').addEventListener('click', () => {
                deleteSocialPost(p.id);
            });

            tbody.appendChild(tr);
        });
    }

    function openSocialPostModal(post = null) {
        const modal = document.getElementById('modal-social-post');
        if (!modal) return;
        
        const title = document.getElementById('modal-social-title');
        const idInput = document.getElementById('social-post-id');
        const redSelect = document.getElementById('social-post-red');
        const tiempoInput = document.getElementById('social-post-tiempo');
        const contenidoInput = document.getElementById('social-post-contenido');
        const hashtagsInput = document.getElementById('social-post-hashtags');
        const enlaceInput = document.getElementById('social-post-enlace');
        const activoCheck = document.getElementById('social-post-activo');

        if (post) {
            title.innerHTML = '<i class="fa fa-edit" style="color:var(--secondary);"></i> Editar Publicación de Red Social';
            idInput.value = post.id;
            redSelect.value = post.red_social;
            tiempoInput.value = post.tiempo_publicacion;
            contenidoInput.value = post.contenido;
            hashtagsInput.value = post.hashtags || '';
            enlaceInput.value = post.enlace_publicacion || '';
            activoCheck.checked = (post.activo == 1);
        } else {
            title.innerHTML = '<i class="fa fa-plus" style="color:var(--secondary);"></i> Nueva Publicación de Red Social';
            idInput.value = '0';
            redSelect.value = 'Instagram';
            tiempoInput.value = 'Hace unos momentos';
            contenidoInput.value = '';
            hashtagsInput.value = '#PastoraDiputada #SDECir3 #PRM';
            enlaceInput.value = '';
            activoCheck.checked = true;
        }

        openModal('modal-social-post');
    }

    function saveSocialPost() {
        const id = parseInt(document.getElementById('social-post-id').value) || 0;
        const payload = {
            id: id,
            red_social: document.getElementById('social-post-red').value,
            tiempo_publicacion: document.getElementById('social-post-tiempo').value.trim(),
            contenido: document.getElementById('social-post-contenido').value.trim(),
            hashtags: document.getElementById('social-post-hashtags').value.trim(),
            enlace_publicacion: document.getElementById('social-post-enlace').value.trim(),
            activo: document.getElementById('social-post-activo').checked ? 1 : 0
        };

        if (!payload.contenido) {
            alert("El contenido de la publicación no puede estar vacío.");
            return;
        }

        fetch('../backend/api/settings.php?action=save_social_post', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                closeModal('modal-social-post');
                showNotification("✓ Publicación guardada en el feed.", "success");
                loadSocialFeedAdmin();
            } else {
                alert(data.mensaje);
            }
        })
        .catch(() => alert("Error de red al guardar la publicación."));
    }

    function deleteSocialPost(id) {
        showCustomConfirm("¿Está seguro de que desea eliminar esta publicación del feed?").then(confirmed => {
            if (!confirmed) return;
            fetch('../backend/api/settings.php?action=delete_social_post', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    showNotification("✓ Publicación eliminada.", "success");
                    loadSocialFeedAdmin();
                } else {
                    alert(data.mensaje);
                }
            })
            .catch(() => alert("Error de red al eliminar publicación."));
        });
    }

    function toggleSocialPost(id, currentActive) {
        const newActive = currentActive == 1 ? 0 : 1;
        fetch('../backend/api/settings.php?action=toggle_social_post', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, activo: newActive })
        })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                loadSocialFeedAdmin();
            }
        })
        .catch(() => alert("Error al alternar estado."));
    }

    // ─── MÓDULO DE RED TERRITORIAL Y TRABAJO DE COORDINADORES ────────────────
    let cachedCoordinatorsData = [];
    let cachedNetworkVoters = [];

    function loadRedTerritorial(resetCoord = false) {
        const levelSelect = document.getElementById('filter-network-level');
        const level = levelSelect ? levelSelect.value : 'all';
        
        fetch(`../backend/api/voters.php?action=coordinators_stats&nivel=${encodeURIComponent(level)}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    cachedCoordinatorsData = data.coordinadores || [];
                    populateCoordinatorsDropdowns(cachedCoordinatorsData, resetCoord);
                    calculateAndRenderNetworkKPIs(cachedCoordinatorsData);
                    
                    const coordSelect = document.getElementById('filter-network-coord');
                    const selectedCoord = coordSelect ? coordSelect.value : '';
                    const search = document.getElementById('filter-network-search') ? document.getElementById('filter-network-search').value : '';
                    loadNetworkVoters(selectedCoord, level, search);
                }
            })
            .catch(() => {
                console.error("Error al cargar datos de red territorial.");
            });
    }

    function populateCoordinatorsDropdowns(coords, resetCoord = false) {
        const coordSelect = document.getElementById('filter-network-coord');
        const padreSelect = document.getElementById('voter-coordinador-padre');
        
        if (coordSelect) {
            const currentVal = resetCoord ? '' : coordSelect.value;
            coordSelect.innerHTML = '<option value="">-- Toda la Red Consolidada --</option>';
            coords.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.nombre;
                opt.textContent = `[${c.rol}] ${c.nombre} (${c.total_red} votantes)`;
                if (c.nombre === currentVal) opt.selected = true;
                coordSelect.appendChild(opt);
            });
        }

        if (padreSelect) {
            const currentPadreVal = padreSelect.value;
            padreSelect.innerHTML = '<option value="">Seleccione coordinador superior...</option>';
            coords.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.nombre;
                opt.textContent = `[${c.rol}] ${c.nombre}`;
                if (c.nombre === currentPadreVal) opt.selected = true;
                padreSelect.appendChild(opt);
            });
        }
    }

    function calculateAndRenderNetworkKPIs(coords) {
        let totalRed = 0;
        let totalDirectos = 0;
        let totalML = 0;
        let totalMeta = 0;

        const coordSelect = document.getElementById('filter-network-coord');
        const selectedCoord = coordSelect ? coordSelect.value : '';

        if (selectedCoord) {
            const active = coords.find(c => c.nombre === selectedCoord);
            if (active) {
                totalRed = active.total_red;
                totalDirectos = active.total_directos;
                totalML = active.total_ml;
                totalMeta = active.meta;
            }
        } else {
            coords.forEach(c => {
                totalRed += c.total_red;
                totalDirectos += c.total_directos;
                totalML += c.total_ml;
                totalMeta += c.meta;
            });
        }

        const pctMeta = totalMeta > 0 ? Math.min(100, Math.round((totalRed / totalMeta) * 100)) : 0;

        const elTotal = document.getElementById('net-stat-total');
        if (elTotal) elTotal.textContent = totalRed.toLocaleString();
        
        const elDir = document.getElementById('net-stat-directos');
        if (elDir) elDir.textContent = totalDirectos.toLocaleString();

        const elML = document.getElementById('net-stat-ml');
        if (elML) elML.textContent = totalML.toLocaleString();

        const elMeta = document.getElementById('net-stat-meta');
        if (elMeta) elMeta.textContent = pctMeta + '%';
    }

    function loadNetworkVoters(coord = '', level = 'all', search = '') {
        fetch(`../backend/api/voters.php?action=network_voters&coordinador=${encodeURIComponent(coord)}&nivel=${encodeURIComponent(level)}`)
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    let voters = data.votantes || [];
                    if (search && search.trim()) {
                        const q = search.trim().toLowerCase();
                        voters = voters.filter(v => 
                            (v.cedula && v.cedula.toLowerCase().includes(q)) ||
                            (v.nombres && v.nombres.toLowerCase().includes(q)) ||
                            (v.apellidos && v.apellidos.toLowerCase().includes(q)) ||
                            (v.colegio_electoral && v.colegio_electoral.toLowerCase().includes(q)) ||
                            (v.sector && v.sector.toLowerCase().includes(q))
                        );
                    }
                    cachedNetworkVoters = voters;
                    renderNetworkVotersTable(voters, coord, level);
                }
            });
    }

    function renderNetworkVotersTable(votantes, coord = '', level = 'all') {
        const tbody = document.getElementById('net-voters-tbody');
        const countBadge = document.getElementById('net-active-count');
        const titleEl = document.getElementById('net-active-title');
        const subtitleEl = document.getElementById('net-active-subtitle');

        if (countBadge) countBadge.textContent = `${votantes.length} Votantes en esta sección`;
        if (titleEl) {
            titleEl.innerHTML = coord 
                ? `<i class="fa fa-user-tie" style="color: var(--secondary); margin-right: 6px;"></i> Padrón Seccionado: Red de <strong>${coord}</strong>`
                : `<i class="fa fa-network-wired" style="color: var(--secondary); margin-right: 6px;"></i> Padrón Seccionado Consolidado de la Red`;
        }
        if (subtitleEl) {
            subtitleEl.textContent = coord 
                ? `Mostrando electores y militantes bajo la línea de mando de ${coord}.`
                : `Visualizando todos los electores registrados en las diferentes estructuras activas.`;
        }

        if (!tbody) return;
        tbody.innerHTML = '';

        if (votantes.length === 0) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: var(--text-muted); padding: 25px;">No se encontraron electores para la red o filtro seleccionado.</td></tr>`;
            return;
        }

        votantes.forEach(v => {
            const tr = document.createElement('tr');
            const isML = (v.es_militante_lider == 1);
            const badgePerfil = isML 
                ? '<span class="badge" style="background:#E3A113; color:#000; font-weight:bold; font-size:11px;"><i class="fa fa-user-tag"></i> ML Líder</span>'
                : '<span class="badge badge-primary" style="font-size:11px;">Votante</span>';

            tr.innerHTML = `
                <td><strong>#${v.numero_lista}</strong></td>
                <td><span style="font-family:monospace; font-weight:bold; color:var(--secondary); font-size:12px;">${v.codigo_comprobante || ('PAD2832-' + v.numero_lista + '-' + v.cedula)}</span></td>
                <td><span style="font-family:monospace; font-weight:600;">${v.cedula}</span></td>
                <td>${v.nombres} ${v.apellidos}</td>
                <td>${badgePerfil}</td>
                <td><span class="badge badge-primary">${v.colegio_electoral}</span></td>
                <td>${v.sector || ''}, ${v.municipio || ''}</td>
                <td><span style="font-size:12px; color:var(--text-muted); font-weight:600; text-transform:uppercase;">${v.coordinador}</span></td>
                <td>
                    <button class="btn btn-outline btn-sm btn-print-net-row" data-id="${v.id}" title="Imprimir Comprobante Oficial"><i class="fa fa-print"></i></button>
                    <button class="btn btn-outline btn-sm btn-email-net-row" data-id="${v.id}" data-email="${v.email || ''}" title="Enviar Comprobante por Correo"><i class="fa fa-envelope"></i></button>
                    ${State.perms.can_edit == 1 ? `<button class="btn btn-outline btn-sm btn-edit-net-row" data-id="${v.id}" title="Editar Votante"><i class="fa fa-edit"></i></button>` : ''}
                </td>
            `;

            tr.querySelector('.btn-print-net-row').addEventListener('click', () => printVoterVoucher(v.id));

            tr.querySelector('.btn-email-net-row').addEventListener('click', () => {
                const defaultEmail = v.email || '';
                const emailPrompt = prompt("Confirme o ingrese el correo del votante para despachar el comprobante oficial:", defaultEmail);
                if (emailPrompt && emailPrompt.trim()) {
                    showNotification("⏳ Enviando comprobante por correo...", "info");
                    fetch(`../backend/api/voters.php?action=email_voucher&id=${v.id}&email=${encodeURIComponent(emailPrompt.trim())}`)
                        .then(res => res.json())
                        .then(resData => {
                            if (resData.exito) {
                                showNotification(`✓ Comprobante [${resData.codigo_comprobante || ''}] enviado exitosamente a ${emailPrompt}.`, "success");
                            } else {
                                alert("Error al enviar comprobante: " + resData.mensaje);
                            }
                        })
                        .catch(() => alert("Error de red al enviar comprobante."));
                }
            });

            if (State.perms.can_edit == 1) {
                tr.querySelector('.btn-edit-net-row').addEventListener('click', () => openEditModal(v.id));
            }

            tbody.appendChild(tr);
        });
    }

    // ─── IMPRESIÓN SECCIONADA POR RED (PDF) ───────────────────────────────────
    function printNetworkSeccionedPDF() {
        const coordSelect = document.getElementById('filter-network-coord');
        const selectedCoordName = coordSelect ? coordSelect.value : '';
        const levelSelect = document.getElementById('filter-network-level');
        const selectedLevel = levelSelect ? levelSelect.value : 'all';

        const activeLeader = cachedCoordinatorsData.find(c => c.nombre === selectedCoordName) || {
            nombre: selectedCoordName || 'Estructura Territorial General',
            rol: selectedLevel !== 'all' ? selectedLevel : 'Coordinación Territorial',
            total_directos: cachedNetworkVoters.length,
            total_ml: cachedNetworkVoters.filter(v => v.es_militante_lider == 1).length,
            total_red: cachedNetworkVoters.length,
            meta: 200
        };

        const pct = activeLeader.meta > 0 ? Math.min(100, Math.round((activeLeader.total_red / activeLeader.meta) * 100)) : 0;

        let printArea = document.getElementById('print-voucher-area');
        if (!printArea) {
            const div = document.createElement('div');
            div.id = 'print-voucher-area';
            document.body.appendChild(div);
            printArea = div;
        }
        printArea.className = 'print-hidden-container';

        let rowsHtml = '';
        cachedNetworkVoters.forEach((v, index) => {
            const folio = v.codigo_comprobante || (`PAD2832-${v.numero_lista}-${v.cedula}`);
            const tagStr = v.tipo_elector || (v.es_militante_lider == 1 ? 'Nuevo Elector (ML)' : 'Nuevo Elector');
            const rolStr = (v.es_militante_lider == 1) ? 'ML Líder' : 'Votante';
            rowsHtml += `
                <tr style="border-bottom: 1px solid #e2e8f0; font-size: 11px;">
                    <td style="padding: 6px 8px; text-align: center; font-weight: bold;">${index + 1}</td>
                    <td style="padding: 6px 8px; font-family: monospace; font-weight: bold; color: #0054A6;">${folio}</td>
                    <td style="padding: 6px 8px; font-family: monospace; white-space: nowrap;">${v.cedula}</td>
                    <td style="padding: 6px 8px; font-weight: 600; text-transform: uppercase;">${v.nombres} ${v.apellidos}</td>
                    <td style="padding: 6px 8px; text-align: center; font-weight: bold; color: #10b981;">${tagStr}</td>
                    <td style="padding: 6px 8px; text-align: center;">${v.telefono || 'N/D'}</td>
                    <td style="padding: 6px 8px; text-align: center; font-weight: bold;">${v.colegio_electoral}</td>
                    <td style="padding: 6px 8px;">${v.sector || ''}</td>
                    <td style="padding: 6px 8px; text-align: center; font-weight: bold; color: #E3A113;">${rolStr}</td>
                    <td style="padding: 6px 8px; border-left: 1px dashed #cbd5e1; min-width: 90px;"></td>
                </tr>
            `;
        });

        if (cachedNetworkVoters.length === 0) {
            rowsHtml = `<tr><td colspan="10" style="text-align: center; padding: 20px; color: #64748b;">No hay electores en esta red para imprimir.</td></tr>`;
        }

        printArea.innerHTML = `
            <div style="font-family: Arial, sans-serif; max-width: 950px; margin: 0 auto; padding: 15px; color: #000; background: #fff;">
                <!-- Header Banner -->
                <div style="text-align: center; margin-bottom: 15px;">
                    <img src="../GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png" alt="Pastora Altagracia" style="width: 100%; height: auto; display: block; border-bottom: 4px solid #E3A113; border-radius: 6px;">
                </div>

                <!-- Document Header -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid #0054A6; padding-bottom: 8px;">
                    <div>
                        <h2 style="margin: 0; font-size: 18px; color: #0054A6; text-transform: uppercase; letter-spacing: 0.5px;">PADRÓN SECCIONADO DE RED TERRITORIAL</h2>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #475569;">Planilla de Control y Verificación de Campo - Periodo 2028</p>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 11px; color: #64748b;">Fecha de Emisión:</span>
                        <div style="font-size: 12px; font-weight: bold; color: #0f172a;">${new Date().toLocaleDateString('es-DO')} ${new Date().toLocaleTimeString('es-DO', {hour:'2-digit', minute:'2-digit'})}</div>
                    </div>
                </div>

                <!-- Network Metrics Summary Card -->
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr; gap: 10px; background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 12px; margin-bottom: 20px;">
                    <div>
                        <span style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; display: block;">Líder / Responsable</span>
                        <strong style="font-size: 14px; color: #0054A6; text-transform: uppercase;">${activeLeader.nombre}</strong>
                        <span style="display: block; font-size: 11px; color: #475569; font-weight: 500;">Rol: ${activeLeader.rol}</span>
                    </div>
                    <div style="text-align: center; border-left: 1px solid #e2e8f0;">
                        <span style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; display: block;">Total Red</span>
                        <strong style="font-size: 16px; color: #0f172a;">${activeLeader.total_red}</strong>
                    </div>
                    <div style="text-align: center; border-left: 1px solid #e2e8f0;">
                        <span style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; display: block;">Directos</span>
                        <strong style="font-size: 16px; color: #10b981;">${activeLeader.total_directos}</strong>
                    </div>
                    <div style="text-align: center; border-left: 1px solid #e2e8f0;">
                        <span style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; display: block;">Militantes ML</span>
                        <strong style="font-size: 16px; color: #E3A113;">${activeLeader.total_ml}</strong>
                    </div>
                    <div style="text-align: center; border-left: 1px solid #e2e8f0;">
                        <span style="font-size: 10px; color: #64748b; font-weight: bold; text-transform: uppercase; display: block;">% Cumplimiento</span>
                        <strong style="font-size: 16px; color: #8b5cf6;">${pct}%</strong>
                    </div>
                </div>

                <!-- Table Section -->
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <thead>
                        <tr style="background-color: #0054A6; color: #ffffff; font-size: 11px; text-transform: uppercase;">
                            <th style="padding: 8px 6px; text-align: center; width: 35px;">No.</th>
                            <th style="padding: 8px 6px; text-align: left;">Folio Oficial</th>
                            <th style="padding: 8px 6px; text-align: left;">Cédula</th>
                            <th style="padding: 8px 6px; text-align: left;">Nombre y Apellidos</th>
                            <th style="padding: 8px 6px; text-align: center;">Etiqueta</th>
                            <th style="padding: 8px 6px; text-align: center;">Teléfono</th>
                            <th style="padding: 8px 6px; text-align: center;">Colegio</th>
                            <th style="padding: 8px 6px; text-align: left;">Sector</th>
                            <th style="padding: 8px 6px; text-align: center;">Rol Red</th>
                            <th style="padding: 8px 6px; text-align: center; width: 100px;">Firma Elector</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rowsHtml}
                    </tbody>
                </table>

                <!-- Footer Signatures Block -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 35px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
                    <div style="text-align: center;">
                        <div style="border-top: 1px solid #000; width: 220px; margin: 30px auto 5px auto;"></div>
                        <span style="font-size: 11px; font-weight: bold; text-transform: uppercase;">${activeLeader.nombre}</span>
                        <div style="font-size: 10px; color: #64748b;">Firma del Responsable de Red</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="border-top: 1px solid #000; width: 220px; margin: 30px auto 5px auto;"></div>
                        <span style="font-size: 11px; font-weight: bold; text-transform: uppercase;">Comisión Electoral ADELOG</span>
                        <div style="font-size: 10px; color: #64748b;">Auditoría y Validación Territorial</div>
                    </div>
                </div>
            </div>
        `;

        const prevTitle = document.title;
        const sanitizedCoord = (selectedCoordName || 'RED_TERRITORIAL').replace(/[^a-zA-Z0-9]/g, '_');
        document.title = `PAD2832-SECCIONADO_${sanitizedCoord}_${new Date().toISOString().slice(0,10)}`;
        window.print();
        setTimeout(() => {
            document.title = prevTitle;
        }, 1000);
    }

    // ─── IMPRESIÓN GENERAL CONSOLIDADA (PDF) ──────────────────────────────────
    function printNetworkGeneralPDF() {
        let printArea = document.getElementById('print-voucher-area');
        if (!printArea) {
            const div = document.createElement('div');
            div.id = 'print-voucher-area';
            document.body.appendChild(div);
            printArea = div;
        }
        printArea.className = 'print-hidden-container';

        let matrixRowsHtml = '';
        let totalGeneralRed = 0;
        let totalGeneralDirectos = 0;
        let totalGeneralML = 0;

        cachedCoordinatorsData.forEach((c, idx) => {
            totalGeneralRed += c.total_red;
            totalGeneralDirectos += c.total_directos;
            totalGeneralML += c.total_ml;
            const pct = c.meta > 0 ? Math.min(100, Math.round((c.total_red / c.meta) * 100)) : 0;

            matrixRowsHtml += `
                <tr style="border-bottom: 1px solid #e2e8f0; font-size: 12px;">
                    <td style="padding: 8px; text-align: center; font-weight: bold;">${idx + 1}</td>
                    <td style="padding: 8px; font-weight: 700; color: #0054A6; text-transform: uppercase;">${c.nombre}</td>
                    <td style="padding: 8px;"><span style="background: #e2e8f0; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">${c.rol}</span></td>
                    <td style="padding: 8px; text-align: center; font-weight: bold; color: #10b981;">${c.total_directos}</td>
                    <td style="padding: 8px; text-align: center; font-weight: bold; color: #E3A113;">${c.total_ml}</td>
                    <td style="padding: 8px; text-align: center; font-weight: bold; font-size: 13px; color: #0f172a;">${c.total_red}</td>
                    <td style="padding: 8px; text-align: center; color: #64748b;">${c.meta}</td>
                    <td style="padding: 8px; text-align: center; font-weight: bold; color: ${pct >= 100 ? '#10b981' : (pct >= 50 ? '#E3A113' : '#ef4444')};">${pct}%</td>
                </tr>
            `;
        });

        printArea.innerHTML = `
            <div style="font-family: Arial, sans-serif; max-width: 950px; margin: 0 auto; padding: 15px; color: #000; background: #fff;">
                <!-- Header Banner -->
                <div style="text-align: center; margin-bottom: 15px;">
                    <img src="../GRAFICOS PARA LA PAGINA WEB/BANNER PLATAFORMA WEB PAD-2832.png" alt="Pastora Altagracia" style="width: 100%; height: auto; display: block; border-bottom: 4px solid #E3A113; border-radius: 6px;">
                </div>

                <!-- Document Header -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid #0054A6; padding-bottom: 8px;">
                    <div>
                        <h2 style="margin: 0; font-size: 18px; color: #0054A6; text-transform: uppercase; letter-spacing: 0.5px;">INFORME GENERAL CONSOLIDADO DE REDES Y LIDERAZGOS</h2>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #475569;">Matriz Territorial de Captación Multinivel - Periodo Electoral 2028</p>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 11px; color: #64748b;">Generado:</span>
                        <div style="font-size: 12px; font-weight: bold; color: #0f172a;">${new Date().toLocaleDateString('es-DO')}</div>
                    </div>
                </div>

                <!-- Consolidated Totals Bar -->
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; background: #0b1320; color: #fff; border-radius: 8px; padding: 15px; margin-bottom: 20px; text-align: center;">
                    <div>
                        <span style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: bold;">Votantes Consolidados en Red</span>
                        <h2 style="margin: 4px 0 0 0; font-size: 24px; color: #ffffff;">${totalGeneralRed.toLocaleString()}</h2>
                    </div>
                    <div>
                        <span style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: bold;">Inscripciones Directas</span>
                        <h2 style="margin: 4px 0 0 0; font-size: 24px; color: #10b981;">${totalGeneralDirectos.toLocaleString()}</h2>
                    </div>
                    <div>
                        <span style="font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: bold;">Militantes Líderes (ML) Activos</span>
                        <h2 style="margin: 4px 0 0 0; font-size: 24px; color: #E3A113;">${totalGeneralML.toLocaleString()}</h2>
                    </div>
                </div>

                <!-- Matrix Table -->
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <thead>
                        <tr style="background-color: #0054A6; color: #ffffff; font-size: 11px; text-transform: uppercase;">
                            <th style="padding: 8px; text-align: center; width: 35px;">No.</th>
                            <th style="padding: 8px; text-align: left;">Nombre del Líder / Coordinador</th>
                            <th style="padding: 8px; text-align: left;">Nivel / Rol</th>
                            <th style="padding: 8px; text-align: center;">Directos</th>
                            <th style="padding: 8px; text-align: center;">ML</th>
                            <th style="padding: 8px; text-align: center;">Total en Red</th>
                            <th style="padding: 8px; text-align: center;">Meta</th>
                            <th style="padding: 8px; text-align: center;">% Cumplimiento</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${matrixRowsHtml}
                    </tbody>
                </table>

                <div style="text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #64748b;">
                    <p>Plataforma Electoral ADELOG - Comando de Campaña Pastora Altagracia 2028. Todos los derechos reservados.</p>
                </div>
            </div>
        `;

        const prevTitle = document.title;
        document.title = `PAD2832-INFORME_GENERAL_CONSOLIDADO_${new Date().toISOString().slice(0,10)}`;
        window.print();
        setTimeout(() => {
            document.title = prevTitle;
        }, 1000);
    }

    // ─── EXPORTAR RED A EXCEL / CSV ──────────────────────────────────────────
    function exportNetworkExcel() {
        const coordSelect = document.getElementById('filter-network-coord');
        const selectedCoord = coordSelect ? coordSelect.value : '';
        const levelSelect = document.getElementById('filter-network-level');
        const selectedLevel = levelSelect ? levelSelect.value : 'all';

        let csv = 'No. Lista,Folio Oficial,Cedula,Nombres,Apellidos,Etiqueta Elector,Rol en Red,Colegio,Sector,Municipio,Coordinador Padre\n';
        cachedNetworkVoters.forEach(v => {
            const folio = v.codigo_comprobante || (`PAD2832-${v.numero_lista}-${v.cedula}`);
            const tagStr = v.tipo_elector || (v.es_militante_lider == 1 ? 'Nuevo Elector (ML)' : 'Nuevo Elector');
            const rolStr = (v.es_militante_lider == 1) ? 'ML - Militante Lider' : 'Votante';
            csv += `"${v.numero_lista}","${folio}","${v.cedula}","${v.nombres}","${v.apellidos}","${tagStr}","${rolStr}","${v.colegio_electoral}","${v.sector || ''}","${v.municipio || ''}","${v.coordinador}"\n`;
        });

        const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        const filename = selectedCoord ? `PAD2832_RED_${selectedCoord.replace(/\s+/g, '_')}.csv` : 'PAD2832_RED_TERRITORIAL_GENERAL.csv';
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    // Exponer las funciones de impresión y compartición en el ámbito global
    window.printVoterVoucher = printVoterVoucher;
    window.printNetworkSeccionedPDF = printNetworkSeccionedPDF;
    window.printNetworkGeneralPDF = printNetworkGeneralPDF;
    window.exportNetworkExcel = exportNetworkExcel;
    window.loadRedTerritorial = loadRedTerritorial;
    window.saveCollaborator = saveCollaborator;
    window.triggerNewChat = triggerNewChat;
    window.fillChatModalFields = fillChatModalFields;
    window.exportSingleVoterExcel = exportSingleVoterExcel;
    window.consultarEstatus2024 = consultarEstatus2024;
    window.prefillVoterModalFromQuery = prefillVoterModalFromQuery;
    window.clearActiveChat = clearActiveChat;
    window.sendVoucherEmail = sendVoucherEmail;
    window.shareVoucherWhatsApp = shareVoucherWhatsApp;
    window.shareVoucherTelegram = shareVoucherTelegram;
});

