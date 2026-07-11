<?php
/**
 * API: Documentación Informativa General
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

// Retornar la base de conocimiento estructurada
$documentos = [
    [
        "id" => 1,
        "titulo" => "1. Funcionamiento del Escaneo OCR de Cédulas",
        "categoria" => "Guías de Uso",
        "contenido" => "El sistema utiliza Google Cloud Vision API para extraer de forma automática los datos impresos de los documentos de identidad dominicanos (Cédula).\n\n" .
                       "**Instrucciones para un escaneo exitoso:**\n" .
                       "1. Tome la foto en un ambiente bien iluminado, evitando reflejos y sombras sobre el plástico.\n" .
                       "2. Coloque el documento en posición horizontal y centre el lente.\n" .
                       "3. El sistema procesa de forma inteligente el frontal de la cédula para extraer el nombre y cédula, y el reverso para extraer el colegio electoral, dirección de residencia, sector y municipio.\n" .
                       "4. Si hay textos borrosos, el sistema autocompletará lo que reconozca y usted podrá corregir o rellenar el resto de los campos de forma manual.",
        "icon" => "fa-camera"
    ],
    [
        "id" => 2,
        "titulo" => "2. Instrucciones para Inscripciones Autónomas vía Bot de WhatsApp",
        "categoria" => "Guías de Uso",
        "contenido" => "La campaña cuenta con un Bot de WhatsApp en el número oficial para la captación autónoma de votantes.\n\n" .
                       "**Flujo de inscripción para votantes:**\n" .
                       "1. El votante escribe 'hola' o 'inscribirme' al WhatsApp.\n" .
                       "2. El bot le da la bienvenida y le solicita ingresar su número de cédula (11 dígitos).\n" .
                       "3. El bot ejecuta la fórmula matemática del dígito verificador y busca si ya está registrada. Si está libre, consulta el nombre oficial y le pide al votante confirmar.\n" .
                       "4. Luego solicita su número telefónico de celular y su dirección.\n" .
                       "5. Finalmente, solicita el nombre del coordinador que lo refirió y emite una confirmación de inscripción completa con su número de lista.\n\n" .
                       "Si un *Coordinador* escribe al bot, el sistema detectará su número y lo enrutará al chat con los digitadores del centro de acopio para atención personalizada.",
        "icon" => "fa-comments"
    ],
    [
        "id" => 3,
        "titulo" => "3. Reporte de Incidencias en Helpdesk",
        "categoria" => "Guías de Uso",
        "contenido" => "El módulo de Helpdesk centraliza los reportes sobre dificultades logísticas y anomalías del sistema en el transcurso de las campañas.\n\n" .
                       "**Cómo usar el Helpdesk:**\n" .
                       "- Vaya a la pestaña de **Helpdesk**.\n" .
                       "- Presione **Reportar Incidencia**.\n" .
                       "- Seleccione la categoría ('Campaña Electoral', 'Anomalía del Sistema' o 'Soporte Técnico').\n" .
                       "- Detalle el problema (ejemplo: 'Faltan formularios físicos en el centro de acopio Sabana Perdida' o 'La cámara no se activa en dispositivos iOS antiguos').\n" .
                       "- Los ingenieros de Soporte TI de Turno revisarán el caso y actualizarán el estado a 'En Proceso' y luego a 'Resuelto'.",
        "icon" => "fa-ticket-alt"
    ],
    [
        "id" => 4,
        "titulo" => "4. Recursos Descargables de la Campaña",
        "categoria" => "Descargas",
        "contenido" => "Descargue los recursos oficiales para el trabajo logístico de campo:\n\n" .
                       "- **Formulario Físico de Captación (PDF)**: Plantilla oficial para los promotores de campo que recolectan firmas en físico.\n" .
                       "- **Manual del Promotor Electoral (PDF)**: Guía de bolsillo con las directrices de la candidatura de la Diputada Altagracia De Los Santos.\n" .
                       "- **Kit de Redes Sociales (ZIP)**: Banners publicitarios, logotipos del PRM y fotos de campaña autorizadas para difusión masiva.",
        "icon" => "fa-download"
    ]
];

echo json_encode(["exito" => true, "documentos" => $documentos]);
?>
