<?php
/**
 * Conexión a Base de Datos (Singleton)
 * PAD/28-32 - Plataforma Electoral
 */

require_once __DIR__ . '/config.php';

class Database {
    private $conn;
    private static $instance = null;

    private function __construct() {
        try {
            $this->conn = new mysqli(
                DB_HOST,
                DB_USER,
                DB_PASSWORD,
                DB_NAME,
                DB_PORT
            );

            if ($this->conn->connect_error) {
                throw new Exception("Error de conexión MySQLi: " . $this->conn->connect_error);
            }

            $this->conn->set_charset("utf8mb4");
            $this->conn->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        } catch (Exception $e) {
            error_log("Error de conexión a base de datos: " . $e->getMessage());
            throw new Exception("Error de conexión a base de datos: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    // Helper para consultas directas
    public function query($sql) {
        return $this->conn->query($sql);
    }

    // Helper para preparar declaraciones
    public function prepare($sql) {
        return $this->conn->prepare($sql);
    }

    // Helper para escapar cadenas
    public function escape($str) {
        return $this->conn->real_escape_string($str);
    }

    public function __clone() {}
}
