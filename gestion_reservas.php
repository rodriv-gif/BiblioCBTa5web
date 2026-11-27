<?php
session_start();
date_default_timezone_set("America/Mexico_City");

// Candado de seguridad: verifica que el bibliotecario haya iniciado sesión
if (!isset($_SESSION['id_bibliotecario'])) {
    header('Location: login.php?error=acceso_denegado');
    exit;
}

require_once __DIR__ . '/config/conexion.php';
$pagina_activa = "gestion_reservas.php";
$ahora         = date('Y-m-d H:i:s');
$error         = '';

// Responde en vivo al buscador de alumnos y trabajadores del bloque inferior (máximo 8 coincidencias)
if (isset($_GET['ajax_lectores']) && $_GET['ajax_lectores'] === '1') {
    $termino = trim($_GET['term'] ?? '');
    if ($termino !== '') {
        $likeTerm = '%' . $termino . '%';
        $sqlSug = "SELECT CONCAT('A-', matricula) AS codigo,
                   CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS nombre_completo,
                   CONCAT('Alumno · ', matricula) AS subtexto
                   FROM tblalumnos
                   WHERE CAST(matricula AS CHAR) LIKE ?
                      OR CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) LIKE ?
                   UNION ALL
                   SELECT CONCAT('T-', numero_control) AS codigo,
                   CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS nombre_completo,
                   CONCAT('Trabajador · ', numero_control) AS subtexto
                   FROM tbltrabajadores
                   WHERE CAST(numero_control AS CHAR) LIKE ?
                      OR CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) LIKE ?
                   ORDER BY nombre_completo ASC
                   LIMIT 8";

        $stmtSug = mysqli_prepare($conexion, $sqlSug);
        if ($stmtSug) {
            mysqli_stmt_bind_param($stmtSug, "ssss", $likeTerm, $likeTerm, $likeTerm, $likeTerm);
            mysqli_stmt_execute($stmtSug);
            $resSug = mysqli_stmt_get_result($stmtSug);

            if (mysqli_num_rows($resSug) > 0) {
                while ($sug = mysqli_fetch_assoc($resSug)) {
                    $etiquetaCompleta = $sug['nombre_completo'] . ' (' . $sug['subtexto'] . ')';
                    echo '<button type="button" class="reader-suggestion-item" data-codigo="' . htmlspecialchars($sug['codigo'], ENT_QUOTES) . '" data-etiqueta="' . htmlspecialchars($etiquetaCompleta, ENT_QUOTES) . '">';
                    echo '<span class="fw-medium text-dark">' . htmlspecialchars($sug['nombre_completo']) . '</span>';
                    echo '<span class="reader-suggestion-badge">' . htmlspecialchars($sug['subtexto']) . '</span>';
                    echo '</button>';
                }
            } else {
                echo '<div class="reader-suggestion-empty">No se encontraron lectores con ese dato.</div>';
            }
            mysqli_stmt_close($stmtSug);
        }
    }
    exit;
}

// Consulta días de préstamo configurados en la base de datos o aplica los valores por defecto
$diasAlumno     = 7;
$diasTrabajador = 15;
$resConfig = mysqli_query($conexion, "SELECT clave, valor FROM tblconfiguracion");
if ($resConfig) {
    while ($conf = mysqli_fetch_assoc($resConfig)) {
        if ($conf['clave'] === 'dias_prestamo_alumno' && is_numeric($conf['valor'])) {
            $diasAlumno = (int)$conf['valor'];
        }
        if ($conf['clave'] === 'dias_prestamo_trabajador' && is_numeric($conf['valor'])) {
            $diasTrabajador = (int)$conf['valor'];
        }
    }
}

// Procesa la confirmación del préstamo cuando se envía el formulario del modal por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idReserva      = (int)($_POST['id_reserva'] ?? 0);
    $idTipoPrestamo = (int)($_POST['id_tipo_prestamo'] ?? 0);
    $idBiblio       = (int)$_SESSION['id_bibliotecario'];

    if ($idReserva <= 0 || $idTipoPrestamo <= 0) {
        $error = 'Selecciona una reserva y un tipo de préstamo válidos.';
    } else {
        $sqlRes  = "SELECT id_reserva, matricula, numero_control, id_ejemplar
                    FROM tblreservas
                    WHERE id_reserva = ? AND id_estado_reserva = 1 AND fecha_limite >= ?";
        $stmtRes = mysqli_prepare($conexion, $sqlRes);
        mysqli_stmt_bind_param($stmtRes, "is", $idReserva, $ahora);
        mysqli_stmt_execute($stmtRes);
        $resDato     = mysqli_stmt_get_result($stmtRes);
        $filaReserva = mysqli_fetch_assoc($resDato);
        mysqli_stmt_close($stmtRes);

        if (!$filaReserva) {
            $error = 'La reserva seleccionada ya no está activa o ya venció.';
        } else {
            $fechaPrestamo = date('Y-m-d');
            if ($idTipoPrestamo === 1) {
                $fechaDevolucion = $fechaPrestamo;
            } else {
                $diasAsignados   = !empty($filaReserva['numero_control']) ? $diasTrabajador : $diasAlumno;
                $fechaDevolucion = date('Y-m-d', strtotime('+' . $diasAsignados . ' days'));
            }

            $matriculaVal  = $filaReserva['matricula'] !== null ? (int)$filaReserva['matricula'] : null;
            $numControlVal = $filaReserva['numero_control'] !== null ? (int)$filaReserva['numero_control'] : null;
            $idEjemplarVal = (int)$filaReserva['id_ejemplar'];
            $estadoPrest   = 1;

            $sqlIns  = "INSERT INTO tblprestamos (id_bibliotecario, id_ejemplar, matricula, numero_control, id_tipo_prestamo, id_estado_prestamo, fecha_prestamo, fecha_devolucion)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtIns = mysqli_prepare($conexion, $sqlIns);

            if ($stmtIns) {
                mysqli_stmt_bind_param($stmtIns, "iiiiiiss", $idBiblio, $idEjemplarVal, $matriculaVal, $numControlVal, $idTipoPrestamo, $estadoPrest, $fechaPrestamo, $fechaDevolucion);
                if (mysqli_stmt_execute($stmtIns)) {
                    $nuevoIdPrestamo = mysqli_insert_id($conexion);
                    mysqli_stmt_close($stmtIns);

                    $sqlUpd  = "UPDATE tblreservas SET id_estado_reserva = 2, id_prestamo = ? WHERE id_reserva = ?";
                    $stmtUpd = mysqli_prepare($conexion, $sqlUpd);
                    mysqli_stmt_bind_param($stmtUpd, "ii", $nuevoIdPrestamo, $idReserva);
                    mysqli_stmt_execute($stmtUpd);
                    mysqli_stmt_close($stmtUpd);

                    header('Location: gestion_reservas.php?estado=activas&confirmado=1');
                    exit;
                } else {
                    $error = 'Error al registrar el préstamo en la base de datos.';
                }
            }
        }
    }
}

// Calcula el texto sencillo del tiempo restante o del tiempo transcurrido desde que venció
function obtenerTextoTiempo($fechaLimite, $fechaActual)
{
    $segundos = strtotime($fechaLimite) - strtotime($fechaActual);
    $absoluto = abs($segundos);
    $dias     = (int)floor($absoluto / 86400);
    $horas    = (int)floor(($absoluto % 86400) / 3600);
    $minutos  = max(1, (int)floor(($absoluto % 3600) / 60));

    if ($segundos >= 0) {
        if ($dias >= 1) {
            return $dias === 1 ? 'Queda 1 día' : 'Quedan ' . $dias . ' días';
        }
        if ($horas >= 1) {
            return $horas === 1 ? 'Queda 1 h' : 'Quedan ' . $horas . ' h';
        }
        return 'Quedan ' . $minutos . ' min';
    }

    if ($dias >= 1) {
        return $dias === 1 ? 'Venció hace 1 día' : 'Venció hace ' . $dias . ' días';
    }
    if ($horas >= 1) {
        return $horas === 1 ? 'Venció hace 1 h' : 'Venció hace ' . $horas . ' h';
    }
    return 'Venció hace ' . $minutos . ' min';
}

// Recibe y valida la pestaña seleccionada, la búsqueda de reservas y la paginación
$estadoActual = $_GET['estado'] ?? 'activas';
$estadosPermitidos = ['activas', 'confirmadas', 'vencidas', 'canceladas'];
if (!in_array($estadoActual, $estadosPermitidos)) {
    $estadoActual = 'activas';
}

$busqueda     = trim($_GET['q'] ?? '');
$like         = '%' . $busqueda . '%';
$paginaActual = (isset($_GET['p']) && is_numeric($_GET['p']) && (int)$_GET['p'] > 0) ? (int)$_GET['p'] : 1;
$porPagina    = 6;

// Valida fecha inicial, fecha final y búsqueda del alumno/trabajador para préstamos por periodo
$fechaInicio  = trim($_GET['fecha_inicio'] ?? '');
$fechaFin     = trim($_GET['fecha_fin'] ?? '');
$lectorFiltro = trim($_GET['lector'] ?? '');
$lectorTexto  = trim($_GET['lector_texto'] ?? '');

if ($fechaInicio !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) {
    $fechaInicio = '';
}
if ($fechaFin !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
    $fechaFin = '';
}
if ($fechaInicio !== '' && $fechaFin !== '' && $fechaInicio > $fechaFin) {
    $tempFecha   = $fechaInicio;
    $fechaInicio = $fechaFin;
    $fechaFin    = $tempFecha;
}

$tipoLectorSel  = '';
$idLectorSel    = 0;
$esAlumnoFiltro = 0;
$esTrabFiltro   = 0;
if (preg_match('/^(A|T)-(\d+)$/', $lectorFiltro, $partesLector)) {
    $tipoLectorSel  = $partesLector[1];
    $idLectorSel    = (int)$partesLector[2];
    $esAlumnoFiltro = ($tipoLectorSel === 'A') ? 1 : 0;
    $esTrabFiltro   = ($tipoLectorSel === 'T') ? 1 : 0;
} else {
    $lectorFiltro = '';
}

// Si escribió un nombre o matrícula sin hacer clic en la sugerencia, intenta identificar si es un lector único
if ($idLectorSel === 0 && $lectorTexto !== '') {
    $likeLector = '%' . $lectorTexto . '%';
    $sqlUnico = "SELECT 'A' AS tipo, matricula AS clave, CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS nombre_completo
                 FROM tblalumnos
                 WHERE CAST(matricula AS CHAR) = ? OR CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) LIKE ?
                 UNION ALL
                 SELECT 'T' AS tipo, numero_control AS clave, CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS nombre_completo
                 FROM tbltrabajadores
                 WHERE CAST(numero_control AS CHAR) = ? OR CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) LIKE ?
                 LIMIT 2";
    $stmtUnico = mysqli_prepare($conexion, $sqlUnico);
    if ($stmtUnico) {
        mysqli_stmt_bind_param($stmtUnico, "ssss", $lectorTexto, $likeLector, $lectorTexto, $likeLector);
        mysqli_stmt_execute($stmtUnico);
        $resUnico = mysqli_stmt_get_result($stmtUnico);
        if (mysqli_num_rows($resUnico) === 1) {
            $filaUnica      = mysqli_fetch_assoc($resUnico);
            $tipoLectorSel  = $filaUnica['tipo'];
            $idLectorSel    = (int)$filaUnica['clave'];
            $lectorFiltro   = $tipoLectorSel . '-' . $idLectorSel;
            $esAlumnoFiltro = ($tipoLectorSel === 'A') ? 1 : 0;
            $esTrabFiltro   = ($tipoLectorSel === 'T') ? 1 : 0;
        }
        mysqli_stmt_close($stmtUnico);
    }
}

// Consulta en tblprestamos cuántos libros se prestaron según las fechas y el lector elegido
$mostrarResumenPrestamos  = ($fechaInicio !== '' || $fechaFin !== '' || $idLectorSel > 0 || $lectorTexto !== '');
$listaPrestamosPeriodo    = [];
$totalPrestadosPeriodo    = 0;
$nombreLectorSeleccionado = '';

if ($idLectorSel > 0) {
    if ($esAlumnoFiltro === 1) {
        $stmtNom = mysqli_prepare($conexion, "SELECT CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS nombre_completo FROM tblalumnos WHERE matricula = ?");
    } else {
        $stmtNom = mysqli_prepare($conexion, "SELECT CONCAT_WS(' ', nombre, apellido_paterno, apellido_materno) AS nombre_completo FROM tbltrabajadores WHERE numero_control = ?");
    }
    if ($stmtNom) {
        mysqli_stmt_bind_param($stmtNom, "i", $idLectorSel);
        mysqli_stmt_execute($stmtNom);
        $resNom = mysqli_stmt_get_result($stmtNom);
        if ($filaNom = mysqli_fetch_assoc($resNom)) {
            $etiquetaTipo             = ($esAlumnoFiltro === 1) ? 'Alumno · ' : 'Trabajador · ';
            $nombreLectorSeleccionado = $filaNom['nombre_completo'] . ' (' . $etiquetaTipo . $idLectorSel . ')';
            $lectorTexto              = $nombreLectorSeleccionado;
        }
        mysqli_stmt_close($stmtNom);
    }
}

if ($mostrarResumenPrestamos) {
    $textoLibreSql  = ($idLectorSel === 0) ? $lectorTexto : '';
    $likeTextoLibre = '%' . $textoLibreSql . '%';

    $sqlPrestamos = "SELECT p.id_prestamo, p.matricula, p.numero_control, p.id_estado_prestamo,
                     p.fecha_prestamo, p.fecha_devolucion,
                     e.id_ejemplar, e.inventario, l.titulo_libro, ep.estado AS nombre_estado,
                     COALESCE(
                         CONCAT_WS(' ', a.nombre, a.apellido_paterno, a.apellido_materno),
                         CONCAT_WS(' ', t.nombre, t.apellido_paterno, t.apellido_materno)
                     ) AS nombre_lector
                     FROM tblprestamos p
                     INNER JOIN tblejemplares e ON e.id_ejemplar = p.id_ejemplar
                     INNER JOIN tbllibros l ON l.id_libro = e.id_libro
                     INNER JOIN tblestado_prestamo ep ON ep.id_estado = p.id_estado_prestamo
                     LEFT JOIN tblalumnos a ON a.matricula = p.matricula
                     LEFT JOIN tbltrabajadores t ON t.numero_control = p.numero_control
                     WHERE (? = '' OR p.fecha_prestamo >= ?)
                       AND (? = '' OR p.fecha_prestamo <= ?)
                       AND (? = 0 OR p.matricula = ?)
                       AND (? = 0 OR p.numero_control = ?)
                       AND (? = '' OR CAST(p.matricula AS CHAR) LIKE ?
                            OR CAST(p.numero_control AS CHAR) LIKE ?
                            OR CONCAT_WS(' ', a.nombre, a.apellido_paterno, a.apellido_materno) LIKE ?
                            OR CONCAT_WS(' ', t.nombre, t.apellido_paterno, t.apellido_materno) LIKE ?)
                     ORDER BY p.fecha_prestamo DESC, p.id_prestamo DESC";

    $stmtPrest = mysqli_prepare($conexion, $sqlPrestamos);
    if ($stmtPrest) {
        mysqli_stmt_bind_param(
            $stmtPrest,
            "ssssiiiisssss",
            $fechaInicio,
            $fechaInicio,
            $fechaFin,
            $fechaFin,
            $esAlumnoFiltro,
            $idLectorSel,
            $esTrabFiltro,
            $idLectorSel,
            $textoLibreSql,
            $likeTextoLibre,
            $likeTextoLibre,
            $likeTextoLibre,
            $likeTextoLibre
        );
        mysqli_stmt_execute($stmtPrest);
        $resPrest = mysqli_stmt_get_result($stmtPrest);
        while ($filaP = mysqli_fetch_assoc($resPrest)) {
            $listaPrestamosPeriodo[] = $filaP;
        }
        $totalPrestadosPeriodo = count($listaPrestamosPeriodo);
        mysqli_stmt_close($stmtPrest);
    }
}

$queryExtraPeriodo = '&fecha_inicio=' . urlencode($fechaInicio) . '&fecha_fin=' . urlencode($fechaFin) . '&lector=' . urlencode($lectorFiltro) . '&lector_texto=' . urlencode($lectorTexto);

// Obtiene el total de reservas en cada estado para mostrar el contador en las pestañas
$conteos = [
    'activas'     => 0,
    'confirmadas' => 0,
    'vencidas'    => 0,
    'canceladas'  => 0,
];
$sqlConteos = "SELECT
    SUM(CASE WHEN id_estado_reserva = 1 AND fecha_limite >= ? THEN 1 ELSE 0 END) AS activas,
    SUM(CASE WHEN id_estado_reserva = 2 THEN 1 ELSE 0 END) AS confirmadas,
    SUM(CASE WHEN id_estado_reserva = 1 AND fecha_limite < ? THEN 1 ELSE 0 END) AS vencidas,
    SUM(CASE WHEN id_estado_reserva = 3 THEN 1 ELSE 0 END) AS canceladas
    FROM tblreservas";
$stmtConteos = mysqli_prepare($conexion, $sqlConteos);
if ($stmtConteos) {
    mysqli_stmt_bind_param($stmtConteos, "ss", $ahora, $ahora);
    mysqli_stmt_execute($stmtConteos);
    $resConteos = mysqli_stmt_get_result($stmtConteos);
    if ($filaC = mysqli_fetch_assoc($resConteos)) {
        $conteos['activas']     = (int)($filaC['activas'] ?? 0);
        $conteos['confirmadas'] = (int)($filaC['confirmadas'] ?? 0);
        $conteos['vencidas']    = (int)($filaC['vencidas'] ?? 0);
        $conteos['canceladas']  = (int)($filaC['canceladas'] ?? 0);
    }
    mysqli_stmt_close($stmtConteos);
}

// Define la condición SQL, encabezados y orden según la pestaña elegida por el usuario
$usaFecha = false;
if ($estadoActual === 'activas') {
    $condicionEstado = "r.id_estado_reserva = 1 AND r.fecha_limite >= ?";
    $ordenSql        = "r.fecha_limite ASC";
    $tituloTabla     = "Reservas activas";
    $subtituloTabla  = "Ordenadas por fecha límite más próxima (haz clic en un lector para ver sus préstamos abajo)";
    $encabezadoCol4  = "Tiempo restante";
    $usaFecha        = true;
} elseif ($estadoActual === 'vencidas') {
    $condicionEstado = "r.id_estado_reserva = 1 AND r.fecha_limite < ?";
    $ordenSql        = "r.fecha_limite DESC";
    $tituloTabla     = "Reservas vencidas";
    $subtituloTabla  = "No se recogieron a tiempo; puedes liberar el ejemplar";
    $encabezadoCol4  = "Tiempo vencido";
    $usaFecha        = true;
} elseif ($estadoActual === 'confirmadas') {
    $condicionEstado = "r.id_estado_reserva = 2";
    $ordenSql        = "r.id_reserva DESC";
    $tituloTabla     = "Reservas confirmadas";
    $subtituloTabla  = "Reservas entregadas y registradas como préstamo";
    $encabezadoCol4  = "Préstamo";
} else {
    $condicionEstado = "r.id_estado_reserva = 3";
    $ordenSql        = "r.id_reserva DESC";
    $tituloTabla     = "Reservas canceladas";
    $subtituloTabla  = "Historial de reservas canceladas o liberadas";
    $encabezadoCol4  = "Estado";
}

$joinsYBusqueda = "FROM tblreservas r
                   INNER JOIN tblejemplares e ON e.id_ejemplar = r.id_ejemplar
                   INNER JOIN tbllibros l ON l.id_libro = e.id_libro
                   LEFT JOIN tblalumnos a ON a.matricula = r.matricula
                   LEFT JOIN tbltrabajadores t ON t.numero_control = r.numero_control
                   WHERE $condicionEstado
                     AND (? = '' OR r.folio LIKE ?
                          OR CAST(r.matricula AS CHAR) LIKE ?
                          OR CAST(r.numero_control AS CHAR) LIKE ?
                          OR CONCAT_WS(' ', a.nombre, a.apellido_paterno, a.apellido_materno) LIKE ?
                          OR CONCAT_WS(' ', t.nombre, t.apellido_paterno, t.apellido_materno) LIKE ?
                          OR l.titulo_libro LIKE ?
                          OR CAST(e.id_ejemplar AS CHAR) LIKE ?)";

$totalRegistros = 0;
$stmtTotal      = mysqli_prepare($conexion, "SELECT COUNT(*) AS total " . $joinsYBusqueda);
if ($stmtTotal) {
    if ($usaFecha) {
        mysqli_stmt_bind_param($stmtTotal, "sssssssss", $ahora, $busqueda, $like, $like, $like, $like, $like, $like, $like);
    } else {
        mysqli_stmt_bind_param($stmtTotal, "ssssssss", $busqueda, $like, $like, $like, $like, $like, $like, $like);
    }
    mysqli_stmt_execute($stmtTotal);
    $resTotal = mysqli_stmt_get_result($stmtTotal);
    if ($filaTotal = mysqli_fetch_assoc($resTotal)) {
        $totalRegistros = (int)$filaTotal['total'];
    }
    mysqli_stmt_close($stmtTotal);
}

$totalPaginas = $totalRegistros > 0 ? (int)ceil($totalRegistros / $porPagina) : 1;
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$offset = ($paginaActual - 1) * $porPagina;

$sqlListado = "SELECT r.id_reserva, r.folio, r.matricula, r.numero_control, r.id_ejemplar,
               r.fecha_reserva, r.fecha_limite, r.id_prestamo,
               e.inventario, l.titulo_libro,
               COALESCE(
                   CONCAT_WS(' ', a.nombre, a.apellido_paterno, a.apellido_materno),
                   CONCAT_WS(' ', t.nombre, t.apellido_paterno, t.apellido_materno)
               ) AS nombre_lector
               " . $joinsYBusqueda . "
               ORDER BY $ordenSql
               LIMIT ? OFFSET ?";

$resultado = false;
$stmtList  = mysqli_prepare($conexion, $sqlListado);
if ($stmtList) {
    if ($usaFecha) {
        mysqli_stmt_bind_param($stmtList, "sssssssssii", $ahora, $busqueda, $like, $like, $like, $like, $like, $like, $like, $porPagina, $offset);
    } else {
        mysqli_stmt_bind_param($stmtList, "ssssssssii", $busqueda, $like, $like, $like, $like, $like, $like, $like, $porPagina, $offset);
    }
    mysqli_stmt_execute($stmtList);
    $resultado = mysqli_stmt_get_result($stmtList);
}

$resTiposPrestamo = mysqli_query($conexion, "SELECT id_tipo_prestamo, tipo FROM tbltipo_prestamo ORDER BY id_tipo_prestamo DESC");
?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Gestión de reservas - Biblioteca Escolar CBTa No. 5</title>

        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />
        <link rel="stylesheet" href="./css/bootstrap.min.css" />
        <link rel="stylesheet" href="./css/estilos.css" />
        <link rel="stylesheet" href="./css/bibliotecario.css?v=2" />
        <link rel="stylesheet" href="./css/gestion_reservas.css?v=3" />
    </head>

    <body>
        <?php include "includes/iconos.php"; ?>
        <?php include "includes/iconos_catalogo.php"; ?>

        <div class="admin-layout">
            <?php include "includes/sidebar_bibliotecario.php"; ?>

            <main class="admin-main">
                <div class="admin-content">
                    <div class="mb-4">
                        <h1 class="page-title">Gestión de reservas</h1>
                        <p class="page-subtitle mb-0">Consulta las reservas por estado, confirma el préstamo en mostrador o revisa préstamos por periodo en la parte inferior.</p>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                    <?php elseif (isset($_GET['confirmado'])): ?>
                        <div class="alert alert-success py-2">El préstamo se registró correctamente y la reserva pasó a Confirmadas.</div>
                    <?php elseif (isset($_GET['cancelado'])): ?>
                        <div class="alert alert-secondary py-2">La reserva fue cancelada y el ejemplar quedó libre nuevamente.</div>
                    <?php endif; ?>

                    <div class="panel p-3 mb-4">
                        <form method="GET" action="gestion_reservas.php" class="search-bar-clean d-flex flex-column flex-sm-row gap-2 mb-3">
                            <input type="hidden" name="estado" value="<?= htmlspecialchars($estadoActual) ?>" />
                            <input type="hidden" name="fecha_inicio" value="<?= htmlspecialchars($fechaInicio) ?>" />
                            <input type="hidden" name="fecha_fin" value="<?= htmlspecialchars($fechaFin) ?>" />
                            <input type="hidden" name="lector" value="<?= htmlspecialchars($lectorFiltro) ?>" />
                            <input type="hidden" name="lector_texto" value="<?= htmlspecialchars($lectorTexto) ?>" />

                            <div class="input-group flex-grow-1">
                                <span class="input-group-text bg-white border-end-0">
                                    <svg class="icon text-secondary"><use href="#i-search" /></svg>
                                </span>
                                <input
                                    type="search"
                                    name="q"
                                    class="form-control border-start-0"
                                    value="<?= htmlspecialchars($busqueda) ?>"
                                    placeholder="Buscar reserva por folio, matrícula, clave, nombre del lector o libro"
                                    aria-label="Buscar reserva"
                                />
                            </div>
                            <button type="submit" class="btn btn-brand text-nowrap">Buscar</button>
                            <?php if ($busqueda !== ''): ?>
                                <a href="gestion_reservas.php?estado=<?= urlencode($estadoActual) ?><?= $queryExtraPeriodo ?>" class="btn btn-outline-brand text-nowrap">Limpiar</a>
                            <?php endif; ?>
                        </form>

                        <div class="filter-tabs">
                            <a href="gestion_reservas.php?estado=activas&q=<?= urlencode($busqueda) ?><?= $queryExtraPeriodo ?>" class="filter-tab <?= $estadoActual === 'activas' ? 'active' : '' ?>">
                                Activas <span class="filter-count">(<?= $conteos['activas'] ?>)</span>
                            </a>
                            <a href="gestion_reservas.php?estado=confirmadas&q=<?= urlencode($busqueda) ?><?= $queryExtraPeriodo ?>" class="filter-tab <?= $estadoActual === 'confirmadas' ? 'active' : '' ?>">
                                Confirmadas <span class="filter-count">(<?= $conteos['confirmadas'] ?>)</span>
                            </a>
                            <a href="gestion_reservas.php?estado=vencidas&q=<?= urlencode($busqueda) ?><?= $queryExtraPeriodo ?>" class="filter-tab <?= $estadoActual === 'vencidas' ? 'active' : '' ?>">
                                Vencidas <span class="filter-count">(<?= $conteos['vencidas'] ?>)</span>
                            </a>
                            <a href="gestion_reservas.php?estado=canceladas&q=<?= urlencode($busqueda) ?><?= $queryExtraPeriodo ?>" class="filter-tab <?= $estadoActual === 'canceladas' ? 'active' : '' ?>">
                                Canceladas <span class="filter-count">(<?= $conteos['canceladas'] ?>)</span>
                            </a>
                        </div>
                    </div>

                    <section class="panel overflow-hidden">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-4 py-3 border-bottom">
                            <div>
                                <h2 class="h6 fw-semibold section-green-title mb-0"><?= htmlspecialchars($tituloTabla) ?></h2>
                                <span class="small text-secondary"><?= htmlspecialchars($subtituloTabla) ?></span>
                            </div>
                            <span class="small text-secondary">Mostrando <?= $resultado ? mysqli_num_rows($resultado) : 0 ?> de <?= $totalRegistros ?></span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-lib align-middle">
                                <thead>
                                    <tr>
                                        <th>Folio y Lector</th>
                                        <th>Libro</th>
                                        <th>Fechas</th>
                                        <th><?= htmlspecialchars($encabezadoCol4) ?></th>
                                        <th class="text-end text-nowrap">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($resultado && mysqli_num_rows($resultado) > 0): ?>
                                        <?php while ($reserva = mysqli_fetch_assoc($resultado)): ?>
                                            <?php
                                            $esTrabajador  = !empty($reserva['numero_control']);
                                            $claveLector   = $esTrabajador ? $reserva['numero_control'] : $reserva['matricula'];
                                            $codigoLector  = $esTrabajador ? ('T-' . (int)$reserva['numero_control']) : ('A-' . (int)$reserva['matricula']);
                                            $rolLector     = $esTrabajador ? 'Trabajador' : 'Alumno';
                                            $diasPrestamo  = $esTrabajador ? $diasTrabajador : $diasAlumno;
                                            $lectorNombre  = trim($reserva['nombre_lector'] ?? '') !== '' ? $reserva['nombre_lector'] : 'Sin nombre';
                                            $fechaResTexto = date('d/m/Y H:i', strtotime($reserva['fecha_reserva']));
                                            $fechaLimTexto = date('d/m/Y H:i', strtotime($reserva['fecha_limite']));
                                            $textoEjemplar = 'Ejemplar #' . $reserva['id_ejemplar'] . ($reserva['inventario'] ? ' (Inv. ' . $reserva['inventario'] . ')' : '');
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="folio-text"><?= htmlspecialchars($reserva['folio']) ?></span>
                                                    <a
                                                        href="gestion_reservas.php?estado=<?= urlencode($estadoActual) ?>&q=<?= urlencode($busqueda) ?>&p=<?= $paginaActual ?>&fecha_inicio=<?= urlencode($fechaInicio) ?>&fecha_fin=<?= urlencode($fechaFin) ?>&lector=<?= urlencode($codigoLector) ?>#consulta-prestamos"
                                                        class="reader-click-link"
                                                        title="Ver cuántos libros tiene prestados este lector"
                                                    >
                                                        <?= htmlspecialchars($lectorNombre) ?>
                                                    </a>
                                                    <span class="sub"><?= htmlspecialchars((string)$claveLector) ?> · <?= $rolLector ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-medium"><?= htmlspecialchars($reserva['titulo_libro']) ?></span>
                                                    <span class="sub"><?= htmlspecialchars($textoEjemplar) ?></span>
                                                </td>
                                                <td>
                                                    <span>Límite: <?= $fechaLimTexto ?></span>
                                                    <span class="sub">Reservada: <?= $fechaResTexto ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($estadoActual === 'activas' || $estadoActual === 'vencidas'): ?>
                                                        <span class="estado-texto"><?= htmlspecialchars(obtenerTextoTiempo($reserva['fecha_limite'], $ahora)) ?></span>
                                                    <?php elseif ($estadoActual === 'confirmadas'): ?>
                                                        <span class="estado-texto">Entregada<?= $reserva['id_prestamo'] ? ' · Préstamo #' . (int)$reserva['id_prestamo'] : '' ?></span>
                                                    <?php else: ?>
                                                        <span class="estado-secundario">Cancelada</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end text-nowrap">
                                                    <?php if ($estadoActual === 'activas'): ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-brand btn-sm me-1"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#confirmarPrestamoModal"
                                                            data-id="<?= (int)$reserva['id_reserva'] ?>"
                                                            data-folio="<?= htmlspecialchars($reserva['folio'], ENT_QUOTES) ?>"
                                                            data-lector="<?= htmlspecialchars($lectorNombre . ' (' . $rolLector . ')', ENT_QUOTES) ?>"
                                                            data-libro="<?= htmlspecialchars($reserva['titulo_libro'] . ' · ' . $textoEjemplar, ENT_QUOTES) ?>"
                                                            data-dias="<?= $diasPrestamo ?>"
                                                        >
                                                            Confirmar
                                                        </button>
                                                        <a
                                                            href="reserva_cancelar.php?id=<?= (int)$reserva['id_reserva'] ?>&estado=activas"
                                                            class="btn btn-danger-soft btn-sm"
                                                            onclick="return confirm('¿Seguro que deseas cancelar la reserva <?= htmlspecialchars($reserva['folio'], ENT_QUOTES) ?>?');"
                                                        >
                                                            Cancelar
                                                        </a>
                                                    <?php elseif ($estadoActual === 'vencidas'): ?>
                                                        <a
                                                            href="reserva_cancelar.php?id=<?= (int)$reserva['id_reserva'] ?>&estado=vencidas"
                                                            class="btn btn-danger-soft btn-sm"
                                                            onclick="return confirm('¿Deseas liberar el ejemplar de la reserva vencida <?= htmlspecialchars($reserva['folio'], ENT_QUOTES) ?>?');"
                                                        >
                                                            Liberar ejemplar
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="estado-secundario">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">No hay reservas registradas en este apartado.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <?php if ($totalPaginas > 1): ?>
                        <nav class="pagination-reservas d-flex justify-content-center mt-4" aria-label="Paginación de reservas">
                            <ul class="pagination mb-0">
                                <li class="page-item <?= $paginaActual <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="gestion_reservas.php?estado=<?= urlencode($estadoActual) ?>&q=<?= urlencode($busqueda) ?><?= $queryExtraPeriodo ?>&p=<?= $paginaActual - 1 ?>">
                                        <svg class="icon"><use href="#i-arrow-left" /></svg> Anterior
                                    </a>
                                </li>

                                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                    <li class="page-item <?= $i === $paginaActual ? 'active' : '' ?>">
                                        <a class="page-link" href="gestion_reservas.php?estado=<?= urlencode($estadoActual) ?>&q=<?= urlencode($busqueda) ?><?= $queryExtraPeriodo ?>&p=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <li class="page-item <?= $paginaActual >= $totalPaginas ? 'disabled' : '' ?>">
                                    <a class="page-link" href="gestion_reservas.php?estado=<?= urlencode($estadoActual) ?>&q=<?= urlencode($busqueda) ?><?= $queryExtraPeriodo ?>&p=<?= $paginaActual + 1 ?>">
                                        Siguiente <svg class="icon"><use href="#i-arrow-right" /></svg>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>

                    <section id="consulta-prestamos" class="panel p-4 mt-4">
                        <div class="mb-3">
                            <h2 class="h6 fw-semibold section-green-title mb-1">Libros prestados por periodo y por alumno</h2>
                            <p class="small text-secondary mb-0">Elige un rango de fechas para ver el total general, o busca a un alumno por nombre o matrícula para ver sus préstamos.</p>
                        </div>

                        <form id="formConsultaPeriodo" method="GET" action="gestion_reservas.php#consulta-prestamos" class="row g-3 align-items-end">
                            <input type="hidden" name="estado" value="<?= htmlspecialchars($estadoActual) ?>" />
                            <input type="hidden" name="q" value="<?= htmlspecialchars($busqueda) ?>" />
                            <input type="hidden" name="p" value="<?= $paginaActual ?>" />
                            <input type="hidden" name="lector" id="codigoLectorOculto" value="<?= htmlspecialchars($lectorFiltro) ?>" />

                            <div class="col-sm-6 col-lg-2">
                                <label for="fecha_inicio" class="form-label small fw-medium mb-1">Fecha inicial</label>
                                <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control" value="<?= htmlspecialchars($fechaInicio) ?>" />
                            </div>

                            <div class="col-sm-6 col-lg-2">
                                <label for="fecha_fin" class="form-label small fw-medium mb-1">Fecha final</label>
                                <input type="date" name="fecha_fin" id="fecha_fin" class="form-control" value="<?= htmlspecialchars($fechaFin) ?>" />
                            </div>

                            <div class="col-sm-12 col-lg-5">
                                <label for="buscadorLector" class="form-label small fw-medium mb-1">Buscar alumno o trabajador (opcional)</label>
                                <div class="reader-search-wrap position-relative">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0">
                                            <svg class="icon text-secondary"><use href="#i-user" /></svg>
                                        </span>
                                        <input
                                            type="search"
                                            name="lector_texto"
                                            id="buscadorLector"
                                            class="form-control border-start-0"
                                            value="<?= htmlspecialchars($lectorTexto) ?>"
                                            placeholder="Escribe nombre, matrícula o clave (vacío = todos)"
                                            autocomplete="off"
                                        />
                                    </div>
                                    <div id="sugerenciasLector" class="reader-suggestions d-none"></div>
                                </div>
                            </div>

                            <div class="col-sm-12 col-lg-3 d-flex gap-2">
                                <button type="submit" class="btn btn-brand flex-grow-1 text-nowrap">Consultar</button>
                                <?php if ($mostrarResumenPrestamos): ?>
                                    <a href="gestion_reservas.php?estado=<?= urlencode($estadoActual) ?>&q=<?= urlencode($busqueda) ?>&p=<?= $paginaActual ?>" class="btn btn-outline-brand text-nowrap">Limpiar</a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if ($mostrarResumenPrestamos): ?>
                            <?php
                            if ($fechaInicio !== '' && $fechaFin !== '') {
                                $textoRango = 'Periodo: del ' . date('d/m/Y', strtotime($fechaInicio)) . ' al ' . date('d/m/Y', strtotime($fechaFin));
                            } elseif ($fechaInicio !== '') {
                                $textoRango = 'Periodo: desde el ' . date('d/m/Y', strtotime($fechaInicio));
                            } elseif ($fechaFin !== '') {
                                $textoRango = 'Periodo: hasta el ' . date('d/m/Y', strtotime($fechaFin));
                            } else {
                                $textoRango = 'Periodo: todo su historial (puedes elegir fechas arriba para acotar)';
                            }
                            ?>
                            <div class="resultado-periodo-box mt-4">
                                <div class="resultado-periodo-banner">
                                    <span class="resultado-periodo-numero"><?= $totalPrestadosPeriodo ?></span>
                                    <div>
                                        <span class="resultado-periodo-titulo">
                                            <?= $totalPrestadosPeriodo === 1 ? 'libro prestado' : 'libros prestados' ?>
                                            <?php if ($nombreLectorSeleccionado !== ''): ?>
                                                a <strong><?= htmlspecialchars($nombreLectorSeleccionado) ?></strong>
                                            <?php elseif ($lectorTexto !== ''): ?>
                                                para la búsqueda <strong>«<?= htmlspecialchars($lectorTexto) ?>»</strong>
                                            <?php else: ?>
                                                en total en la biblioteca
                                            <?php endif; ?>
                                        </span>
                                        <span class="resultado-periodo-subtitulo"><?= htmlspecialchars($textoRango) ?></span>
                                    </div>
                                </div>

                                <?php if ($totalPrestadosPeriodo > 0): ?>
                                    <div class="table-responsive period-table-wrap mt-3">
                                        <table class="table table-sm table-hover table-lib align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Lector</th>
                                                    <th>Libro</th>
                                                    <th>Fecha de préstamo</th>
                                                    <th>Estado</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($listaPrestamosPeriodo as $prest): ?>
                                                    <?php
                                                    $esTrabP     = !empty($prest['numero_control']);
                                                    $codLectorP  = $esTrabP ? ('T-' . (int)$prest['numero_control']) : ('A-' . (int)$prest['matricula']);
                                                    $claveTextoP = $esTrabP ? $prest['numero_control'] : $prest['matricula'];
                                                    $rolTextoP   = $esTrabP ? 'Trabajador' : 'Alumno';
                                                    $estIdP      = (int)$prest['id_estado_prestamo'];
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <a
                                                                href="gestion_reservas.php?estado=<?= urlencode($estadoActual) ?>&q=<?= urlencode($busqueda) ?>&p=<?= $paginaActual ?>&fecha_inicio=<?= urlencode($fechaInicio) ?>&fecha_fin=<?= urlencode($fechaFin) ?>&lector=<?= urlencode($codLectorP) ?>#consulta-prestamos"
                                                                class="reader-click-link"
                                                            >
                                                                <?= htmlspecialchars($prest['nombre_lector'] ?? 'Sin nombre') ?>
                                                            </a>
                                                            <span class="sub"><?= htmlspecialchars((string)$claveTextoP) ?> · <?= $rolTextoP ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="fw-medium"><?= htmlspecialchars($prest['titulo_libro']) ?></span>
                                                            <span class="sub">Ejemplar #<?= (int)$prest['id_ejemplar'] ?></span>
                                                        </td>
                                                        <td><?= date('d/m/Y', strtotime($prest['fecha_prestamo'])) ?></td>
                                                        <td>
                                                            <?php if ($estIdP === 2): ?>
                                                                <span class="tag tag-ok"><?= htmlspecialchars($prest['nombre_estado']) ?></span>
                                                            <?php else: ?>
                                                                <span class="tag tag-off"><?= htmlspecialchars($prest['nombre_estado']) ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </main>
        </div>

        <div class="modal fade" id="confirmarPrestamoModal" tabindex="-1" aria-labelledby="confirmarPrestamoTitulo" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 rounded-4">
                    <form method="POST" action="gestion_reservas.php?estado=activas">
                        <div class="modal-header border-bottom px-4 py-3">
                            <div>
                                <h2 id="confirmarPrestamoTitulo" class="h5 fw-semibold section-green-title mb-0">Confirmar préstamo</h2>
                                <p class="small text-secondary mb-0">Verifica credencial o recibo y comprobante antes de entregar.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>

                        <div class="modal-body px-4 py-3">
                            <input type="hidden" name="id_reserva" id="modal_id_reserva" value="" />

                            <div class="mb-3">
                                <div class="modal-summary-row">
                                    <span class="text-secondary">Folio</span>
                                    <strong id="modal_folio"></strong>
                                </div>
                                <div class="modal-summary-row">
                                    <span class="text-secondary">Lector</span>
                                    <span id="modal_lector" class="text-end"></span>
                                </div>
                                <div class="modal-summary-row">
                                    <span class="text-secondary">Libro</span>
                                    <span id="modal_libro" class="text-end"></span>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="id_tipo_prestamo" class="form-label fw-medium">Tipo de préstamo</label>
                                <select name="id_tipo_prestamo" id="id_tipo_prestamo" class="form-select">
                                    <?php if ($resTiposPrestamo && mysqli_num_rows($resTiposPrestamo) > 0): ?>
                                        <?php while ($tipo = mysqli_fetch_assoc($resTiposPrestamo)): ?>
                                            <option value="<?= (int)$tipo['id_tipo_prestamo'] ?>">
                                                <?= htmlspecialchars($tipo['tipo']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <p class="small text-secondary mb-0">
                                Fecha límite de devolución asignada: <strong id="modal_fecha_devolucion" class="text-dark"></strong>
                            </p>
                        </div>

                        <div class="modal-footer border-top px-4 py-3">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                            <button type="submit" class="btn btn-brand">Confirmar entrega</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php
        if ($stmtList) {
            mysqli_stmt_close($stmtList);
        }
        ?>
        <script src="./js/bootstrap.bundle.min.js"></script>
        <script src="./js/ver_password.js"></script>
        <script src="./js/gestion_reservas.js?v=3"></script>
    </body>
</html>