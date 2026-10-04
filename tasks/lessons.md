# Registro de Lecciones Aprendidas y Directrices (Normas PLAD) - PAD/28-32

## Lección 01: Dualidad de Fuentes de Datos (Padrón del Partido vs Padrón de la Candidata)
- **Patrón:** Las plataformas de campaña electoral dominicanas (especialmente bajo estructuras del PRM en Santo Domingo Este Circ. 3) manejan dos universos de datos complementarios:
  1. *El Padrón General/Maestro del Partido en la Demarcación* (`padron_maestro_consulta` / `padron_consulta_circ3`), que contiene el universo total de electores y militantes de la circunscripción 3 con sus colegios y recintos oficiales.
  2. *El Padrón de Simpatizantes de la Candidata* (`inscritos`), que contiene exclusivamente los electores captados y comprometidos por la red de coordinadores de la candidata para el período de contienda actual.
- **Regla:** Ninguna consulta de estatus de elector debe asumir que un votante no existe solo porque no figura en la tabla de `inscritos`. Se debe consultar primero el padrón oficial del partido para determinar su existencia y habilitar su estatus legal/electoral, y luego cruzar con el padrón de la candidata para saber si ya fue captado o si es un objetivo de captación pendiente.

## Lección 02: Homogeneidad de Collation e Índices en Bases de Datos MySQL
- **Patrón:** El uso de tablas con intercalaciones mixtas (`utf8mb4_0900_ai_ci` vs `utf8mb4_unicode_ci`) provoca fallos `SQLSTATE[HY000]: 1267 Illegal mix of collations` al ejecutar subconsultas o `JOIN` entre cédulas.
- **Regla:** Toda tabla de padrón debe tener estandarizado `COLLATE=utf8mb4_unicode_ci` e índices `UNIQUE` o `INDEX` en la columna `cedula` para garantizar operaciones relacionales seguras y en submilisegundos sobre cientos de miles de registros.

## Lección 03: Relación Lógica entre Canales de Ingesta y Consultas (Norma PLAD-REL-INGESTA-01)
- **Patrón:** Múltiples canales de ingesta (Formulario Web, Manual en Panel, OCR, WhatsApp Bot, QR Campaign, Motor ETL) no deben trabajar como silos aislados ni guardar recintos o colegios arbitrarios.
- **Regla:** Todo canal de captura debe tener como referencia previa el Padrón Maestro (`padron_maestro_consulta`). Al captar una cédula, los datos oficiales del colegio y recinto JCE deben ser heredados del maestro, y la tabla `inscritos` debe centralizar el estatus con la candidata, registrando siempre el `canal_origen` y el `coordinador`. La consulta de estatus debe ser siempre relacional y cruzada entre ambas capas, diagnosticando el estado político del ciudadano con precisión quirúrgica.

## Lección 04: Universalidad de la Restricción UNIQUE y Etiquetado de Nuevos Electores
- **Patrón:** Si un perfil de alto rango (como Administrador) tiene bypass de validación o no se muestra el coordinador previo al duplicar, se desvirtúa el padrón y se inflan metas artificialmente. Además, la ausencia de una etiqueta explícita de "Nuevo Elector" confunde el estatus de captación con el histórico partidario.
- **Regla:** La restricción UNIQUE en `cedula` aplica a TODOS los perfiles por igual sin distinción de jerarquía. Cada votante ingresado al padrón activo debe quedar rotulado explícitamente como `Nuevo Elector` (o `Nuevo Elector - ML Líder`) tanto en la base de datos como en los elementos visuales del sistema.

## Lección 05: Certificación y Auditoría Digital por Código QR (PLAD-CERT-QR-01)
- **Patrón:** Los comprobantes físicos o digitales en formato PDF que carecen de código QR o cuyos QR apuntan a enlaces rotos o sin datos sincronizados pierden valor legal y probatorio en las mesas electorales.
- **Regla:** Todo comprobante debe incrustar un código QR de alta resolución apuntando a `validar.php`. Dicho endpoint debe consultar en tiempo real las bases de datos sincronizadas y presentar: 1) Etiquetas de Elector (Militante vigente/antiguo, Nuevo elector), 2) Posición en ambos padrones (Partido y Candidata Pastora), 3) Perfil responsable y canal de origen, y 4) Fecha y hora exacta de ingesta.

## Lección 06: Paridad y Sincronización Total de Entregables (Norma PLAD-ENTREGABLES-SYNC-01)
- **Patrón:** Modificar la lógica de datos y agregar nuevos campos en el backend o en la pantalla pero omitirlos en los reportes descargables (Excel, PDF, Vouchers) genera asimetría de información y desconfianza en los coordinadores y delegados de mesa.
- **Regla:** Todo nuevo campo (Etiquetas de elector, Posición en padrones, Perfil responsable, Fecha de ingesta, Código QR) DEBE ser propagado de forma obligatoria y simultánea a la totalidad de los 8 entregables del proyecto (Comprobante Web, Voucher Impreso, Validar QR, Excel General, PDF General, Padrones Seccionados de Red, Exportación Individual y Buscador en Dashboard).

## Lección 07: Paridad y Persistencia Unificada de Enlaces Oficiales de Redes Sociales de Campaña
- **Patrón:** La dispersión de URLs institucionales o de redes sociales entre la base de datos MySQL, el código HTML estático del portal, los endpoints de backend con fallbacks desacoplados y los scripts de migración provoca que la actualización en una sola capa deje enlaces rotos (ej. Instagram `pastoraaltagracia` vs `pastoraaltagraciard`, o Facebook `pastoraaltagraciard` que muestra "Contenido no disponible" vs `Apostolaltagraciard`) en botones o publicaciones del feed.
- **Regla:** Cualquier actualización de canales o perfiles oficiales de la candidata/campaña debe aplicarse de forma holística y sincronizada (PLAD-ENTREGABLES-SYNC-01):
  1. En la base de datos (`configuraciones` y tablas de publicaciones `redes_sociales_feed`).
  2. En el frontend estático y placeholders de formularios (`index.html` y `dashboard.html`).
  3. En los endpoints REST/API (`social_feed.php`) como contingencia predeterminada.
  4. En los scripts de migración/semillas (`migracion_redes_sociales.php`) para evitar que futuros despliegues reintroduzcan valores obsoletos.

## Lección 08: Estandarización de Campos de Formulario sin Placeholders Numéricos Arbitrarios
- **Patrón:** Colocar ejemplos numéricos o códigos fijos (ej. `placeholder="1401"`) en campos de entrada como "Colegio Electoral" genera confusión operativa tanto en los ciudadanos en el portal público como en los digitadores/coordinadores en el panel de registro, haciéndoles creer que el campo ya viene preseleccionado o que ese es el valor por defecto.
- **Regla:** Los campos geoelectorales y padronales deben presentarse limpios y en blanco (`placeholder=""`), manteniendo consistencia visual con los demás campos del formulario (Nombres, Apellidos, Sector, Municipio). El valor del colegio debe ser ingresado libremente por el usuario o autocompletado en caliente tras la consulta algorítmica al Padrón Maestro.

## Lección 09: Automatización Tripartita de Respaldo Electoral (Comando backup-adelog)
- **Patrón:** Los respaldos manuales dispersos entre terminales y carpetas locales tienden a omitir componentes críticos (la base de datos queda desactualizada respecto al código, o los commits de Git no reflejan los últimos archivos locales).
- **Regla:** Se debe contar con un comando maestro unificado (`backup-adelog` / `bkadelog`) ejecutable desde cualquier consola que orqueste atómicamente: 1) Volcado MySQL completo con compresión, 2) Compilación del Kit de Instalación con script de restauración de 1 clic, 3) Sincronización espejo a almacenamiento físico externo (`F:\ADELOG`), 4) Commit y push automático a GitHub, y 5) Preparación del paquete comprimido consolidado para Google Drive.





