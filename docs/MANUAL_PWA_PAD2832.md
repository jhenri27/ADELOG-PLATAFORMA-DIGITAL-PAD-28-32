# Manual Oficial de Arquitectura y Operación — PWA PAD-28/32
> **Plataforma de Gestión Electoral Móvil para Coordinadores y Militantes Líderes (ML)**  
> **Campaña Diputada Pastora Altagracia De Los Santos • Santo Domingo Este (Circunscripción 3)**  
> *Normas de Calidad Electoral PLAD v4.0 & Estándar ISO 9001 / NOFTRAB*

---

## 📱 1. Visión General de la PWA

La **PWA PAD-28/32** (`frontend/pwa/`) es una aplicación web progresiva de alto rendimiento diseñada específicamente para dispositivos móviles (**Android e iOS Safari**). Su objetivo primordial es descentralizar la captación electoral en el terreno, permitiendo a los Coordinadores, Sub-coordinadores, Digitadores y Militantes Líderes (ML) realizar inscripciones, consultas padronales y auditorías en tiempo real con o sin cobertura celular estable.

### Pilares Fundamentales
1. **Instalabilidad Nativa (W3C Standalone)**: Añadible a la pantalla de inicio sin pasar por tiendas de aplicaciones, con soporte de *Safe Area Inset* para notch y barra de navegación en iPhones y dispositivos Android modernos.
2. **Resiliencia Offline y Caché Stale-While-Revalidate**: El Service Worker (`sw.js`) almacena los activos estáticos y estilos para carga instantánea (<1 segundo), mientras que las peticiones padronales (`/backend/api/`) consultan en vivo a la base de datos MySQL `pad_electoral_2832`.
3. **Auditoría Local Autónoma por Código QR**: Generación de códigos QR directamente sobre lienzo HTML5 Canvas (`qrcode.min.js`), eliminando al 100% las dependencias de APIs externas susceptibles a fallos de red o bloqueos CORS (*experiencia FAIL-015*).

```
[Dispositivo Móvil Android / iOS]
       │
       ▼
 ┌───────────────┐        ┌──────────────────┐
 │ ServiceWorker │ ◄────► │ Caché Local      │ (UI & Assets Offline)
 └───────┬───────┘        └──────────────────┘
         │
         ▼ (Fetch Seguro Credentials same-origin)
 ┌────────────────────────────────────────────────────────┐
 │ /backend/api/                                          │
 │ ├─ auth.php       (Sesión PHP BCRYPT & Role Scoping)   │
 │ ├─ voters.php     (Luhn Mod 10, Circ. 3 & PLAD-SEC-01) │
 │ └─ padron_lookup  (Padrón Maestro Circunscripción 3)   │
 └────────────────────────────────────────────────────────┘
```

---

## 🛡️ 2. Seguridad y Control de Acceso por Roles (Cláusula PLAD-SEC-01)

Para prevenir filtraciones de información sensible y garantizar la compartimentación de las redes territoriales, el sistema implementa **aislamiento estricto de visibilidad padronal a nivel del servidor (PHP/MySQL)**:

| Perfil / Rol | Alcance de Visibilidad (`voters.php?action=list` y `action=network_voters`) | Meta Asignada |
| :--- | :--- | :--- |
| **Administrador / Coord. General / Jefe Electoral** | **Global:** Puede ver los 19+ votantes de la plataforma completa y todas las redes. | 200+ Electores |
| **Coordinador / Sub-coordinador** | **Red Territorial:** Únicamente ve los electores asignados a su red (`coordinador = $nombre` O `registrado_por = $user_id` O `coordinador_padre_id = $user_id`). | 200 Electores |
| **Digitador** | **Registros Propios:** Únicamente ve los electores que él mismo ingresó (`registrado_por = $user_id`). | 50 Electores |
| **Militante Líder (ML)** | **Célula Personal:** Únicamente ve sus electores y referidos directos (`registrado_por = $user_id` O `referido_por_ml_id = $user_id`). | 25 Electores |

> **Principio de Inviolabilidad:** El backend evalúa la identidad inmutable a partir de `$_SESSION['usuario_id']` y `$_SESSION['role']`, ignorando cualquier parámetro arbitrario enviado desde el cliente web.

---

## ⚙️ 3. Módulo de Perfil, Configuración y Control de Valor

Ubicado en `#perfil` y accesible desde el botón de la tuerca superior (`⚙`), el avatar o la barra de navegación inferior:

### 3.1. Tarjeta de Identidad Oficial
* **Avatar y Código ML:** Icono adaptativo según el rango (Escudo para Administrador, Traje para Coordinador, Chequeo para Militante Líder) con chapa identificadora.
* **Datos del Personal:**
  * Nombre oficial y Rol institucional.
  * Cédula Dominicana formateada con algoritmo Luhn (`000-0000000-0`).
  * Nombre de usuario y Código de Red Asignado (`ML-XXXX` o `USER-X`).
  * Demarcación Geográfica: *Santo Domingo Este • Circunscripción 3 (Pastora Altagracia)*.

### 3.2. Control de Valor Electoral (KPIs en Tiempo Real)
El panel consulta de forma asíncrona la API `voters.php?action=list` para computar y reflejar el rendimiento electoral:
* **Electores Aportados:** Total de ciudadanos inscritos por el usuario en el Padrón Activo 2028.
* **Líderes en Red (ML):** Conteo de militantes multiplicadores captados en su estructura.
* **Barra de Progreso:** Porcentaje de cumplimiento respecto a la cuota electoral asignada (25 electores para ML).
* **Nivel Gamificado de Liderazgo:**
  * 🥉 **Nivel Bronce (Iniciador de Red):** 0 a 9 electores aportados.
  * 🥈 **Nivel Plata (Crecimiento Activo):** 10 a 19 electores aportados.
  * 🥇 **Nivel Oro (Liderazgo Consolidado):** 20 a 24 electores aportados.
  * 💎 **Nivel Diamante (Meta Cumplida):** 25+ electores aportados con reconocimiento y corona dorada.

---

## 🔗 4. Enlaces Oficiales de Captación y Auto-Aprovisionamiento

El módulo de configuración provee dos enlaces únicos y permanentes vinculados al código del usuario (`refCode`):

```
                               ┌──────────────────────────────────────────────────────────┐
                               │   Usuario Logueado en PWA (Ej: ML-0007 / Ana Gómez)     │
                               └─────────────┬──────────────────────────────┬─────────────┘
                                             │                              │
                    Enlace Simpatizante      │                              │ Enlace Militante Líder
             (registro.php?canal=...&ref=ML-0007)                           │ (registro.php?tipo=ml&canal=...&ref=ML-0007)
                                             │                              │
                                             ▼                              ▼
                             ┌────────────────────────┐    ┌──────────────────────────────────┐
                             │ Formulario Público     │    │ Formulario con Banner Dorado ML  │
                             │ (Votante General)      │    │ (Inscripción de Nuevo Líder)     │
                             └───────────┬────────────┘    └────────────────┬─────────────────┘
                                         │                                  │
                                         ▼                                  ▼
                             ┌────────────────────────┐    ┌──────────────────────────────────┐
                             │ Registro en inscritos  │    │ 1. Registro en inscritos (es_ml=1│
                             │ (tipo: Nuevo Elector)  │    │ 2. Creación en tabla usuarios    │
                             │ Atribuido a ML-0007    │    │ 3. Generación de código ML-XXXX  │
                             └────────────────────────┘    │ 4. Clave BCRYPT = Cédula         │
                                                           │ 5. ¡Acceso Inmediato a la PWA!   │
                                                           └──────────────────────────────────┘
```

### 4.1. Enlace 1: Militante Simpatizante (Votante General)
* **URL:** `.../registro.php?canal=pwa_simpatizante&ref=ML-XXXX`
* **Destino:** Diseñado para inscribir votantes del sector, familiares y vecinos de los recintos de la Circunscripción 3.
* **Herramientas de Compartir:** Botón Copiar (portapapeles con Toast), Botón WhatsApp (con mensaje personalizado preformateado) y Botón Ver QR (para escanear en operativos).

### 4.2. Enlace 2: Militante Líder (ML) con Capacidad de Liderazgo
* **URL:** `.../registro.php?tipo=ml&canal=red_ml&ref=ML-XXXX`
* **Mecanismo de Auto-Aprovisionamiento:**
  1. Al abrir el enlace, el portal detecta `tipo=ml` y presenta el banner dorado de liderazgo.
  2. Al enviar la cédula y completar la inscripción, `voters.php` asigna `$esML = 1` y crea automáticamente el usuario en la tabla `usuarios`:
     * Genera el siguiente código secuencial `ML-XXXX` (ej. `ML-0008`).
     * Asigna como usuario `ml_<ultimos_6_digitos_cedula>` y contraseña la cédula (cifrada con BCRYPT).
     * Otorga permisos para crear y consultar votantes (`can_create = 1, can_view = 1`).
  3. El nuevo militante líder puede abrir inmediatamente `http://.../frontend/pwa/` e iniciar sesión para empezar a coordinar su propia red.

---

## 📋 5. Credenciales Sembradas de Prueba

| Perfil | Usuario | Contraseña | Código ML | Visibilidad Padronal |
| :--- | :--- | :--- | :--- | :--- |
| **Administrador** | `admin` | `admin123` | N/A | Padrón Completo (19+ electores) |
| **Digitador** | `digitador1` | `digitador123` | N/A | Electores Propios (11 electores) |
| **Coordinador** | `coordinador1` | `coordinador123` | N/A | Red Asignada |
| **Jefe Electoral** | `jefe1` | `jefe123` | N/A | Demarcación Completa |
| **Militante Líder (Ejemplo)** | `ML-0007` o `001-0000007-7` | `00100000077` | `ML-0007` | Célula Personal de 25 |

---

## 💾 6. Procedimiento de Respaldo Físico y Google Drive

La plataforma cuenta con el comando oficial unificado de respaldo **`backup-adelog`** (`backup-adelog.bat` / `backup-adelog.ps1`):

1. **Volcado MySQL:** Genera el archivo `.sql` atómico de `pad_electoral_2832` y lo comprime en `.zip`.
2. **Kit de Instalación:** Genera el script `restaurar_base_de_datos.bat` para restauración en 1 clic.
3. **Sincronización Física F:\\:** Reclona el código íntegro a `F:\ADELOG\PLATAFORMA DIGITAL-PAD-28-32-backup\`.
4. **Paquetes ZIP:** Empaqueta `PAD2832_BACKUP_TOTAL_UNIFICADO_LATEST.zip` en la carpeta `LISTO_PARA_GOOGLE_DRIVE`.
5. **Git Push:** Sincroniza atómicamente con el repositorio remoto de GitHub (`origin main`).
6. **Google Drive:** Actualiza el acceso directo hacia la carpeta oficial de Google Drive.
