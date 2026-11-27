<?php
require_once __DIR__ . "/includes/sesion.php";
exigir_bibliotecario();
require_once __DIR__ . "/config/conexion.php";

/** @var mysqli $conexion */
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    die("No se pudo establecer la conexión con la base de datos.");
}

$pagina_activa = "datos_biblioteca_inicio.php";
$error = "";
$mensaje = $_SESSION["mensaje_inicio"] ?? "";
unset($_SESSION["mensaje_inicio"]);
$id_bibliotecario = (int) ($_SESSION["id_bibliotecario"] ?? 0);

if (empty($_SESSION["csrf_inicio"])) {
    $_SESSION["csrf_inicio"] = bin2hex(random_bytes(32));
}

// Claves de configuración utilizadas por el inicio público.
$campos = [];
foreach (["servicio" => 3, "paso" => 4] as $tipo => $cantidad) {
    for ($i = 1; $i <= $cantidad; $i++) {
        $campos[$tipo . "_" . $i . "_titulo"] = [$tipo . "_titulo", $i - 1];
        $campos[$tipo . "_" . $i . "_descripcion"] = [$tipo . "_contenido", $i - 1];
    }
}
$datos = array_fill_keys(array_keys($campos), "");
$fecha = "";
$usuario = "";
$carga_correcta = false;
$limite = 0;

// Consultar la longitud real de valor, ya que en la captura aparece recortada.
try {
    $resultado = mysqli_query($conexion, "SELECT CHARACTER_MAXIMUM_LENGTH AS limite
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblconfiguracion'
        AND COLUMN_NAME = 'valor'");
    if ($resultado === false) {
        throw new RuntimeException("No se pudo consultar la estructura.");
    }
    $columna = mysqli_fetch_assoc($resultado);
    mysqli_free_result($resultado);
    $limite = (int) ($columna["limite"] ?? 0);
    if ($limite < 19) {
        throw new RuntimeException("La columna valor no tiene capacidad suficiente.");
    }

    $resultado = mysqli_query($conexion, "SELECT clave, valor FROM tblconfiguracion");
    if ($resultado === false) {
        throw new RuntimeException("Error de consulta.");
    }
    $id_actualizador = 0;
    while ($fila = mysqli_fetch_assoc($resultado)) {
        $clave = $fila["clave"];
        if (array_key_exists($clave, $datos)) {
            $datos[$clave] = $fila["valor"];
        } elseif ($clave === "inicio_fecha_actualizada") {
            $fecha = $fila["valor"];
        } elseif ($clave === "inicio_id_bibliotecario") {
            $id_actualizador = (int) $fila["valor"];
        }
    }
    mysqli_free_result($resultado);

    if ($id_actualizador > 0) {
        $consulta = mysqli_prepare($conexion, "SELECT usuario FROM tblbibliotecario WHERE id_bibliotecario = ?");
        if (!$consulta || !mysqli_stmt_bind_param($consulta, "i", $id_actualizador) || !mysqli_stmt_execute($consulta)) {
            throw new RuntimeException("Error al consultar el usuario.");
        }
        mysqli_stmt_bind_result($consulta, $usuario_bd);
        if (mysqli_stmt_fetch($consulta)) {
            $usuario = $usuario_bd;
        }
        mysqli_stmt_close($consulta);
    }
    $carga_correcta = true;
} catch (Exception $e) {
    error_log("Inicio: " . $e->getMessage());
    $error = "No se pudo consultar la configuración del inicio.";
}

// Guardar los tres servicios y los cuatro pasos en una transacción.
if ($_SERVER["REQUEST_METHOD"] === "POST" && $carga_correcta) {
    $token = $_POST["csrf"] ?? "";
    if (!is_string($token) || !hash_equals($_SESSION["csrf_inicio"], $token) || $id_bibliotecario <= 0) {
        $error = "El formulario o la sesión no son válidos.";
    } else {
        foreach (["servicio" => 3, "paso" => 4] as $tipo => $cantidad) {
            foreach (["_titulo", "_contenido"] as $sufijo) {
                $lista = $_POST[$tipo . $sufijo] ?? null;
                if (!is_array($lista) || count($lista) !== $cantidad) {
                    $error = "El formulario está incompleto.";
                    break 2;
                }
                   }
        }
        if ($error === "") {
            foreach ($campos as $clave => $campo) {
                $valor = $_POST[$campo[0]][$campo[1]] ?? null;
                if (!is_string($valor)) {
                    $error = "Los datos del formulario no son válidos.";
                    break;
                }
                $datos[$clave] = trim($valor);
                if ($datos[$clave] === "" || preg_match_all('/./us', $datos[$clave]) === false || preg_match_all('/./us', $datos[$clave]) > $limite) {
                    $error = "Completa los títulos y descripciones sin superar la longitud permitida.";
                }
            }
        }
        if ($error === "") {
            $transaccion = false;
            try {
                if (!mysqli_begin_transaction($conexion)) {
                    throw new RuntimeException("Error al iniciar la transacción.");
                }
                $transaccion = true;
                $resultado = mysqli_query($conexion, "SELECT NOW() AS fecha");
                if (!$resultado) {
                    throw new RuntimeException("Error al consultar la fecha.");
                }
                $fecha_servidor = mysqli_fetch_assoc($resultado)["fecha"];
                mysqli_free_result($resultado);
                $registros = $datos;
                $registros["inicio_fecha_actualizada"] = $fecha_servidor;
                $registros["inicio_id_bibliotecario"] = (string) $id_bibliotecario;

                $sql = "INSERT INTO tblconfiguracion (clave, valor) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE valor = VALUES(valor)";
                $consulta = mysqli_prepare($conexion, $sql);
                if (!$consulta || !mysqli_stmt_bind_param($consulta, "ss", $clave, $valor)) {
                    throw new RuntimeException("Error al preparar la consulta.");
                }
                foreach ($registros as $clave => $valor) {
                    if (!mysqli_stmt_execute($consulta) || mysqli_warning_count($conexion) > 0) {
                        throw new RuntimeException("Error al guardar la configuración.");
                    }
                }
                mysqli_stmt_close($consulta);
                if (!mysqli_commit($conexion)) {
                    throw new RuntimeException("Error al confirmar los cambios.");
                }
                $_SESSION["mensaje_inicio"] = "Los cambios se guardaron correctamente.";
                header("Location: datos_biblioteca_inicio.php");
                exit;
            } catch (Exception $e) {
                if ($transaccion) {
                    mysqli_rollback($conexion);
                }
                error_log("Inicio: " . $e->getMessage());
                $error = "No se pudieron guardar los cambios. Revisa la base de datos.";
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

        <title>Contenido del inicio - Datos de la biblioteca</title>

        <!-- Fuente Poppins (guia de estilos) -->
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />

        <link rel="stylesheet" href="./css/bootstrap.min.css" />
        <link rel="stylesheet" href="./css/bibliotecario.css" />
        <link rel="stylesheet" href="./css/biblioteca_inicio.css" />

       
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
            <?php include __DIR__ . "/includes/sidebar_bibliotecario.php"; ?>

            <!-- Contenido -->
            <main class="admin-main">
                <div class="admin-content">
                    <nav class="breadcrumb-line" aria-label="Ruta de navegación">
                        <a href="datos_biblioteca.php">Datos de la biblioteca</a>
                        <svg class="icon"><use href="#i-chevron-right" /></svg>
                        <span>Contenido del inicio</span>
                    </nav>
                    <h1 class="page-title">Contenido del inicio</h1>
                    <p class="page-subtitle">
                        Edita las tarjetas de servicios y los pasos para reservar que se muestran en el inicio público.
                    </p>

                    <!-- Mensajes -->
                    <?php if ($error !== ""): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                    <?php endif; ?>
                    <?php if ($mensaje !== ""): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($mensaje, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                    <?php endif; ?>

                    <form action="" method="POST">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["csrf_inicio"], ENT_QUOTES, "UTF-8") ?>">
                        <div class="form-layout equal">
                            <!-- Columna izquierda -->
                            <div class="form-column">
                                <section class="form-card">
                                    <div class="card-header-row has-subtitle">
                                        <div class="card-icon">
                                            <svg class="icon"><use href="#i-bell" /></svg>
                                        </div>
                                        <div>
                                            <h2 class="card-title">Servicios</h2>
                                            <p class="card-subtitle">Tarjetas de la sección «Servicios de la biblioteca».</p>
                                        </div>
                                    </div>

                                    <div data-list data-label="Servicio">
                                        <div class="repeat-row">
                                            <div class="row-head">
                                                <span class="row-label">Servicio 1</span>
                                            </div>
                                            <input
                                                type="text"
                                                name="servicio_titulo[]"
                                                class="form-control mb-2"
                                                placeholder="[Título del servicio]"
                                                value="<?= htmlspecialchars((string) $datos["servicio_1_titulo"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                            <input
                                                type="text"
                                                name="servicio_contenido[]"
                                                class="form-control"
                                                placeholder="[Descripción del servicio]"
                                                value="<?= htmlspecialchars((string) $datos["servicio_1_descripcion"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                        </div>
                                        <div class="repeat-row">
                                            <div class="row-head">
                                                <span class="row-label">Servicio 2</span>
                                            </div>
                                            <input
                                                type="text"
                                                name="servicio_titulo[]"
                                                class="form-control mb-2"
                                                placeholder="[Título del servicio]"
                                                value="<?= htmlspecialchars((string) $datos["servicio_2_titulo"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                            <input
                                                type="text"
                                                name="servicio_contenido[]"
                                                class="form-control"
                                                placeholder="[Descripción del servicio]"
                                                value="<?= htmlspecialchars((string) $datos["servicio_2_descripcion"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                        </div>
                                        <div class="repeat-row">
                                            <div class="row-head">
                                                <span class="row-label">Servicio 3</span>
                                            </div>
                                            <input
                                                type="text"
                                                name="servicio_titulo[]"
                                                class="form-control mb-2"
                                                placeholder="[Título del servicio]"
                                                value="<?= htmlspecialchars((string) $datos["servicio_3_titulo"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                            <input
                                                type="text"
                                                name="servicio_contenido[]"
                                                class="form-control"
                                                placeholder="[Descripción del servicio]"
                                                value="<?= htmlspecialchars((string) $datos["servicio_3_descripcion"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <!-- Columna derecha -->
                            <div class="form-column">
                                <section class="form-card">
                                    <div class="card-header-row has-subtitle">
                                        <div class="card-icon">
                                            <svg class="icon"><use href="#i-list-ordered" /></svg>
                                        </div>
                                        <div>
                                            <h2 class="card-title">Cómo reservar</h2>
                                            <p class="card-subtitle">
                                                Pasos de la sección «Cómo reservar tu libro». Las reservas se hacen desde la app móvil.
                                            </p>
                                        </div>
                                    </div>

                                    <div data-list data-label="Paso">
                                        <div class="repeat-row">
                                            <div class="row-head">
                                                <span class="row-label">Paso 1</span>
                                            </div>
                                            <input
                                                type="text"
                                                name="paso_titulo[]"
                                                class="form-control mb-2"
                                                placeholder="[Título del paso]"
                                                value="<?= htmlspecialchars((string) $datos["paso_1_titulo"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                            <input
                                                type="text"
                                                name="paso_contenido[]"
                                                class="form-control"
                                                placeholder="[Descripción del paso]"
                                                value="<?= htmlspecialchars((string) $datos["paso_1_descripcion"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                        </div>
                                        <div class="repeat-row">
                                            <div class="row-head">
                                                <span class="row-label">Paso 2</span>
                                            </div>
                                            <input
                                                type="text"
                                                name="paso_titulo[]"
                                                class="form-control mb-2"
                                                placeholder="[Título del paso]"
                                                value="<?= htmlspecialchars((string) $datos["paso_2_titulo"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                            <input
                                                type="text"
                                                name="paso_contenido[]"
                                                class="form-control"
                                                placeholder="[Descripción del paso]"
                                                value="<?= htmlspecialchars((string) $datos["paso_2_descripcion"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                        </div>
                                        <div class="repeat-row">
                                            <div class="row-head">
                                                <span class="row-label">Paso 3</span>
                                            </div>
                                            <input
                                                type="text"
                                                name="paso_titulo[]"
                                                class="form-control mb-2"
                                                placeholder="[Título del paso]"
                                                value="<?= htmlspecialchars((string) $datos["paso_3_titulo"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                            <input
                                                type="text"
                                                name="paso_contenido[]"
                                                class="form-control"
                                                placeholder="[Descripción del paso]"
                                                value="<?= htmlspecialchars((string) $datos["paso_3_descripcion"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                        </div>
                                        <div class="repeat-row">
                                            <div class="row-head">
                                                <span class="row-label">Paso 4</span>
                                            </div>
                                            <input
                                                type="text"
                                                name="paso_titulo[]"
                                                class="form-control mb-2"
                                                placeholder="[Título del paso]"
                                                value="<?= htmlspecialchars((string) $datos["paso_4_titulo"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                            <input
                                                type="text"
                                                name="paso_contenido[]"
                                                class="form-control"
                                                placeholder="[Descripción del paso]"
                                                value="<?= htmlspecialchars((string) $datos["paso_4_descripcion"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                required
                                            />
                                        </div>
                                    </div>
                                </section>

                                <!-- Ultima actualizacion -->
                                <div class="update-box">
                                    <p class="label">Última actualización</p>
                                    <p class="value"><?= htmlspecialchars(($fecha !== "" && strtotime($fecha) !== false ? date("d/m/Y, H:i", strtotime($fecha)) : "Sin registro") . ($usuario !== "" ? " · " . $usuario : ""), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></p>
                                    <p class="note">La fecha, la hora y el usuario lo registra el sistema al guardar.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Botones -->
                        <div class="actions-row">
                            <a href="panel_control.php" class="btn btn-secondary-brand">Cancelar</a>
                            <button type="submit" class="btn btn-primary-brand" <?= !$carga_correcta ? "disabled" : "" ?>>
                                <svg class="icon"><use href="#i-circle-check" /></svg> Guardar cambios
                            </button>
                        </div>
                    </form>
                </div>
            </main>
        </div>

        <?php include __DIR__ . "/includes/iconos.php"; ?>
        <?php include __DIR__ . "/includes/iconos_catalogo.php"; ?>

        <script src="./js/bootstrap.bundle.min.js"></script>
        <script src="./js/ver_password.js"></script>
    </body>
</html>
