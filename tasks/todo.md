# Tareas: Consulta de Estatus Elector - Padrón Histórico Partido vs Padrón Candidata (Normas PLAD)

## Diagnóstico, Causa Raíz y Auditoría Cuantitativa
- [x] Identificar tablas de datos: `padron_maestro_consulta` (330,774 electores Circ. 3 PRM), `padron_consulta_circ3` (42,739 electores Circ. 3), e `inscritos` (padrón activo de la candidata).
- [x] Detectar discrepancia de collation entre `padron_consulta_circ3` (`utf8mb4_0900_ai_ci`) y `padron_maestro_consulta` / `inscritos` (`utf8mb4_unicode_ci`) que causaba fallo SQLSTATE 1267.
- [x] Detectar causa raíz del fallo en `action=query_2024`: Buscaba únicamente en `inscritos WHERE periodo = '2024'`, tabla que solo contiene registros del período 2028 y no consultaba el padrón maestro del partido ni cruzaba con el padrón actual de la candidata.
- [x] Establecer normas formales en `PLAD (PLANIFICADOR POR DEFECTO).txt`:
  - **Norma PLAD-REL-INGESTA-01** (Separación de Universos, Etiquetado Obligatorio de Nuevo Elector, Restricción UNIQUE Universal sin distinción de perfiles, y Diagnóstico Dual de 4 Estados).
  - **Norma PLAD-CERT-QR-01** (Certificación y Validación Digital por Código QR en Comprobantes con Data Sincronizada).
  - **Norma PLAD-ENTREGABLES-SYNC-01** (Paridad y Sincronización Total de los 8 Entregables Oficiales).
- [x] Auditoría de Porcentajes de Implementación:
  - *Módulo Consulta de Estatus Elector:* **0% Sin Error | 100% Con Error** (Inoperante actualmente al buscar periodo 2024 inexistente).
  - *Comprobantes con Código QR de Validación Sincronizada:* **0% Sin Error | 100% Con Error** (Inexistente actualmente en `comprobante.php` y `app.js`).
  - *Entregables con Paridad de Nuevos Campos:* **20% Sin Error | 80% Con Error/Desactualizados**.
  - *Ecosistema Global de Ingesta y Datos:* **50% Sin Error | 50% Con Error/Desviación**.

## Fase 1: Optimización y Estandarización de Base de Datos (Normas PLAD)
- [x] 1.1 Homogeneizar collation de `padron_consulta_circ3` a `utf8mb4_unicode_ci` para unificar índices con `padron_maestro_consulta` e `inscritos`.
- [x] 1.2 Agregar/validar columna `tipo_elector` VARCHAR(50) DEFAULT 'Nuevo Elector' en `inscritos`.
- [x] 1.3 Verificar y blindar la restricción `UNIQUE (cedula)` en `inscritos` a nivel de motor InnoDB.

## Fase 2: Rediseño del Motor Backend y Validación QR
- [x] 2.1 Actualizar el handler `action=query_2024` para realizar la consulta dual cruzada:
  - Búsqueda en el Padrón del Partido de la Circunscripción 3 (`padron_maestro_consulta` con fallback a `padron_consulta_circ3`).
  - Cruce en tiempo real con el Padrón Actual de la Candidata (`inscritos` período actual).
  - Diagnóstico de 4 Estados con etiqueta clara de `Nuevo Elector Registrado`.
- [x] 2.2 Blindar la restricción UNIQUE universal en `voters.php` (acción `create` y edición):
  - Validación estricta que aplica a todos los perfiles (Administrador, Coordinador General, Digitador, Promotor).
  - Respuesta HTTP 409 con detalles de quién y cuándo registró al elector.
- [x] 2.3 Crear endpoint / vista pública de validación QR `validar.php` (Entregable 3):
  - Recibe `cedula` o `folio`.
  - Cruza en tiempo real `inscritos` con `padron_maestro_consulta`.
  - Despliega: Sello de Auditoría PLAD, Etiquetas de elector, Lugar/posición en ambos padrones, Perfil responsable y Fecha de ingesta.

## Fase 3: Paridad y Sincronización Total de Entregables (Norma PLAD-ENTREGABLES-SYNC-01)
- [x] 3.1 **Entregable 1: Comprobante Web (`comprobante.php`):**
  - Inyectar código QR dinámico de validación apuntando a `validar.php`.
  - Desplegar Folio `PAD2832-X-Y`, Etiquetas de Elector (Vigente/Antiguo Partido, Nuevo Elector Candidata), Posición en ambos padrones, Perfil responsable y Fecha de ingesta.
- [x] 3.2 **Entregable 2: Voucher Impreso en Panel (`printVoterVoucher` en `app.js`):**
  - Sincronizar paridad con `comprobante.php`, incluyendo código QR dinámico y datos enriquecidos.
- [x] 3.3 **Entregable 4: Exportación General en Excel (`action=export_excel` en `voters.php`):**
  - Añadir columnas: Folio, Etiqueta Elector, Estatus Partido PRM, Colegio, Recinto JCE, Posición Recinto, Coordinador, Registrado Por, Canal Origen, Periodo y Fecha Ingesta.
- [x] 3.4 **Entregable 5: Exportación General en PDF (`action=export_pdf_2024` en `voters.php`):**
  - Actualizar tabla imprimible con columnas sincronizadas, estatus de ambos padrones y espacio de firma para día D.
- [x] 3.5 **Entregable 6: Padrones Seccionados de Red Territorial (`printNetworkSeccionedPDF` y `exportNetworkExcel` en `app.js`):**
  - Incluir Folio, Rol/Etiqueta Elector, Colegio/Recinto JCE y Coordinador.
- [x] 3.6 **Entregable 7: Exportación Individual en Excel (`exportSingleVoterExcel` en `app.js`):**
  - Actualizar ficha CSV individual con todos los nuevos datos.
- [x] 3.7 **Entregable 8: Tarjeta de Consulta de Estatus en Dashboard (`dashboard.html` / `app.js`):**
  - Actualizar encabezados, alertas ejecutivas, badges de estado y botón de captación inmediata `+ Registrar como Nuevo Elector`.

## Fase 4: Pruebas y Verificación Rigurosa
- [x] 4.1 Probar cédula que esté en padrón del partido e inscrita con la candidata (debe mostrarse con etiqueta "Nuevo Elector").
- [x] 4.2 Probar cédula que esté en padrón del partido pero NO inscrita con la candidata.
- [x] 4.3 Probar cédula que no esté en ninguna de las dos bases de datos.
- [x] 4.4 Probar intento de duplicado desde cuenta Administrador y cuenta Coordinador para verificar que UNIQUE bloquea a todos los perfiles por igual.
- [x] 4.5 Escanear/abrir el enlace del código QR del comprobante y verificar que `validar.php` despliega todos los datos solicitados: etiquetas, posiciones en ambos padrones, perfil responsable y fecha de ingesta.
- [x] 4.6 Verificar que cada uno de los 8 entregables (Excel, PDF, Vouchers, Pantallas) presente los nuevos campos sincronizados sin omisiones.
- [x] 4.7 Demostrar ausencia de errores SQL y respuesta rápida en consola/red.

## Fase 5: Corrección Específica Cédula 001-1423642-5 y Optimización de Impresión QR (1 Página)
- [x] 5.1 Actualización de datos de elector `001-1423642-5` con la nueva cédula física escaneada:
  - Nombre: `JOSE ANDERSON HENRIQUEZ MARTE`
  - Colegio: `1823` | Recinto: `CENTRO EDUCATIVO ISABELITA LA, ISABELITA`
  - Dirección: `10 CASA 51, EDIF. JEFRY I PISO 4 APTO 4-A`
  - Sector: `ISABELITA` | Municipio: `SANTO DOMINGO ESTE`
  - Demarcación: `Circunscripción 1 (Santo Domingo Este)` — Identificado como Simpatizante Externo y multiplicador de votos para Pastora Altagracia (Circ. 3).
- [x] 5.2 Corrección del orden y superposición del Código QR en el comprobante:
  - Estructuración en tabla rígida de 2 columnas (68% Datos / 32% QR).
  - Eliminación de solapamiento del texto "VALIDACIÓN EN TIEMPO REAL" y del subtítulo normativo.
  - Conversión de canvas a imagen pura DataURL para compatibilidad total con motores de impresión y previsualización.
- [x] 5.3 Garantía estricta de 1 sola página impresa (Eliminación de "Total: 2 páginas"):
  - Ajuste de márgenes y paddings verticales para una altura total de ~517px (~136mm).
  - Configuración `@page { size: portrait; margin: 6mm 8mm; }` y `page-break-inside: avoid !important`.
- [x] 5.4 Sincronización en `validar.php` y `comprobante.php`:
  - Despliegue de etiqueta de Simpatizante Externo (Circunscripción 1 - Santo Domingo Este) y dirección exacta de la cédula física.
- [x] 5.5 Optimización de Ahorro de Tinta en Impresión / PDF (Norma PLAD):
  - Sustitución del fondo oscuro pesado (`#0b1320`) de la tarjeta del volante por fondo blanco limpio (`#ffffff` / `#f8fafc`).
  - Transformación de la tipografía blanca a negro puro (`#000000` / `#0f172a`) con etiquetas en gris pizarra (`#475569`) y acentos corporativos en azul (#0054A6) y verde esmeralda (#059669).
  - Reducción drástica del consumo de tóner/tinta durante la impresión masiva de volantes y comprobantes oficiales.

## Fase 6: Sincronización Integral del Enlace Oficial de Instagram (Normas PLAD)
- [x] 6.1 Actualización en Base de Datos MySQL (`pad_electoral_2832`):
  - Actualizar `social_instagram_url` en `configuraciones` a `https://www.instagram.com/pastoraaltagraciard/` (1 fila afectada).
  - Actualizar `enlace_publicacion` en `redes_sociales_feed` para publicaciones de Instagram (2 filas afectadas: Posts ID 1 y 3).
- [x] 6.2 Paridad en Frontend (Portal Público y Dashboard):
  - Actualizar `#btn-social-instagram` en `frontend/index.html` con `href="https://www.instagram.com/pastoraaltagraciard/"`.
  - Actualizar placeholder en `frontend/dashboard.html` con `https://www.instagram.com/pastoraaltagraciard/`.
- [x] 6.3 Paridad en Backend y Scripts de Migración:
  - Actualizar fallback de API en `backend/api/social_feed.php`.
  - Actualizar configuración por defecto y semillas en `backend/migracion_redes_sociales.php`.
- [x] 6.4 Verificación y Demostración Cuantitativa PLAD:
  - Verificación SQL ejecutada: `configuraciones` y `redes_sociales_feed` actualizadas.
  - Verificación API ejecutada: `social_feed.php?action=get_feed` retorna JSON con `perfiles.instagram = https://www.instagram.com/pastoraaltagraciard/` y enlaces en publicaciones actualizados.
  - Verificación estática ejecutada: 0 instancias de la URL antigua en todo el repositorio.
- [x] 6.5 Cierre del Bucle de Automejora:
  - Lección 07 registrada en `tasks/lessons.md`.
  - Fase 6 auditada y documentada en `tasks/todo.md`.

## Fase 7: Sincronización Integral del Enlace Oficial de Facebook (Normas PLAD)
- [x] 7.1 Actualización en Base de Datos MySQL (`pad_electoral_2832`):
  - Actualizar `social_facebook_url` en `configuraciones` a `https://www.facebook.com/Apostolaltagraciard` (1 fila afectada).
  - Actualizar `enlace_publicacion` en `redes_sociales_feed` para publicaciones de Facebook (1 fila afectada: Post ID 2).
- [x] 7.2 Paridad en Frontend (Portal Público y Dashboard):
  - Actualizar `#btn-social-facebook` en `frontend/index.html` con `href="https://www.facebook.com/Apostolaltagraciard"`.
  - Actualizar placeholder en `frontend/dashboard.html` con `https://www.facebook.com/Apostolaltagraciard`.
- [x] 7.3 Paridad en Backend y Scripts de Migración:
  - Actualizar fallback de API en `backend/api/social_feed.php`.
  - Actualizar configuración por defecto y semillas en `backend/migracion_redes_sociales.php`.
- [x] 7.4 Verificación y Demostración Cuantitativa PLAD:
  - Verificación SQL ejecutada: `configuraciones` y `redes_sociales_feed` actualizadas.
  - Verificación API ejecutada: `social_feed.php?action=get_feed` retorna JSON con `perfiles.facebook = https://www.facebook.com/Apostolaltagraciard` y enlace de publicación en Facebook corregido.
  - Verificación estática ejecutada: 0 instancias de `facebook.com/pastoraaltagraciard` en todo el repositorio.
- [x] 7.5 Cierre del Bucle de Automejora:
  - Lección 07 actualizada en `tasks/lessons.md`.
  - Fase 7 auditada y documentada en `tasks/todo.md`.

## Fase 8: Estandarización de Campos de Formulario — Remoción de Placeholder 1401 (Normas PLAD)
- [x] 8.1 Corrección en Portal Público (`frontend/index.html`):
  - `placeholder="1401"` removido del input `#public-colegio_electoral`. Campo ahora 100% limpio y en blanco como Nombres, Apellidos, Sector y Municipio.
- [x] 8.2 Corrección en Panel Administrativo (`frontend/dashboard.html`):
  - `placeholder="1401"` removido del input `#colegio_electoral` en el modal de captura del dashboard.
- [x] 8.3 Verificación y Demostración Cuantitativa PLAD:
  - Búsqueda estática ejecutada: 0 ocurrencias de `placeholder="1401"` en todo el directorio frontend.
- [x] 8.4 Cierre del Bucle de Automejora:
  - Lección 08 registrada en `tasks/lessons.md`.
  - Fase 8 documentada y auditada en `tasks/todo.md`.

## Fase 9: Comando Oficial de Respaldo Automatizado `backup-adelog` / `bkadelog` (Normas PLAD)
- [x] 9.1 Diseño y Creación del Script de Respaldo Maestro (`backup-adelog.ps1`):
  - Detección automática de WampServer MySQL `mysqldump.exe` y unidad `F:\`.
  - Volcado íntegro de base de datos `pad_electoral_2832` (.sql y .sql.zip).
  - Compilación automática del Kit de Instalación Autónomo con script 1-clic `restaurar_base_de_datos.bat` y guía rápida.
  - Sincronización espejo de código fuente en `F:\ADELOG\PLATAFORMA DIGITAL-PAD-28-32-backup\`.
  - Empaquetado comprimido ZIP unificado (Plataforma, Kit + DB, y Paquete Total).
  - Integración Git: `git add -A`, commit estructurado y `git push origin main`.
  - Asistente interactivo para Google Drive con acceso directo a carpeta en la nube y apertura del directorio de carga.
- [x] 9.2 Creación de Wrappers Globales (`backup-adelog.bat` y `bkadelog.bat`):
  - Instalados en `C:\wamp64\www\PLATAFORMA DIGITAL-PAD-28-32\`.
  - Instalados en `C:\Users\jhenr\AppData\Roaming\npm\` (reconocidos globalmente en el PATH de Windows).
- [x] 9.3 Ejecución del Respaldo Automatizado (`backup-adelog`):
  - Ejecutado exitosamente con código de salida 0.
  - Volcado SQL generado: 81.93 MB (comprimido a 13.07 MB).
  - Paquetes ZIP consolidados en F:\: Paquete Total Unificado 80.1 MB.
  - Kit de instalación compilado con `restaurar_base_de_datos.bat`.
  - GitHub origin main sincronizado (Commit 5f7151c: 30 archivos actualizados).
  - Paquete para Google Drive preparado en `F:\ADELOG\PLATAFORMA DIGITAL-PAD-28-32-backup\LISTO_PARA_GOOGLE_DRIVE\`.

## Sección de Revisión y Lecciones Aprendidas
- [x] Documentar resultados finales en `tasks/todo.md` y verificar actualización en `tasks/lessons.md`.
- [x] Auditoría final de no-regresión y cumplimiento estricto de la Norma de Paridad PLAD-ENTREGABLES-SYNC-01.



