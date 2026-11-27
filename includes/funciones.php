<?php
// Quita mayúsculas, acentos y espacios de más (para guardar y comparar la respuesta de seguridad)
function normalizar_respuesta($texto)
{
    $texto = mb_strtolower(trim($texto), "UTF-8");
    return strtr($texto, ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n"]);
}

function ocultar_correo($correo)
{
    $partes = explode("@", $correo);
    if (count($partes) !== 2) {
        return $correo;
    }
    return substr($partes[0], 0, 2) . "****@" . $partes[1];
}

// Cuántas veces falló la respuesta de seguridad en los últimos 15 minutos
function intentos_recuperacion($conexion, $clave)
{
    $desde = date("Y-m-d H:i:s", time() - 900);
    $clave_intento = "rec_" . $clave;
    $consulta = mysqli_prepare($conexion, "SELECT COUNT(*) FROM tblintentos_acceso WHERE clave_acceso = ? AND exitoso = 0 AND fecha_intento >= ?");
    mysqli_stmt_bind_param($consulta, "ss", $clave_intento, $desde);
    mysqli_stmt_execute($consulta);
    $fila = mysqli_fetch_row(mysqli_stmt_get_result($consulta));
    return (int) $fila[0];
}
