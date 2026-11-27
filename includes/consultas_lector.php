<?php
// Consultas de Mi cuenta del lector (préstamos, reservas, adeudos y avisos).
//
// Usa las tablas que ya tiene la base, no crea ninguna nueva:
//   tblprestamos   : id_prestamo, id_ejemplar, matricula, numero_control, id_estado_prestamo, fecha_prestamo, fecha_devolucion
//   tblreservas    : id_reserva, folio, id_ejemplar, matricula, numero_control, id_estado_reserva, fecha_reserva, fecha_limite
//   tblejemplares  : id_ejemplar, id_libro, inventario
//   tbllibros      : id_libro, titulo_libro
//   tblmultas      : id_multa, id_prestamo, monto, motivo, fecha_pago, dias_atrasados, estado_pago
//   tblreposiciones: id_reposicion, id_prestamo, motivo_reposicion, fecha_reporte, estado_reposicion, fecha_cumplimiento
//   tblavisos      : id_aviso, matricula, numero_control, id_tipo_aviso, mensaje, id_reserva, id_prestamo, leido, fecha_aviso
//   tbltipo_aviso  : id_tipo_aviso, tipo_aviso
//   tblalumnos / tbltrabajadores / tblcarreras : datos de la persona
//
// Estados de la base:
//   reserva  -> 1 Activa, 2 Confirmada (ya es préstamo), 3 Cancelada
//   préstamo -> 1 Prestado, 2 Devuelto, 3 Vencido (1 y 3 son "en curso": el libro sigue con el lector)
//
// Adeudos:
//   - Salen de tblmultas (multa por retraso) y de tblreposiciones (libro perdido o dañado).
//   - Si un préstamo sigue sin devolverse y ya venció, todavía no tiene multa registrada: se muestra
//     la que lleva acumulada (días de retraso x MULTA_POR_DIA) hasta que el bibliotecario la registre.
// Avisos:
//   - Los que registra la biblioteca salen de tblavisos (con su marca de leído).
//   - Además se arman al momento los de vencimientos y reservas del lector.

require_once __DIR__ . "/bd.php";
require_once __DIR__ . "/utiles.php";

// Estados de tblprestamos en los que el libro sigue con el lector
const PRESTAMO_EN_CURSO = "1, 3";

// Estados de tblreservas
const RESERVA_ACTIVA = 1;
const RESERVA_CONFIRMADA = 2;
const RESERVA_CANCELADA = 3;

// ---------- Quién es el lector ----------

// Los alumnos se identifican por matrícula y los trabajadores por número de control.
// Regresa [condición SQL, parámetros] para usarla en un WHERE.
function condicion_lector($alias = "")
{
    $prefijo = $alias === "" ? "" : $alias . ".";
    $matricula = $_SESSION["matricula"] ?? "";

    if ($matricula !== "" && $matricula !== null) {
        return [$prefijo . "matricula = ?", [$matricula]];
    }

    return [$prefijo . "numero_control = ?", [$_SESSION["numero_control"] ?? 0]];
}

// "Alumno" o "Trabajador", según con qué clave entró
function tipo_de_lector()
{
    return ($_SESSION["matricula"] ?? "") !== "" ? "Alumno" : "Trabajador";
}

// Nombre completo del lector (en la sesión solo está el nombre de pila)
function nombre_completo_del_lector($pdo)
{
    $matricula = $_SESSION["matricula"] ?? "";

    if ($matricula !== "" && $matricula !== null) {
        $fila = consultar_una($pdo, "SELECT nombre, apellido_paterno, apellido_materno FROM tblalumnos WHERE matricula = ?", [$matricula], false);
    } else {
        $fila = consultar_una($pdo, "SELECT nombre, apellido_paterno, apellido_materno FROM tbltrabajadores WHERE numero_control = ?", [$_SESSION["numero_control"] ?? 0], false);
    }

    if (!$fila) {
        return $_SESSION["nombre"] ?? "";
    }

    return trim(($fila["nombre"] ?? "") . " " . ($fila["apellido_paterno"] ?? "") . " " . ($fila["apellido_materno"] ?? ""));
}

// Carrera del alumno: tblalumnos guarda el id_carrera y el nombre está en tblcarreras.
// Si no se encuentra, regresa "" (y Mi cuenta muestra solo Alumno / Trabajador).
function carrera_del_lector($pdo)
{
    $matricula = $_SESSION["matricula"] ?? "";

    if ($matricula === "" || $matricula === null) {
        return "";
    }

    $alumno = consultar_una($pdo, "SELECT * FROM tblalumnos WHERE matricula = ?", [$matricula], false);

    if (!empty($alumno["id_carrera"])) {
        $nombre = consultar_valor($pdo, "SELECT nombre_carrera FROM tblcarreras WHERE id_carrera = ?", [$alumno["id_carrera"]], false);

        if (!empty($nombre)) {
            return (string) $nombre;
        }
    }

    // Por si en alguna copia de la base la carrera viene escrita directo en el alumno
    foreach (["carrera", "especialidad"] as $columna) {
        if (!empty($alumno[$columna])) {
            return (string) $alumno[$columna];
        }
    }

    return "";
}

// ---------- Préstamos ----------

// Préstamos en curso del lector, del que vence primero al que vence después
function prestamos_del_lector($pdo)
{
    [$condicion, $parametros] = condicion_lector("p");

    $filas = consultar(
        $pdo,
        "SELECT p.id_prestamo, p.fecha_prestamo, p.fecha_devolucion, e.inventario, l.titulo_libro
         FROM tblprestamos p
         INNER JOIN tblejemplares e ON e.id_ejemplar = p.id_ejemplar
         INNER JOIN tbllibros l ON l.id_libro = e.id_libro
         WHERE $condicion AND p.id_estado_prestamo IN (" . PRESTAMO_EN_CURSO . ")
         ORDER BY p.fecha_devolucion ASC",
        $parametros
    );

    return calcular_prestamos($filas);
}

// ---------- Reservas ----------

// Reservas Activas del lector que todavía no vencen
function reservas_del_lector($pdo)
{
    [$condicion, $parametros] = condicion_lector("r");
    $parametros[] = date("Y-m-d H:i:s");

    return consultar(
        $pdo,
        "SELECT r.id_reserva, r.folio, r.fecha_reserva, r.fecha_limite, e.inventario, l.titulo_libro
         FROM tblreservas r
         INNER JOIN tblejemplares e ON e.id_ejemplar = r.id_ejemplar
         INNER JOIN tbllibros l ON l.id_libro = e.id_libro
         WHERE $condicion AND r.id_estado_reserva = " . RESERVA_ACTIVA . " AND r.fecha_limite >= ?
         ORDER BY r.fecha_limite ASC",
        $parametros
    );
}

// Una reserva Activa de este lector (para el comprobante). Si es de otra persona regresa null.
function reserva_del_lector($pdo, $id_reserva)
{
    [$condicion, $parametros] = condicion_lector("r");
    array_unshift($parametros, $id_reserva);
    $parametros[] = date("Y-m-d H:i:s");

    return consultar_una(
        $pdo,
        "SELECT r.id_reserva, r.folio, r.fecha_reserva, r.fecha_limite,
                e.id_libro, e.inventario, l.titulo_libro
         FROM tblreservas r
         INNER JOIN tblejemplares e ON e.id_ejemplar = r.id_ejemplar
         INNER JOIN tbllibros l ON l.id_libro = e.id_libro
         WHERE r.id_reserva = ? AND $condicion AND r.id_estado_reserva = " . RESERVA_ACTIVA . " AND r.fecha_limite >= ?",
        $parametros
    );
}

// Cancela una reserva Activa del lector. Regresa true si se canceló.
// Usa el mismo cambio que reserva_cancelar.php del bibliotecario (estado 3), así el ejemplar queda libre.
function cancelar_reserva_del_lector($pdo, $id_reserva)
{
    [$condicion, $parametros] = condicion_lector();
    array_unshift($parametros, $id_reserva);

    $cambiadas = ejecutar(
        $pdo,
        "UPDATE tblreservas SET id_estado_reserva = " . RESERVA_CANCELADA . "
         WHERE id_reserva = ? AND $condicion AND id_estado_reserva = " . RESERVA_ACTIVA,
        $parametros
    );

    return $cambiadas === 1;
}

// Autores de un libro, separados por coma ("" si no tiene)
function autores_del_libro($pdo, $id_libro)
{
    $filas = consultar(
        $pdo,
        "SELECT a.nombre, a.apellido_paterno, a.apellido_materno
         FROM tbllibro_autor la
         INNER JOIN tblautores a ON a.id_autor = la.id_autor
         WHERE la.id_libro = ?",
        [$id_libro],
        false
    );

    $nombres = [];

    foreach ($filas as $fila) {
        $nombres[] = trim(($fila["nombre"] ?? "") . " " . ($fila["apellido_paterno"] ?? "") . " " . ($fila["apellido_materno"] ?? ""));
    }

    return implode(", ", $nombres);
}

// ---------- Adeudos ----------

// true si el adeudo ya quedó resuelto: multa pagada o reposición cumplida
function adeudo_resuelto($estado, $fecha_cumplimiento = null)
{
    if (!empty($fecha_cumplimiento)) {
        return true;
    }

    return preg_match("/pagad|cumpl|repuest|entreg/i", (string) $estado) === 1;
}

// Todos los adeudos del lector: multas por retraso y reposiciones. Regresa [lista, resumen].
//
// Cada adeudo de la lista trae:
//   tipo, titulo_libro, inventario, detalle, monto (null en las reposiciones),
//   pendiente (true/false), estado, fecha_texto, acumulando (true si la multa todavía sigue creciendo)
// Los pendientes salen primero y luego los ya resueltos.
//
// El resumen trae: total (suma de las multas pendientes), multas (cuántas pendientes),
// reposiciones (cuántas pendientes) y pendientes (multas + reposiciones).
function adeudos_del_lector($pdo)
{
    [$condicion, $parametros] = condicion_lector("p");

    $multas = consultar(
        $pdo,
        "SELECT m.id_multa, m.id_prestamo, m.monto, m.motivo, m.fecha_pago, m.dias_atrasados, m.estado_pago,
                e.inventario, l.titulo_libro
         FROM tblmultas m
         INNER JOIN tblprestamos p ON p.id_prestamo = m.id_prestamo
         LEFT JOIN tblejemplares e ON e.id_ejemplar = p.id_ejemplar
         LEFT JOIN tbllibros l ON l.id_libro = e.id_libro
         WHERE $condicion
         ORDER BY m.id_multa DESC",
        $parametros
    );

    $reposiciones = consultar(
        $pdo,
        "SELECT r.id_reposicion, r.motivo_reposicion, r.fecha_reporte, r.estado_reposicion, r.fecha_cumplimiento,
                e.inventario, l.titulo_libro
         FROM tblreposiciones r
         INNER JOIN tblprestamos p ON p.id_prestamo = r.id_prestamo
         LEFT JOIN tblejemplares e ON e.id_ejemplar = p.id_ejemplar
         LEFT JOIN tbllibros l ON l.id_libro = e.id_libro
         WHERE $condicion
         ORDER BY r.id_reposicion DESC",
        $parametros
    );

    $acumulando = [];
    $multas_pendientes = [];
    $multas_pagadas = [];
    $reposiciones_pendientes = [];
    $reposiciones_cumplidas = [];
    $con_multa = [];
    $total = 0;

    foreach ($multas as $m) {
        $con_multa[$m["id_prestamo"]] = true;

        $resuelta = adeudo_resuelto($m["estado_pago"]);
        $dias = (int) $m["dias_atrasados"];

        $adeudo = [
            "tipo" => "Multa por retraso",
            "titulo_libro" => !empty($m["titulo_libro"]) ? $m["titulo_libro"] : (string) $m["motivo"],
            "inventario" => $m["inventario"],
            "detalle" => plural($dias, "día", "días") . " de retraso",
            "monto" => (float) $m["monto"],
            "pendiente" => !$resuelta,
            "estado" => $resuelta ? "Pagado" : "Pendiente",
            "fecha_texto" => $resuelta && !empty($m["fecha_pago"]) ? "Pagada el " . fecha_corta($m["fecha_pago"]) : "",
            "acumulando" => false,
        ];

        if ($resuelta) {
            $multas_pagadas[] = $adeudo;
        } else {
            $multas_pendientes[] = $adeudo;
            $total += $adeudo["monto"];
        }
    }

    foreach ($reposiciones as $r) {
        $cumplida = adeudo_resuelto($r["estado_reposicion"], $r["fecha_cumplimiento"]);

        $adeudo = [
            "tipo" => "Reposición",
            "titulo_libro" => !empty($r["titulo_libro"]) ? $r["titulo_libro"] : "Libro sin título",
            "inventario" => $r["inventario"],
            "detalle" => (string) $r["motivo_reposicion"],
            "monto" => null,
            "pendiente" => !$cumplida,
            "estado" => $cumplida ? "Cumplida" : "Pendiente",
            "fecha_texto" => $cumplida
                ? (!empty($r["fecha_cumplimiento"]) ? "Cumplida el " . fecha_corta($r["fecha_cumplimiento"]) : "")
                : "Reportada el " . fecha_corta($r["fecha_reporte"]),
            "acumulando" => false,
        ];

        if ($cumplida) {
            $reposiciones_cumplidas[] = $adeudo;
        } else {
            $reposiciones_pendientes[] = $adeudo;
        }
    }

    // Préstamos vencidos que siguen sin devolverse: todavía no tienen multa registrada,
    // así que se muestra la que llevan acumulada hasta ahora
    foreach (prestamos_del_lector($pdo) as $p) {
        if ($p["vencido"] && empty($con_multa[$p["id_prestamo"]])) {
            $acumulando[] = [
                "tipo" => "Multa por retraso",
                "titulo_libro" => $p["titulo_libro"],
                "inventario" => $p["inventario"],
                "detalle" => plural($p["retraso"], "día", "días") . " de retraso",
                "monto" => (float) $p["multa"],
                "pendiente" => true,
                "estado" => "Pendiente",
                "fecha_texto" => "Sigue sin devolverse: la multa aumenta cada día",
                "acumulando" => true,
            ];

            $total += $p["multa"];
        }
    }

    $lista = array_merge($acumulando, $multas_pendientes, $reposiciones_pendientes, $multas_pagadas, $reposiciones_cumplidas);

    $resumen = [
        "total" => $total,
        "multas" => count($acumulando) + count($multas_pendientes),
        "reposiciones" => count($reposiciones_pendientes),
        "pendientes" => count($acumulando) + count($multas_pendientes) + count($reposiciones_pendientes),
    ];

    return [$lista, $resumen];
}

// ---------- Avisos ----------

// Avisos que la biblioteca registró para el lector (tblavisos). "Nuevo" = todavía no leído.
function avisos_guardados_del_lector($pdo)
{
    [$condicion, $parametros] = condicion_lector("a");

    $filas = consultar(
        $pdo,
        "SELECT a.id_aviso, a.mensaje, a.leido, a.fecha_aviso, t.tipo_aviso
         FROM tblavisos a
         LEFT JOIN tbltipo_aviso t ON t.id_tipo_aviso = a.id_tipo_aviso
         WHERE $condicion
         ORDER BY a.fecha_aviso DESC, a.id_aviso DESC",
        $parametros
    );

    $avisos = [];

    foreach ($filas as $fila) {
        $avisos[] = [
            "icono" => "i-bell",
            "titulo" => !empty($fila["tipo_aviso"]) ? $fila["tipo_aviso"] : "Aviso de la biblioteca",
            "texto" => (string) $fila["mensaje"],
            "fecha" => $fila["fecha_aviso"],
            "nuevo" => (int) $fila["leido"] === 0,
            "guardado" => true,
        ];
    }

    return $avisos;
}

// Marca como leídos todos los avisos guardados del lector. Regresa cuántos cambió (o false si falló).
function marcar_avisos_leidos($pdo)
{
    [$condicion, $parametros] = condicion_lector();

    return ejecutar(
        $pdo,
        "UPDATE tblavisos SET leido = 1 WHERE $condicion AND (leido = 0 OR leido IS NULL)",
        $parametros
    );
}

// Avisos del lector, del más reciente al más antiguo: los de tblavisos y los que se arman al momento
// con sus préstamos y reservas. Cada uno trae: icono, titulo, texto, fecha (Y-m-d H:i:s), nuevo (true/false).
// "Nuevo" son los que piden atención (vencidos, por vencer) y los guardados que no ha leído.
function avisos_del_lector($pdo)
{
    $avisos = avisos_guardados_del_lector($pdo);

    foreach (prestamos_del_lector($pdo) as $p) {
        if ($p["vencido"]) {
            $avisos[] = [
                "icono" => "i-triangle-alert",
                "titulo" => "Préstamo vencido",
                "texto" => "Tu préstamo de " . $p["titulo_libro"] . " tiene " . plural($p["retraso"], "día", "días")
                    . " de retraso. Multa acumulada: " . dinero($p["multa"]) . ".",
                "fecha" => $p["fecha_devolucion"],
                "nuevo" => true,
            ];
        } elseif ($p["dias"] <= 2) {
            $cuando = $p["dias"] === 0 ? "vence hoy" : ($p["dias"] === 1 ? "vence mañana" : "vence en 2 días");
            $avisos[] = [
                "icono" => "i-clock",
                "titulo" => "Préstamo por vencer",
                "texto" => "Tu préstamo de " . $p["titulo_libro"] . " " . $cuando . " (" . fecha_corta($p["fecha_devolucion"]) . ").",
                "fecha" => $p["fecha_devolucion"],
                "nuevo" => true,
            ];
        }
    }

    [$condicion, $parametros] = condicion_lector("r");
    $parametros[] = date("Y-m-d H:i:s", strtotime("-7 days"));

    $reservas = consultar(
        $pdo,
        "SELECT r.id_estado_reserva, r.fecha_reserva, r.fecha_limite, l.titulo_libro
         FROM tblreservas r
         INNER JOIN tblejemplares e ON e.id_ejemplar = r.id_ejemplar
         INNER JOIN tbllibros l ON l.id_libro = e.id_libro
         WHERE $condicion AND r.fecha_reserva >= ?
         ORDER BY r.fecha_reserva DESC",
        $parametros
    );

    foreach ($reservas as $r) {
        $estado = (int) $r["id_estado_reserva"];

        if ($estado === RESERVA_ACTIVA && segundos_para($r["fecha_limite"]) >= 0) {
            $urgente = segundos_para($r["fecha_limite"]) < 86400;

            $avisos[] = [
                "icono" => $urgente ? "i-clock" : "i-bookmark",
                "titulo" => $urgente ? "Reserva por vencer" : "Reserva registrada",
                "texto" => "Recoge " . $r["titulo_libro"] . " antes del " . fecha_con_hora($r["fecha_limite"]) . ".",
                "fecha" => $r["fecha_reserva"],
                "nuevo" => $urgente,
            ];
        } elseif ($estado === RESERVA_ACTIVA) {
            $avisos[] = [
                "icono" => "i-circle-x",
                "titulo" => "Reserva vencida",
                "texto" => "No recogiste " . $r["titulo_libro"] . " a tiempo y la reserva venció.",
                "fecha" => $r["fecha_limite"],
                "nuevo" => false,
            ];
        } elseif ($estado === RESERVA_CONFIRMADA) {
            $avisos[] = [
                "icono" => "i-circle-check",
                "titulo" => "Reserva entregada",
                "texto" => "Recogiste " . $r["titulo_libro"] . " y quedó registrado como préstamo.",
                "fecha" => $r["fecha_reserva"],
                "nuevo" => false,
            ];
        } else {
            $avisos[] = [
                "icono" => "i-circle-x",
                "titulo" => "Reserva cancelada",
                "texto" => "Tu reserva de " . $r["titulo_libro"] . " (hecha el " . fecha_corta($r["fecha_reserva"]) . ") fue cancelada.",
                "fecha" => $r["fecha_reserva"],
                "nuevo" => false,
            ];
        }
    }

    // Más reciente primero
    usort($avisos, function ($a, $b) {
        return strtotime($b["fecha"]) <=> strtotime($a["fecha"]);
    });

    return $avisos;
}

// Cuántos avisos nuevos tiene (el número rojo de la campana)
function contar_avisos_nuevos($pdo)
{
    $nuevos = 0;

    foreach (avisos_del_lector($pdo) as $aviso) {
        if ($aviso["nuevo"]) {
            $nuevos++;
        }
    }

    return $nuevos;
}
