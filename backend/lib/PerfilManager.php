<?php
/**
 * Motor de Gestión de Perfiles y Accesos Granulares (RBAC)
 * PAD/28-32 - Plataforma Electoral
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

class PerfilManager {
    private $conn;

    public function __construct() {
        $this->conn = Database::getInstance()->getConnection();
    }

    /**
     * Lista todos los perfiles con conteo de usuarios asignados
     */
    public function listarPerfiles() {
        $sql = "
            SELECT p.id, p.nombre, p.descripcion, p.nivel_jerarquico, p.estado, p.fecha_creacion,
                   COUNT(u.id) as total_usuarios
            FROM perfiles p
            LEFT JOIN usuarios u ON u.perfil_id = p.id
            GROUP BY p.id
            ORDER BY p.nivel_jerarquico ASC, p.id ASC
        ";
        $res = $this->conn->query($sql);
        $perfiles = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['nivel_jerarquico'] = (int)$row['nivel_jerarquico'];
                $row['estado'] = (int)$row['estado'];
                $row['total_usuarios'] = (int)$row['total_usuarios'];
                $perfiles[] = $row;
            }
        }
        return $perfiles;
    }

    /**
     * Obtiene la estructura completa de un perfil incluyendo su malla de permisos por módulo
     */
    public function obtenerPerfilCompleto($perfil_id) {
        $perfil_id = (int)$perfil_id;
        
        $stmt = $this->conn->prepare("SELECT id, nombre, descripcion, nivel_jerarquico, estado, fecha_creacion FROM perfiles WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $perfil_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if (!$row = $res->fetch_assoc()) {
            $stmt->close();
            return null;
        }
        $stmt->close();

        $row['id'] = (int)$row['id'];
        $row['nivel_jerarquico'] = (int)$row['nivel_jerarquico'];
        $row['estado'] = (int)$row['estado'];

        // Obtener permisos por módulo
        $sqlPerms = "
            SELECT m.id as modulo_id, m.nombre_modulo, m.codigo_modulo, m.icono, m.orden,
                   COALESCE(pp.ejecutar, 0) as ejecutar,
                   COALESCE(pp.ver_datos, 0) as ver_datos,
                   COALESCE(pp.crear, 0) as crear,
                   COALESCE(pp.editar, 0) as editar,
                   COALESCE(pp.eliminar, 0) as eliminar,
                   COALESCE(pp.reportes, 0) as reportes,
                   COALESCE(pp.exportar, 0) as exportar,
                   COALESCE(pp.importar, 0) as importar,
                   COALESCE(pp.imprimir, 0) as imprimir,
                   COALESCE(pp.solo_propios, 0) as solo_propios
            FROM modulos_sistema m
            LEFT JOIN permisos_perfil pp ON pp.modulo_id = m.id AND pp.perfil_id = ?
            WHERE m.activo = 1
            ORDER BY m.orden ASC
        ";
        $stmtP = $this->conn->prepare($sqlPerms);
        $stmtP->bind_param("i", $perfil_id);
        $stmtP->execute();
        $resP = $stmtP->get_result();
        
        $malla = [];
        while ($p = $resP->fetch_assoc()) {
            $malla[] = [
                'modulo_id'    => (int)$p['modulo_id'],
                'nombre_modulo'=> $p['nombre_modulo'],
                'codigo_modulo'=> $p['codigo_modulo'],
                'icono'        => $p['icono'],
                'orden'        => (int)$p['orden'],
                'ejecutar'     => (int)$p['ejecutar'],
                'ver_datos'    => (int)$p['ver_datos'],
                'crear'        => (int)$p['crear'],
                'editar'       => (int)$p['editar'],
                'eliminar'     => (int)$p['eliminar'],
                'reportes'     => (int)$p['reportes'],
                'exportar'     => (int)$p['exportar'],
                'importar'     => (int)$p['importar'],
                'imprimir'     => (int)$p['imprimir'],
                'solo_propios' => (int)$p['solo_propios']
            ];
        }
        $stmtP->close();

        $row['malla_permisos'] = $malla;
        return $row;
    }

    /**
     * Obtiene la estructura global de módulos y funciones del sistema
     */
    public function obtenerMallaPermisos() {
        $res = $this->conn->query("SELECT id, nombre_modulo, codigo_modulo, icono, orden FROM modulos_sistema WHERE activo = 1 ORDER BY orden ASC");
        $modulos = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['id'] = (int)$r['id'];
                $r['orden'] = (int)$r['orden'];
                $modulos[] = $r;
            }
        }
        return $modulos;
    }

    /**
     * Crea un nuevo perfil y opcionalmente clona la malla de permisos desde un perfil origen
     */
    public function crearPerfil($datos, $usuario_id) {
        $nombre = trim($datos['nombre'] ?? '');
        $descripcion = trim($datos['descripcion'] ?? '');
        $nivel = (int)($datos['nivel_jerarquico'] ?? 5);
        $origen_id = (int)($datos['perfil_origen'] ?? 0);

        if (empty($nombre)) {
            return ["exito" => false, "mensaje" => "El nombre del perfil es obligatorio."];
        }

        // Verificar si existe el nombre
        $stmtCheck = $this->conn->prepare("SELECT id FROM perfiles WHERE nombre = ? LIMIT 1");
        $stmtCheck->bind_param("s", $nombre);
        $stmtCheck->execute();
        if ($stmtCheck->get_result()->num_rows > 0) {
            $stmtCheck->close();
            return ["exito" => false, "mensaje" => "Ya existe un perfil registrado con ese nombre."];
        }
        $stmtCheck->close();

        $stmt = $this->conn->prepare("INSERT INTO perfiles (nombre, descripcion, nivel_jerarquico, estado) VALUES (?, ?, ?, 1)");
        $stmt->bind_param("ssi", $nombre, $descripcion, $nivel);
        
        if ($stmt->execute()) {
            $newPerfilId = $stmt->insert_id;
            $stmt->close();

            // Si especificó perfil origen, clonar permisos
            if ($origen_id > 0) {
                $this->copiarPermisos($origen_id, $newPerfilId, $usuario_id);
            } else {
                // Inicializar matriz vacía por módulo
                $stmtInit = $this->conn->prepare("INSERT INTO permisos_perfil (perfil_id, modulo_id) SELECT ?, id FROM modulos_sistema WHERE activo = 1");
                $stmtInit->bind_param("i", $newPerfilId);
                $stmtInit->execute();
                $stmtInit->close();
            }

            $this->logAuditoria($usuario_id, 'CREAR_PERFIL', "Creado nuevo perfil '$nombre' (ID: $newPerfilId, Nivel: $nivel)");

            return [
                "exito" => true,
                "mensaje" => "Perfil '$nombre' creado exitosamente.",
                "id" => $newPerfilId
            ];
        } else {
            return ["exito" => false, "mensaje" => "Error al insertar perfil: " . $stmt->error];
        }
    }

    /**
     * Edita metadatos o estado de un perfil
     */
    public function editarPerfil($perfil_id, $datos, $usuario_id) {
        $perfil_id = (int)$perfil_id;
        $nombre = trim($datos['nombre'] ?? '');
        $descripcion = trim($datos['descripcion'] ?? '');
        $nivel = isset($datos['nivel_jerarquico']) ? (int)$datos['nivel_jerarquico'] : null;
        $estado = isset($datos['estado']) ? (int)$datos['estado'] : null;

        if ($perfil_id <= 0) {
            return ["exito" => false, "mensaje" => "ID de perfil no válido."];
        }

        $fields = [];
        $params = [];
        $types = "";

        if (!empty($nombre)) {
            $fields[] = "nombre = ?";
            $params[] = $nombre;
            $types .= "s";
        }
        if ($descripcion !== null) {
            $fields[] = "descripcion = ?";
            $params[] = $descripcion;
            $types .= "s";
        }
        if ($nivel !== null) {
            $fields[] = "nivel_jerarquico = ?";
            $params[] = $nivel;
            $types .= "i";
        }
        if ($estado !== null) {
            $fields[] = "estado = ?";
            $params[] = $estado;
            $types .= "i";
        }

        if (empty($fields)) {
            return ["exito" => false, "mensaje" => "No se enviaron datos para actualizar."];
        }

        $params[] = $perfil_id;
        $types .= "i";

        $sql = "UPDATE perfiles SET " . implode(", ", $fields) . " WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            $stmt->close();
            $this->logAuditoria($usuario_id, 'EDITAR_PERFIL', "Perfil ID $perfil_id actualizado exitosamente.");
            return ["exito" => true, "mensaje" => "Perfil actualizado exitosamente."];
        } else {
            return ["exito" => false, "mensaje" => "Error al actualizar perfil: " . $stmt->error];
        }
    }

    /**
     * Asigna o guarda la matriz completa de permisos a un perfil
     */
    public function asignarPermisosAPerfil($perfil_id, $permisos, $usuario_id) {
        $perfil_id = (int)$perfil_id;
        if ($perfil_id <= 0) {
            return ["exito" => false, "mensaje" => "ID de perfil no válido."];
        }

        $this->conn->begin_transaction();

        try {
            $stmtDel = $this->conn->prepare("DELETE FROM permisos_perfil WHERE perfil_id = ?");
            $stmtDel->bind_param("i", $perfil_id);
            $stmtDel->execute();
            $stmtDel->close();

            $stmtIns = $this->conn->prepare("
                INSERT INTO permisos_perfil 
                (perfil_id, modulo_id, ejecutar, ver_datos, crear, editar, eliminar, reportes, exportar, importar, imprimir, solo_propios)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($permisos as $p) {
                $modId   = (int)($p['modulo_id'] ?? 0);
                $ejec    = (int)($p['ejecutar'] ?? 0);
                $ver     = (int)($p['ver_datos'] ?? 0);
                $crear   = (int)($p['crear'] ?? 0);
                $edit    = (int)($p['editar'] ?? 0);
                $elim    = (int)($p['eliminar'] ?? 0);
                $rep     = (int)($p['reportes'] ?? 0);
                $exp     = (int)($p['exportar'] ?? 0);
                $imp     = (int)($p['importar'] ?? 0);
                $prn     = (int)($p['imprimir'] ?? 0);
                $soloProp= (int)($p['solo_propios'] ?? 0);

                if ($modId > 0) {
                    $stmtIns->bind_param(
                        "iiiiiiiiiiii",
                        $perfil_id, $modId, $ejec, $ver, $crear, $edit, $elim, $rep, $exp, $imp, $prn, $soloProp
                    );
                    $stmtIns->execute();
                }
            }
            $stmtIns->close();

            $this->conn->commit();
            $this->logAuditoria($usuario_id, 'ASIGNAR_PERMISOS', "Actualizada malla de permisos para perfil ID $perfil_id.");

            return ["exito" => true, "mensaje" => "Malla de permisos guardada exitosamente."];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ["exito" => false, "mensaje" => "Error al guardar permisos: " . $e->getMessage()];
        }
    }

    /**
     * Clona/copia la matriz completa de permisos de un perfil origen a un perfil destino
     */
    public function copiarPermisos($origen_id, $destino_id, $usuario_id) {
        $origen_id = (int)$origen_id;
        $destino_id = (int)$destino_id;

        if ($origen_id <= 0 || $destino_id <= 0) {
            return ["exito" => false, "mensaje" => "Perfil origen y destino son requeridos."];
        }
        if ($origen_id === $destino_id) {
            return ["exito" => false, "mensaje" => "El perfil origen y destino no pueden ser el mismo."];
        }

        $this->conn->begin_transaction();

        try {
            $stmtDel = $this->conn->prepare("DELETE FROM permisos_perfil WHERE perfil_id = ?");
            $stmtDel->bind_param("i", $destino_id);
            $stmtDel->execute();
            $stmtDel->close();

            $stmtCopy = $this->conn->prepare("
                INSERT INTO permisos_perfil 
                (perfil_id, modulo_id, ejecutar, ver_datos, crear, editar, eliminar, reportes, exportar, importar, imprimir, solo_propios)
                SELECT ?, modulo_id, ejecutar, ver_datos, crear, editar, eliminar, reportes, exportar, importar, imprimir, solo_propios
                FROM permisos_perfil
                WHERE perfil_id = ?
            ");
            $stmtCopy->bind_param("ii", $destino_id, $origen_id);
            $stmtCopy->execute();
            $countClonados = $stmtCopy->affected_rows;
            $stmtCopy->close();

            $this->conn->commit();
            $this->logAuditoria($usuario_id, 'COPIAR_PERMISOS', "Permisos clonados desde perfil ID $origen_id hacia perfil ID $destino_id ($countClonados módulos).");

            return [
                "exito" => true,
                "mensaje" => "Permisos clonados exitosamente desde el perfil origen."
            ];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ["exito" => false, "mensaje" => "Error al clonar permisos: " . $e->getMessage()];
        }
    }

    /**
     * Desactiva o elimina un perfil (con validación de usuarios asignados)
     */
    public function eliminarPerfil($perfil_id, $usuario_id) {
        $perfil_id = (int)$perfil_id;
        
        if ($perfil_id === 1) {
            return ["exito" => false, "mensaje" => "El perfil Administrador Principal no puede ser eliminado ni desactivado."];
        }

        // Verificar si existen usuarios asignados
        $stmtUsers = $this->conn->prepare("SELECT COUNT(*) as total FROM usuarios WHERE perfil_id = ? AND estado = 1");
        $stmtUsers->bind_param("i", $perfil_id);
        $stmtUsers->execute();
        $cnt = $stmtUsers->get_result()->fetch_assoc()['total'];
        $stmtUsers->close();

        if ($cnt > 0) {
            return [
                "exito" => false,
                "mensaje" => "No se puede desactivar/eliminar el perfil porque existen $cnt usuario(s) activo(s) asignado(s). Reasigne los usuarios a otro perfil primero."
            ];
        }

        // Cambiar estado a Inactivo (Soft delete de seguridad)
        $stmt = $this->conn->prepare("UPDATE perfiles SET estado = 0 WHERE id = ?");
        $stmt->bind_param("i", $perfil_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            $this->logAuditoria($usuario_id, 'ELIMINAR_PERFIL', "Perfil ID $perfil_id desactivado correctamente.");
            return ["exito" => true, "mensaje" => "Perfil desactivado exitosamente."];
        } else {
            return ["exito" => false, "mensaje" => "Error al desactivar perfil: " . $stmt->error];
        }
    }

    private function logAuditoria($usuario_id, $accion, $detalles) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $this->conn->prepare("INSERT INTO logs_auditoria (usuario_id, accion, detalles, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $usuario_id, $accion, $detalles, $ip);
        $stmt->execute();
        $stmt->close();
    }
}
