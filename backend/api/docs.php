<?php
/**
 * API: Documentación Informativa General y Manual de Operaciones
 * PAD/28-32 - Plataforma Electoral
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

// Validar inicio de sesión
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(["exito" => false, "mensaje" => "No autorizado. Inicie sesión."]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["exito" => false, "mensaje" => "Método no permitido."]);
    exit;
}

// Base de conocimiento estructurada actualizada
$documentos = [
    [
        "id" => 1,
        "titulo" => "1. Escaneo OCR con Visión Artificial y Validación de Identidad",
        "categoria" => "Captación Digital",
        "contenido" => "El sistema integra Google Cloud Vision API para la extracción automatizada de datos impresos en la Cédula de Identidad y Electoral dominicana.\n\n" .
                       "**Instrucciones para un escaneo exitoso:**\n" .
                       "1. Tome la fotografía con iluminación uniforme, evitando sombras o reflejos directos en el plástico.\n" .
                       "2. Coloque el documento en posición horizontal y centre el lente.\n" .
                       "3. El motor OCR analiza la cara frontal (Nombres, Apellidos y Cédula) y el reverso (Colegio Electoral, Municipio, Sector y Dirección Residencial).\n" .
                       "4. Cada número de cédula es auditado automáticamente mediante el **Algoritmo de Luhn (Mod 10)** para garantizar su validez estructural antes de registrarse.",
        "icon" => "fa-camera"
    ],
    [
        "id" => 2,
        "titulo" => "2. Padrón de Consulta y Validación Territorial (Circunscripción 3)",
        "categoria" => "Padrón Electoral",
        "contenido" => "La plataforma cuenta con el **Padrón Máster Integrado de la 3ra. Circunscripción (42,739 Votantes)** abarcando Santo Domingo Este, San Luis, Boca Chica, San José de Mendoza, El Tamarindo, Invivienda, Brisas del Este, Los Frailes y sectores aledaños.\n\n" .
                       "**Funcionalidades del Padrón:**\n" .
                       "1. **Autocompletado en Tiempo Real:** Al digitar los 11 números de la cédula en el formulario de inscripción, el sistema consulta el padrón máster y llena de forma instantánea: Nombres, Apellidos, Sector, Municipio, Recinto y Zona.\n" .
                       "2. **Verificación de Duplicados:** Muestra una alerta visual instantánea indicando si el votante pertenece a la Circunscripción 3 y si ya fue inscrito previamente por otro coordinador.\n" .
                       "3. **Estadísticas de Penetración:** Monitoreo en tiempo real del nivel de avance por recintos (ej: Inst. Elda Reyes Muñoz, Prof. Manuel B. Troncoso) y sectores de influencia.",
        "icon" => "fa-database"
    ],
    [
        "id" => 3,
        "titulo" => "3. Administración de Perfiles y Accesos Granulares (RBAC)",
        "categoria" => "Seguridad y Accesos",
        "contenido" => "El sistema implementa el estándar **NIST RBAC 2.0 & ISO 27001** para la administración de roles de usuarios y control de accesos atómicos.\n\n" .
                       "**Componentes del Módulo de Perfiles:**\n" .
                       "1. **Malla de Permisos Atómica (10 Dimensiones):** Permite activar o desactivar permisos específicos por módulo (*Ejecutar, Ver Datos, Crear, Editar, Eliminar, Reportes, Exportar, Importar, Imprimir y Solo Propios*).\n" .
                       "2. **Nivel Jerárquico de Seguridad (1-10):** Define la jerarquía de cada perfil (*Administrador, Gerente Electoral, Jefe Electoral, Coordinador Regional, Digitador*).\n" .
                       "3. **Clonación y Copia de Mallas:** Permite duplicar la malla completa de permisos desde un perfil plantilla hacia nuevos perfiles con 1 clic.\n" .
                       "4. **Interfaz 100% Responsiva y Dual Theme:** Diseño adaptativo para dispositivos móviles, tablets y computadoras de escritorio, compatible con temas oscuro (Dark) y claro (Light).",
        "icon" => "fa-user-shield"
    ],
    [
        "id" => 4,
        "titulo" => "4. Captación Autónoma vía Bot de WhatsApp",
        "categoria" => "Automatización Bot",
        "contenido" => "La plataforma cuenta con un Bot de WhatsApp interactivo para la autocaptación de simpatizantes en tiempo real.\n\n" .
                       "**Flujo conversacional:**\n" .
                       "1. El ciudadano escribe 'Hola' o 'Inscribirme' al WhatsApp oficial de campaña.\n" .
                       "2. El bot solicita el número de cédula y verifica su validez y pertenencia al padrón.\n" .
                       "3. El bot solicita confirmación de datos, número celular y nombre del coordinador referente.\n" .
                       "4. Se emite un comprobante interactivo de inscripción con su número de folio en la lista oficial.",
        "icon" => "fa-comments"
    ],
    [
        "id" => 5,
        "titulo" => "5. Enlaces QR de Campañas Masivas y Atribución",
        "categoria" => "Marketing Electoral",
        "contenido" => "Generador de código QR y enlaces personalizados para la captación digital a través de redes sociales (Facebook, Instagram, WhatsApp, Banners).\n\n" .
                       "**Métricas de seguimiento:**\n" .
                       "- Conteo automatizado de clics recibidos por enlace de campaña.\n" .
                       "- Registro de inscripciones efectivas atribuidas a cada coordinador o canal difusor.",
        "icon" => "fa-qrcode"
    ],
    [
        "id" => 6,
        "titulo" => "6. Mesa de Ayuda e Incidencias Logísticas (Helpdesk)",
        "categoria" => "Soporte Técnico",
        "contenido" => "Centraliza las solicitudes de asistencia técnica, fallas de dispositivos y faltantes de material de campo durante la jornada electoral.\n\n" .
                       "**Gestión de Tickets:**\n" .
                       "- Creación de tickets por nivel de prioridad ('Baja', 'Media', 'Alta', 'Urgente').\n" .
                       "- Actualización en vivo del estado del ticket ('Pendiente', 'En Proceso', 'Resuelto').",
        "icon" => "fa-ticket-alt"
    ],
    [
        "id" => 7,
        "titulo" => "7. Recursos Descargables y Formularios Físicos",
        "categoria" => "Descargas",
        "contenido" => "Descarga de material oficial de trabajo para promotores y digitadores de centro de acopio:\n\n" .
                       "- **Formulario Físico de Captación (PDF/HTML)**: Plantilla impresa para recolección de simpatizantes en mano.\n" .
                       "- **Manual del Promotor Electoral (PDF/HTML)**: Guía oficial de lineamientos de campaña de la candidata Pastora Altagracia De Los Santos.\n" .
                       "- **Kit de Recursos de Marca (ZIP)**: Banners publicitarios y logotipos oficiales.",
        "icon" => "fa-download"
    ]
];

echo json_encode(["exito" => true, "documentos" => $documentos], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
