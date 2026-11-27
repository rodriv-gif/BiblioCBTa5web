<?php
// El lector marca como leídos sus avisos de tblavisos (botón "Marcar como leídos" de Mis avisos).
// Solo funciona por POST, con el token de la sesión, y solo cambia la marca "leido" de sus propios avisos.

require "includes/sesion.php";
exigir_lector();
require "config/conexion.php"; // aquí viene la conexión $conexion
require "includes/consultas_lector.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST" || !csrf_valido()) {
    flash_poner("error", "No se pudo marcar los avisos. Intenta de nuevo.");
} elseif (marcar_avisos_leidos($conexion) === false) {
    flash_poner("error", "No se pudo marcar los avisos. Intenta de nuevo.");
} else {
    flash_poner("ok", "Tus avisos quedaron marcados como leídos.");
}

header("Location: mis_avisos.php");
exit();
