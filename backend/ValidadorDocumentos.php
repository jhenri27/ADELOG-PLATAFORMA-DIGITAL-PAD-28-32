<?php
/**
 * Clase Utilitaria para Validaciones de Datos Dominicanos
 * Adaptada de la Plataforma Integrada de Más Que Fianzas
 */

class ValidadorDocumentos {
    /**
     * Validación de Cédula Dominicana (11 dígitos, Luhn mod 10)
     */
    public static function validarCedula($cedula) {
        if ($cedula === null) return false;
        
        // Eliminar caracteres no numéricos
        $cedula = preg_replace('/\D/', '', $cedula);
        
        // Debe tener exactamente 11 dígitos
        if (strlen($cedula) !== 11) return false;

        // Regla especial: Cédulas que empiezan por 000 no suelen ser válidas, pero evaluamos por módulo 10
        $pesos = [1, 2, 1, 2, 1, 2, 1, 2, 1, 2];
        $suma = 0;

        for ($i = 0; $i < 10; $i++) {
            $mult = (int)$cedula[$i] * $pesos[$i];
            if ($mult >= 10) {
                $suma += (int)($mult / 10) + ($mult % 10);
            } else {
                $suma += $mult;
            }
        }

        $digito_calculado = (10 - ($suma % 10)) % 10;
        $digito_real = (int)$cedula[10];

        return $digito_calculado === $digito_real;
    }

    /**
     * Validación de Teléfono Dominicano (10 dígitos, prefijos 809/829/849)
     */
    public static function validarTelefono($telefono) {
        if ($telefono === null) return false;
        
        // Eliminar caracteres no numéricos
        $telefono = preg_replace('/\D/', '', $telefono);
        
        // Debe tener exactamente 10 dígitos
        if (strlen($telefono) !== 10) return false;
        
        // Debe empezar con un prefijo dominicano válido
        $prefijo = substr($telefono, 0, 3);
        return in_array($prefijo, ['809', '829', '849']);
    }
}
?>
