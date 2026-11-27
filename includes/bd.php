<?php
// Ayudas para consultar la base de datos sin que la página se caiga si una consulta falla.
// Usan MySQLi procedimental con la conexión $conexion que se crea en config/conexion.php.
// Los datos de la consulta siempre van como parámetros (?), nunca pegados al texto del SQL.

require_once __DIR__ . "/utiles.php";

// Cámbialo a false cuando todo funcione, para que el usuario no vea detalles técnicos
const MODO_DEPURACION = true;

// Guarda (y devuelve) la lista de errores de consulta de esta petición
function errores_bd($mensaje = null)
{
    static $errores = [];

    if ($mensaje !== null) {
        $errores[] = $mensaje;
    }

    return $errores;
}

// Prepara la consulta, le pone los parámetros (?) y la ejecuta. Regresa la consulta lista para leer.
// Si algo falla lanza una excepción, que atrapan consultar() y ejecutar().
function ejecutar_consulta($conexion, $sql, $parametros)
{
    $consulta = mysqli_prepare($conexion, $sql);

    if (!$consulta) {
        throw new Exception(mysqli_error($conexion));
    }

    if (count($parametros) > 0) {
        // Un tipo por cada parámetro: i = entero, d = decimal, s = texto
        $tipos = "";

        foreach ($parametros as $parametro) {
            if (is_int($parametro)) {
                $tipos .= "i";
            } elseif (is_float($parametro)) {
                $tipos .= "d";
            } else {
                $tipos .= "s";
            }
        }

        mysqli_stmt_bind_param($consulta, $tipos, ...$parametros);
    }

    if (!mysqli_stmt_execute($consulta)) {
        $mensaje = mysqli_stmt_error($consulta);
        mysqli_stmt_close($consulta);

        throw new Exception($mensaje);
    }

    return $consulta;
}

// SELECT: regresa todas las filas (o una lista vacía si falla la consulta).
// $avisar = false sirve para datos opcionales: si fallan, no se muestra el aviso amarillo.
function consultar($conexion, $sql, $parametros = [], $avisar = true)
{
    try {
        $consulta = ejecutar_consulta($conexion, $sql, array_values($parametros));
        $resultado = mysqli_stmt_get_result($consulta);

        $filas = [];

        if ($resultado) {
            $filas = mysqli_fetch_all($resultado, MYSQLI_ASSOC);
        }

        mysqli_stmt_close($consulta);

        return $filas;
    } catch (Throwable $error) {
        error_log("Consulta fallida: " . $error->getMessage());

        if ($avisar) {
            errores_bd($error->getMessage());
        }

        return [];
    }
}

// SELECT de una sola fila: regresa un arreglo o null
function consultar_una($conexion, $sql, $parametros = [], $avisar = true)
{
    $filas = consultar($conexion, $sql, $parametros, $avisar);

    return $filas[0] ?? null;
}

// SELECT de un solo valor (por ejemplo un COUNT): regresa el valor o 0
function consultar_valor($conexion, $sql, $parametros = [], $avisar = true)
{
    $fila = consultar_una($conexion, $sql, $parametros, $avisar);

    if ($fila === null) {
        return 0;
    }

    return array_values($fila)[0];
}

// INSERT, UPDATE o DELETE: regresa cuántas filas cambió, o false si falla
function ejecutar($conexion, $sql, $parametros = [])
{
    try {
        $consulta = ejecutar_consulta($conexion, $sql, array_values($parametros));
        $filas = (int) mysqli_stmt_affected_rows($consulta);
        mysqli_stmt_close($consulta);

        return $filas;
    } catch (Throwable $error) {
        error_log("Consulta fallida: " . $error->getMessage());
        errores_bd($error->getMessage());

        return false;
    }
}

// Aviso amarillo que se pone arriba de la página cuando alguna consulta falló
function aviso_errores_bd()
{
    $errores = errores_bd();

    if (count($errores) === 0) {
        return "";
    }

    $html = '<div class="alert alert-warning" role="alert">';
    $html .= "No se pudo cargar parte de la información. Intenta de nuevo en un momento.";

    if (MODO_DEPURACION) {
        $html .= '<ul class="small mb-0 mt-2">';

        foreach (array_unique($errores) as $error) {
            $html .= "<li>" . e($error) . "</li>";
        }

        $html .= "</ul>";
    }

    $html .= "</div>";

    return $html;
}
