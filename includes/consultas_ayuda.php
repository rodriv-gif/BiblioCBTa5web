<?php
// Consultas de la pantalla de Ayuda (la ve el lector y la administra el bibliotecario).
//
// Usa una tabla nueva, tblayuda. Se crea con el archivo sql/ayuda.sql:
//   tblayuda : id_ayuda, pregunta, respuesta, orden, fecha_alta, id_bibliotecario

require_once __DIR__ . "/bd.php";
require_once __DIR__ . "/utiles.php";

// Límites de los textos (los mismos que tiene la tabla)
const AYUDA_MAX_PREGUNTA = 200;
const AYUDA_MAX_RESPUESTA = 2000;

// Todos los temas, en el orden que decidió el bibliotecario
function temas_de_ayuda($conexion)
{
    return consultar(
        $conexion,
        "SELECT id_ayuda, pregunta, respuesta, orden, fecha_alta
         FROM tblayuda
         ORDER BY orden ASC, id_ayuda ASC"
    );
}

// Alta de un tema. Si $orden viene vacío, se manda al final de la lista.
// Regresa true si se guardó.
function agregar_tema_ayuda($conexion, $pregunta, $respuesta, $orden, $id_bibliotecario)
{
    if ($orden === null) {
        $orden = (int) consultar_valor($conexion, "SELECT COALESCE(MAX(orden), 0) FROM tblayuda") + 1;
    }

    $guardado = ejecutar(
        $conexion,
        "INSERT INTO tblayuda (pregunta, respuesta, orden, fecha_alta, id_bibliotecario)
         VALUES (?, ?, ?, ?, ?)",
        [$pregunta, $respuesta, $orden, date("Y-m-d H:i:s"), $id_bibliotecario]
    );

    return $guardado === 1;
}

// Baja de un tema. Regresa true si se eliminó.
function borrar_tema_ayuda($conexion, $id_ayuda)
{
    return ejecutar($conexion, "DELETE FROM tblayuda WHERE id_ayuda = ?", [$id_ayuda]) === 1;
}
