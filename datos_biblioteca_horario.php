<?php
require_once __DIR__ . "/includes/sesion.php";
exigir_bibliotecario();
require_once __DIR__ . "/config/conexion.php";

/** @var mysqli $conexion */
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    die("No se pudo establecer la conexión con la base de datos.");
}

$pagina_activa = "datos_biblioteca_horario.php";
$error = "";
$mensaje = $_SESSION["mensaje_horario"] ?? "";
unset($_SESSION["mensaje_horario"]);

if (empty($_SESSION["csrf_horario"])) {
    $_SESSION["csrf_horario"] = bin2hex(random_bytes(32));
}

function escapar_horario($valor) {
    return htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8");
}

// Valores iniciales del diseño, mientras no existan horarios registrados.
$informacionBiblioteca = [
    "ubicacion" => "", "telefono" => "", "correo" => "",
    "descripcion_biblioteca" => "", "mision" => "", "vision" => "",
    "hora_apertura" => "08:00", "hora_cierre" => "15:00",
    "fin_de_semana_cerrado" => 1,
    "cierre_inicio" => "", "cierre_fin" => "", "cierre_nota" => "",
    "actualizado_en" => null, "usuario" => null
];
$informacionDesdeBD = false;
$idCierre = 0;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $resultado = mysqli_query($conexion, "SELECT b.id_biblioteca, b.descripcion AS descripcion_biblioteca, b.mision, b.vision, b.direccion AS ubicacion,
        b.telefono, b.correo, b.fecha_actualizada AS actualizado_en, u.usuario
        FROM tblbiblioteca b LEFT JOIN tblbibliotecario u
        ON b.id_bibliotecario = u.id_bibliotecario ORDER BY b.id_biblioteca ASC LIMIT 1");
    $contacto = mysqli_fetch_assoc($resultado);
    mysqli_free_result($resultado);
    if (!$contacto) {
        throw new RuntimeException("No existe el registro de biblioteca.");
    }
    $informacionBiblioteca = array_replace($informacionBiblioteca, $contacto);
    $idBiblioteca = (int) $contacto["id_biblioteca"];

    // Convención: lunes = 1, domingo = 7.
    $resultado = mysqli_query($conexion, "SELECT dia_semana, hora_apertura, hora_cierre, cerrado
        FROM tblhorarios_biblioteca ORDER BY dia_semana");
    $horarios = [];
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $horarios[(int) $fila["dia_semana"]] = $fila;
    }
    mysqli_free_result($resultado);
    if (isset($horarios[1])) {
        $informacionBiblioteca["hora_apertura"] = $horarios[1]["hora_apertura"] ?? "";
        $informacionBiblioteca["hora_cierre"] = $horarios[1]["hora_cierre"] ?? "";
    }
    if (isset($horarios[6], $horarios[7])) {
        $informacionBiblioteca["fin_de_semana_cerrado"] =
            (int) ($horarios[6]["cerrado"] && $horarios[7]["cerrado"]);
    }

    // El formulario administra un solo periodo: el último registrado.
    $resultado = mysqli_query($conexion, "SELECT id_cierre, fecha_inicio, fecha_fin, nota
        FROM tblcierres_biblioteca ORDER BY id_cierre DESC LIMIT 1");
    if ($cierre = mysqli_fetch_assoc($resultado)) {
        $idCierre = (int) $cierre["id_cierre"];
        $informacionBiblioteca["cierre_inicio"] = $cierre["fecha_inicio"];
        $informacionBiblioteca["cierre_fin"] = $cierre["fecha_fin"];
        $informacionBiblioteca["cierre_nota"] = $cierre["nota"];
    }
    mysqli_free_result($resultado);
    $informacionDesdeBD = true;
} catch (Exception $e) {
    error_log($e->getMessage());
    $error = "No se pudo consultar el horario y contacto.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $informacionDesdeBD) {
    $token = $_POST["csrf"] ?? "";
    if (!is_string($token) || !hash_equals($_SESSION["csrf_horario"], $token)) {
        $error = "El formulario no es válido.";
    } else {
        $datos = [];
        foreach (["descripcion_biblioteca", "mision", "vision", "ubicacion", "telefono", "correo", "hora_apertura", "hora_cierre",
                  "cierre_inicio", "cierre_fin", "cierre_nota"] as $campo) {
            $valor = $_POST[$campo] ?? "";
            if (!is_string($valor)) {
                $error = "Los datos del formulario no son válidos.";
                break;
            }
            $datos[$campo] = trim($valor);
        }
        if ($error === "") {
            $datos["fin_de_semana_cerrado"] = (int) (($_POST["fin_de_semana_cerrado"] ?? "") === "1");
             $informacionBiblioteca = array_replace($informacionBiblioteca, $datos);
            $idBibliotecario = (int) ($_SESSION["id_bibliotecario"] ?? 0);
            $apertura = $datos["hora_apertura"];
            $fin = $datos["hora_cierre"];
            $desde = $datos["cierre_inicio"];
            $hasta = $datos["cierre_fin"];
            $nota = $datos["cierre_nota"];
            $formatoHora = '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D';
            $fechaValida = function ($fecha) {
                $obj = DateTime::createFromFormat("!Y-m-d", $fecha);
                return $obj && $obj->format("Y-m-d") === $fecha;
            };
            if ($idBibliotecario <= 0 || $datos["ubicacion"] === "") {
                $error = "Verifica la dirección y la sesión.";
            } elseif ($datos["descripcion_biblioteca"] === "" || $datos["mision"] === "" || $datos["vision"] === "") {
                $error = "Completa la descripción, misión y visión.";
            } elseif (mb_strlen($datos["descripcion_biblioteca"], "UTF-8") > 3000 || mb_strlen($datos["mision"], "UTF-8") > 3000 || mb_strlen($datos["vision"], "UTF-8") > 3000 || mb_strlen($datos["ubicacion"], "UTF-8") > 255 || mb_strlen($datos["telefono"], "UTF-8") > 40 || mb_strlen($datos["correo"], "UTF-8") > 254 || mb_strlen($nota, "UTF-8") > 500) {
                $error = "Uno de los campos supera la longitud permitida.";
            } elseif (!preg_match($formatoHora, $apertura) || !preg_match($formatoHora, $fin) || $apertura >= $fin) {
                $error = "La hora de cierre debe ser posterior a la apertura.";
            } elseif ($datos["correo"] !== "" && !filter_var($datos["correo"], FILTER_VALIDATE_EMAIL)) {
                $error = "Escribe un correo válido.";
            } elseif (($desde === "") !== ($hasta === "") || ($desde !== "" && (!$fechaValida($desde) || !$fechaValida($hasta) || $desde > $hasta))) {
                $error = "Indica un periodo de cierre válido o deja ambas fechas vacías.";
            } elseif ($desde === "" && $nota !== "") {
                $error = "Indica las fechas para guardar una nota de cierre.";
            }
        }
        if ($error === "") {
            try {
                mysqli_begin_transaction($conexion);
                $consulta = mysqli_prepare($conexion, "UPDATE tblbiblioteca SET descripcion = ?, mision = ?, vision = ?, direccion = ?,
                    telefono = ?, correo = ?, fecha_actualizada = NOW(), id_bibliotecario = ?
                    WHERE id_biblioteca = ?");
                mysqli_stmt_bind_param($consulta, "ssssssii", $datos["descripcion_biblioteca"], $datos["mision"], $datos["vision"], $datos["ubicacion"], $datos["telefono"], $datos["correo"], $idBibliotecario, $idBiblioteca);
                mysqli_stmt_execute($consulta);
                mysqli_stmt_close($consulta);

                $consulta = mysqli_prepare($conexion, "INSERT INTO tblhorarios_biblioteca
                    (dia_semana, hora_apertura, hora_cierre, cerrado) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE hora_apertura = VALUES(hora_apertura),
                    hora_cierre = VALUES(hora_cierre), cerrado = VALUES(cerrado)");
                mysqli_stmt_bind_param($consulta, "issi", $dia, $horaApertura, $horaCierre, $cerrado);
                for ($dia = 1; $dia <= 7; $dia++) {
                    $cerrado = ($dia >= 6 && $datos["fin_de_semana_cerrado"] === 1) ? 1 : 0;
                    $horaApertura = $cerrado ? null : $apertura;
                    $horaCierre = $cerrado ? null : $fin;
                    mysqli_stmt_execute($consulta);
                }
                mysqli_stmt_close($consulta);

                if ($desde !== "") {
                    if ($idCierre > 0) {
                        $consulta = mysqli_prepare($conexion, "UPDATE tblcierres_biblioteca SET fecha_inicio = ?, fecha_fin = ?, nota = ? WHERE id_cierre = ?");
                        mysqli_stmt_bind_param($consulta, "sssi", $desde, $hasta, $nota, $idCierre);
                    } else {
                        $consulta = mysqli_prepare($conexion, "INSERT INTO tblcierres_biblioteca (fecha_inicio, fecha_fin, nota) VALUES (?, ?, ?)");
                        mysqli_stmt_bind_param($consulta, "sss", $desde, $hasta, $nota);
                    }
                    mysqli_stmt_execute($consulta);
                    mysqli_stmt_close($consulta);
                } elseif ($idCierre > 0) {
                    $consulta = mysqli_prepare($conexion, "DELETE FROM tblcierres_biblioteca WHERE id_cierre = ?");
                    mysqli_stmt_bind_param($consulta, "i", $idCierre);
                    mysqli_stmt_execute($consulta);
                    mysqli_stmt_close($consulta);
                }
                mysqli_commit($conexion);
                $_SESSION["mensaje_horario"] = "Los cambios se guardaron correctamente.";
                header("Location: datos_biblioteca_horario.php");
                exit;
            } catch (Exception $e) {
                mysqli_rollback($conexion);
                error_log($e->getMessage());
                $error = "No se pudo guardar el horario y contacto.";
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />

        <title>Nosotros, horario y contacto - Datos de la biblioteca</title>

        <!-- Fuente Poppins (guia de estilos) -->
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />

        <link rel="stylesheet" href="./css/bootstrap.min.css" />
        <link rel="stylesheet" href="./css/bibliotecario.css" />
        <link rel="stylesheet" href="./css/biblioteca_horarios.css" />

    </head>

    <body>
        <!-- Iconos (set Lucide, trazo lineal) -->
        <svg xmlns="http://www.w3.org/2000/svg" style="display: none">
            <symbol id="i-clipboard-list" viewBox="0 0 24 24">
                <rect width="8" height="4" x="8" y="2" rx="1" ry="1" />
                <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" />
                <path d="M12 11h4" />
                <path d="M12 16h4" />
                <path d="M8 11h.01" />
                <path d="M8 16h.01" />
            </symbol>
            <symbol id="i-book-open" viewBox="0 0 24 24">
                <path d="M12 7v14" />
                <path
                    d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"
                />
            </symbol>
            <symbol id="i-layout-dashboard" viewBox="0 0 24 24">
                <rect width="7" height="9" x="3" y="3" rx="1" />
                <rect width="7" height="5" x="14" y="3" rx="1" />
                <rect width="7" height="9" x="14" y="12" rx="1" />
                <rect width="7" height="5" x="3" y="16" rx="1" />
            </symbol>
            <symbol id="i-building" viewBox="0 0 24 24">
                <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z" />
                <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2" />
                <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2" />
                <path d="M10 6h4" />
                <path d="M10 10h4" />
                <path d="M10 14h4" />
                <path d="M10 18h4" />
            </symbol>
            <symbol id="i-lock" viewBox="0 0 24 24">
                <rect width="18" height="11" x="3" y="11" rx="2" ry="2" />
                <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </symbol>
            <symbol id="i-log-out" viewBox="0 0 24 24">
                <path d="m16 17 5-5-5-5" />
                <path d="M21 12H9" />
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            </symbol>
            <symbol id="i-user" viewBox="0 0 24 24">
                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                <circle cx="12" cy="7" r="4" />
            </symbol>
            <symbol id="i-target" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10" />
                <circle cx="12" cy="12" r="6" />
                <circle cx="12" cy="12" r="2" />
            </symbol>
            <symbol id="i-map-pin" viewBox="0 0 24 24">
                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" />
                <circle cx="12" cy="10" r="3" />
            </symbol>
            <symbol id="i-clock" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10" />
                <polyline points="12 6 12 12 16 14" />
            </symbol>
            <symbol id="i-calendar" viewBox="0 0 24 24">
                <path d="M8 2v4" />
                <path d="M16 2v4" />
                <rect width="18" height="18" x="3" y="4" rx="2" />
                <path d="M3 10h18" />
                <path d="m9 16 2 2 4-4" />
            </symbol>
            <symbol id="i-info" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10" />
                <path d="M12 16v-4" />
                <path d="M12 8h.01" />
            </symbol>
            <symbol id="i-circle-check" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10" />
                <path d="m9 12 2 2 4-4" />
            </symbol>
            <symbol id="i-image" viewBox="0 0 24 24">
                <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                <circle cx="9" cy="9" r="2" />
                <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
            </symbol>
            <symbol id="i-upload" viewBox="0 0 24 24">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                <path d="m17 8-5-5-5 5" />
                <path d="M12 3v12" />
            </symbol>
            <symbol id="i-trash" viewBox="0 0 24 24">
                <path d="M3 6h18" />
                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                <path d="M10 11v6" />
                <path d="M14 11v6" />
            </symbol>
            <symbol id="i-plus" viewBox="0 0 24 24">
                <path d="M5 12h14" />
                <path d="M12 5v14" />
            </symbol>
            <symbol id="i-bell" viewBox="0 0 24 24">
                <path d="M3 20a1 1 0 0 1-1-1v-1a1 1 0 0 1 1-1h18a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1Z" />
                <path d="M20 16a8 8 0 1 0-16 0" />
                <path d="M12 4v4" />
                <path d="M10 4h4" />
            </symbol>
            <symbol id="i-list-ordered" viewBox="0 0 24 24">
                <path d="M10 12h11" />
                <path d="M10 18h11" />
                <path d="M10 6h11" />
                <path d="M4 10h2" />
                <path d="M4 6h1v4" />
                <path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1" />
            </symbol>
            <symbol id="i-list-checks" viewBox="0 0 24 24">
                <path d="m3 17 2 2 4-4" />
                <path d="m3 7 2 2 4-4" />
                <path d="M13 6h8" />
                <path d="M13 12h8" />
                <path d="M13 18h8" />
            </symbol>
            <symbol id="i-circle-help" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10" />
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                <path d="M12 17h.01" />
            </symbol>
            <symbol id="i-shield-check" viewBox="0 0 24 24">
                <path
                    d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"
                />
                <path d="m9 12 2 2 4-4" />
            </symbol>
            <symbol id="i-triangle-alert" viewBox="0 0 24 24">
                <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3" />
                <path d="M12 9v4" />
                <path d="M12 17h.01" />
            </symbol>
            <symbol id="i-chevron-down" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6" /></symbol>
            <symbol id="i-chevron-right" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6" /></symbol>
            <symbol id="i-school" viewBox="0 0 24 24">
                <path d="M14 22v-4a2 2 0 1 0-4 0v4" />
                <path d="m18 10 3.447 1.724a1 1 0 0 1 .553.894V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-7.382a1 1 0 0 1 .553-.894L6 10" />
                <path d="M18 5v17" />
                <path d="m4 6 7.106-3.553a2 2 0 0 1 1.788 0L20 6" />
                <path d="M6 5v17" />
                <circle cx="12" cy="9" r="2" />
            </symbol>
        </svg>

        <div class="admin-layout">
            <!-- Menu lateral del bibliotecario -->
            <?php include "includes/sidebar_bibliotecario.php"; ?>

            <!-- Contenido -->
            <main class="admin-main">
                <div class="admin-content">
                    <nav class="breadcrumb-line" aria-label="Ruta de navegación">
                        <a href="datos_biblioteca.php">Datos de la biblioteca</a>
                        <svg class="icon"><use href="#i-chevron-right" /></svg>
                        <span>Nosotros, horario y contacto</span>
                    </nav>
                    <h1 class="page-title">Nosotros, horario y contacto</h1>
                    <p class="page-subtitle">Edita la información institucional que se muestra en Nosotros, el horario y los datos de contacto.</p>

                    <?php if ($error !== ""): ?>
                        <div class="alert alert-danger" role="alert"><?= escapar_horario($error) ?></div>
                    <?php endif; ?>
                    <?php if ($mensaje !== ""): ?>
                        <div class="alert alert-success" role="status"><?= escapar_horario($mensaje) ?></div>
                    <?php endif; ?>
                    <?php if (!$informacionDesdeBD): ?>
                        <div class="alert alert-warning" role="alert">
                            No se encontró el registro de información institucional. Ejecuta primero el archivo
                            <strong>sql/nosotros.sql</strong>; los valores actuales son provisionales.
                        </div>
                    <?php endif; ?>

                    <form action="" method="POST">
                        <input type="hidden" name="csrf" value="<?= escapar_horario($_SESSION["csrf_horario"]) ?>" />
                        <div class="form-layout">
                            <!-- Columna izquierda -->
                            <div class="form-column">
                                <section class="form-card">
                                    <div class="card-header-row has-subtitle">
                                        <div class="card-icon">
                                            <svg class="icon"><use href="#i-school" /></svg>
                                        </div>
                                        <div>
                                            <h2 class="card-title">Información de la biblioteca</h2>
                                            <p class="card-subtitle">Estos textos se muestran en la página pública Nosotros.</p>
                                        </div>
                                    </div>

                                    <div class="field-block">
                                        <label for="descripcion_biblioteca" class="field-label">Descripción de la biblioteca</label>
                                        <textarea
                                            id="descripcion_biblioteca"
                                            name="descripcion_biblioteca"
                                            class="form-control"
                                            rows="3"
                                            maxlength="3000"
                                            required
                                        ><?= escapar_horario($informacionBiblioteca["descripcion_biblioteca"]) ?></textarea>
                                    </div>

                                    <div class="field-block">
                                        <label for="mision" class="field-label">Misión</label>
                                        <textarea id="mision" name="mision" class="form-control" rows="3" maxlength="3000" required><?= escapar_horario($informacionBiblioteca["mision"]) ?></textarea>
                                    </div>

                                    <div class="field-block mb-0">
                                        <label for="vision" class="field-label">Visión</label>
                                        <textarea id="vision" name="vision" class="form-control" rows="3" maxlength="3000" required><?= escapar_horario($informacionBiblioteca["vision"]) ?></textarea>
                                    </div>
                                </section>

                                <section class="form-card">
                                    <div class="card-header-row">
                                        <div class="card-icon">
                                            <svg class="icon"><use href="#i-clock" /></svg>
                                        </div>
                                        <h2 class="card-title">Horario de atención</h2>
                                    </div>

                                    <p class="group-label">Lunes a viernes</p>
                                    <div class="row g-3 mb-3">
                                        <div class="col-6">
                                            <label for="hora_apertura" class="field-label">Apertura</label>
                                            <input
                                                type="time"
                                                id="hora_apertura"
                                                name="hora_apertura"
                                                class="form-control"
                                                value="<?= escapar_horario(substr($informacionBiblioteca["hora_apertura"], 0, 5)) ?>"
                                                required
                                            />
                                        </div>
                                        <div class="col-6">
                                            <label for="hora_cierre" class="field-label">Cierre</label>
                                            <input
                                                type="time"
                                                id="hora_cierre"
                                                name="hora_cierre"
                                                class="form-control"
                                                value="<?= escapar_horario(substr($informacionBiblioteca["hora_cierre"], 0, 5)) ?>"
                                                required
                                            />
                                        </div>
                                    </div>

                                    <p class="group-label">Sábado y domingo</p>
                                    <div class="toggle-row">
                                        <span>Cerrados</span>
                                        <label class="switch" aria-label="Sábado y domingo cerrados">
                                            <input
                                                type="checkbox"
                                                name="fin_de_semana_cerrado"
                                                value="1"
                                                <?= (int) $informacionBiblioteca["fin_de_semana_cerrado"] === 1 ? "checked" : "" ?>
                                            />
                                            <span class="switch-track"></span>
                                        </label>
                                    </div>

                                    <p class="help-text">
                                        <svg class="icon"><use href="#i-info" /></svg>
                                        <span
                                            >La página Nosotros muestra este horario. Si abres en fin de semana, se usará el mismo horario indicado arriba.</span
                                        >
                                    </p>
                                </section>

                                <section class="form-card">
                                    <div class="card-header-row has-subtitle">
                                        <div class="card-icon">
                                            <svg class="icon"><use href="#i-calendar" /></svg>
                                        </div>
                                        <div>
                                            <h2 class="card-title">Cierre temporal</h2>
                                            <p class="card-subtitle">Opcional. Por ejemplo, un periodo vacacional.</p>
                                        </div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-6">
                                            <label for="cierre_inicio" class="field-label"
                                                >Desde <span class="optional">(opcional)</span></label
                                            >
                                            <input
                                                type="date"
                                                id="cierre_inicio"
                                                name="cierre_inicio"
                                                class="form-control"
                                                value="<?= escapar_horario($informacionBiblioteca["cierre_inicio"] ?? "") ?>"
                                            />
                                        </div>
                                        <div class="col-6">
                                            <label for="cierre_fin" class="field-label"
                                                >Hasta <span class="optional">(opcional)</span></label
                                            >
                                            <input
                                                type="date"
                                                id="cierre_fin"
                                                name="cierre_fin"
                                                class="form-control"
                                                value="<?= escapar_horario($informacionBiblioteca["cierre_fin"] ?? "") ?>"
                                            />
                                        </div>
                                    </div>

                                    <div class="field-block">
                                        <label for="cierre_nota" class="field-label">Nota <span class="optional">(opcional)</span></label>
                                        <textarea
                                            id="cierre_nota"
                                            name="cierre_nota"
                                            class="form-control"
                                            rows="3"
                                            maxlength="500"
                                            placeholder="Ej. Periodo vacacional de verano"
                                        ><?= escapar_horario($informacionBiblioteca["cierre_nota"] ?? "") ?></textarea>
                                    </div>

                                    <p class="help-text">
                                        <svg class="icon"><use href="#i-info" /></svg>
                                        <span
                                            >Si hoy cae dentro de este rango, la biblioteca se muestra como <strong>Cerrada</strong> aunque
                                            el horario normal diga otra cosa. La nota aparece en la página Nosotros.</span
                                        >
                                    </p>
                                </section>
                            </div>

                            <!-- Columna derecha -->
                            <div class="form-column">
                                <section class="form-card">
                                    <div class="card-header-row has-subtitle">
                                        <div class="card-icon">
                                            <svg class="icon"><use href="#i-map-pin" /></svg>
                                        </div>
                                        <div>
                                            <h2 class="card-title">Contacto</h2>
                                            <p class="card-subtitle">Se muestra en la página Nosotros y en su pie de página.</p>
                                        </div>
                                    </div>

                                    <div class="field-block">
                                        <label for="direccion" class="field-label">Dirección</label>
                                        <input
                                            type="text"
                                            id="direccion"
                                            name="ubicacion"
                                            class="form-control"
                                            value="<?= escapar_horario($informacionBiblioteca["ubicacion"]) ?>"
                                            required
                                        />
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label for="telefono" class="field-label">Teléfono</label>
                                            <input
                                                type="tel"
                                                id="telefono"
                                                name="telefono"
                                                class="form-control"
                                                maxlength="40"
                                                value="<?= escapar_horario($informacionBiblioteca["telefono"] ?? "") ?>"
                                            />
                                        </div>
                                        <div class="col-12">
                                            <label for="correo" class="field-label">Correo</label>
                                            <input
                                                type="email"
                                                id="correo"
                                                name="correo"
                                                class="form-control"
                                                maxlength="254"
                                                value="<?= escapar_horario($informacionBiblioteca["correo"] ?? "") ?>"
                                            />
                                        </div>
                                    </div>
                                </section>

                                <!-- Ultima actualizacion -->
                                <div class="update-box">
                                    <p class="label">Última actualización</p>
                                    <p class="value">
                                        <?= $informacionBiblioteca["actualizado_en"] ? escapar_horario(date("d/m/Y H:i", strtotime($informacionBiblioteca["actualizado_en"]))) : "Sin registro" ?>
                                    </p>
                                    <p class="note">La fecha se actualiza al guardar los cambios.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Botones -->
                        <div class="actions-row">
                            <a href="panel_control.php" class="btn btn-secondary-brand">Cancelar</a>
                            <button type="submit" class="btn btn-primary-brand" <?= !$informacionDesdeBD ? "disabled" : "" ?>>
                                <svg class="icon"><use href="#i-circle-check" /></svg> Guardar cambios
                            </button>
                        </div>
                    </form>
                </div>
            </main>
        </div>

        <?php include "includes/iconos.php"; ?>
        <?php include "includes/iconos_catalogo.php"; ?>

        <script src="./js/bootstrap.bundle.min.js"></script>
        <script src="./js/ver_password.js"></script>
    </body>
</html>