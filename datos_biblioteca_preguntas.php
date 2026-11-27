<?php

require_once __DIR__ . "/includes/sesion.php";
exigir_bibliotecario();
require_once __DIR__ . "/config/conexion.php";

/** @var mysqli $conexion */

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    die("No se pudo establecer la conexión con la base de datos.");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pagina_activa = "datos_biblioteca_preguntas.php";

$mensaje = $_SESSION["mensaje_preguntas"] ?? "";
unset($_SESSION["mensaje_preguntas"]);

$error = "";
$preguntasFaq = [];
$ultimaActualizacion = "Sin registro";
$revision = "";
$cargaCorrecta = false;
$transaccion = false;

$id_bibliotecario = (int) ($_SESSION["id_bibliotecario"] ?? 0);

// Token de seguridad
if (empty($_SESSION["csrf_preguntas"])) {
    $_SESSION["csrf_preguntas"] = bin2hex(random_bytes(32));
}

try {

    // Verificar las columnas de la tabla
    $columnas = mysqli_query(
        $conexion,
        "SHOW COLUMNS FROM tblpreguntas_frecuentes"
    );

    $tieneBibliotecario = false;

    while ($columna = mysqli_fetch_assoc($columnas)) {

        if ($columna["Field"] === "id_bibliotecario") {
            $tieneBibliotecario = true;
        }

    }

    mysqli_free_result($columnas);

    // Consulta de preguntas frecuentes
    $sql = "SELECT
                id_pregunta_frecuente,
                pregunta,
                respuesta,
                orden,
                actualizado_en";

    if ($tieneBibliotecario) {
        $sql .= ", id_bibliotecario";
    }

    $sql .= " FROM tblpreguntas_frecuentes
              ORDER BY orden, id_pregunta_frecuente";

    // Guardar cambios
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $token = $_POST["csrf"] ?? "";
        $ids = $_POST["pregunta_id"] ?? [];
        $titulos = $_POST["pregunta_titulo"] ?? [];
        $respuestas = $_POST["pregunta_contenido"] ?? [];

        // Validar formulario
        if (
            !is_string($token) ||
            !hash_equals($_SESSION["csrf_preguntas"], $token) ||
            $id_bibliotecario <= 0
        ) {

            $error = "El formulario o la sesión no son válidos.";

        } elseif (
            !is_array($ids) ||
            !is_array($titulos) ||
            !is_array($respuestas) ||
            count($ids) !== count($titulos) ||
            count($ids) !== count($respuestas) ||
            count($ids) < 1 ||
            count($ids) > 30
        ) {

            $error = "Debes guardar entre 1 y 30 preguntas completas.";

        } else {

            $ids = array_values($ids);
            $titulos = array_values($titulos);
            $respuestas = array_values($respuestas);

            foreach ($ids as $i => $id) {

                if (
                    !is_string($id) ||
                    !ctype_digit($id) ||
                    !is_string($titulos[$i]) ||
                    !is_string($respuestas[$i])
                ) {
                    $error = "Los datos del formulario no son válidos.";
                    break;
                }

                $titulos[$i] = trim($titulos[$i]);
                $respuestas[$i] = trim($respuestas[$i]);

                if (
                    $titulos[$i] === "" ||
                    $respuestas[$i] === ""
                ) {
                    $error = "Cada pregunta debe tener su respuesta.";
                    break;
                }

                if (
                    mb_strlen($titulos[$i], "UTF-8") > 200 ||
                    mb_strlen($respuestas[$i], "UTF-8") > 2000
                ) {
                    $error = "Se superó la longitud permitida.";
                    break;
                }

            }

        }

        // Procesar modificaciones
        if ($error === "") {

            mysqli_begin_transaction($conexion);

            $transaccion = true;

            // Consultar los registros existentes
            $resultado = mysqli_query(
                $conexion,
                $sql . " FOR UPDATE"
            );

            $anteriores = mysqli_fetch_all(
                $resultado,
                MYSQLI_ASSOC
            );

            mysqli_free_result($resultado);

            // Verificar que la lista no haya cambiado
            $revisionRecibida = $_POST["revision"] ?? "";

            if (
                !is_string($revisionRecibida) ||
                !hash_equals(
                    hash("sha256", serialize($anteriores)),
                    $revisionRecibida
                )
            ) {
                throw new RuntimeException(
                    "La lista cambió. Recarga la página antes de guardar."
                );
            }

            $existentes = array_column(
                $anteriores,
                null,
                "id_pregunta_frecuente"
            );

            $conservar = [];

            foreach ($ids as $id) {

                $id = (int) $id;

                if ($id > 0) {

                    if (
                        !isset($existentes[$id]) ||
                        isset($conservar[$id])
                    ) {
                        throw new RuntimeException(
                            "Identificador de pregunta no válido."
                        );
                    }

                    $conservar[$id] = true;

                }

            }

            // Eliminar preguntas retiradas del formulario
            $eliminar = mysqli_prepare(
                $conexion,
                "DELETE FROM tblpreguntas_frecuentes
                 WHERE id_pregunta_frecuente = ?"
            );

            foreach ($existentes as $id => $registro) {

                if (!isset($conservar[$id])) {

                    mysqli_stmt_bind_param(
                        $eliminar,
                        "i",
                        $id
                    );

                    mysqli_stmt_execute($eliminar);

                }

            }

            mysqli_stmt_close($eliminar);

            // Preparar consultas de inserción y actualización
            $sqlInsertar = "INSERT INTO tblpreguntas_frecuentes
                            (pregunta, respuesta, orden, actualizado_en";

            $sqlActualizar = "UPDATE tblpreguntas_frecuentes SET
                                pregunta = ?,
                                respuesta = ?,
                                orden = ?,
                                actualizado_en = NOW()";

            if ($tieneBibliotecario) {

                $sqlInsertar .= ", id_bibliotecario)
                                 VALUES (?, ?, ?, NOW(), ?)";

                $sqlActualizar .= ", id_bibliotecario = ?";

            } else {

                $sqlInsertar .= ") VALUES (?, ?, ?, NOW())";

            }

            $sqlActualizar .= " WHERE id_pregunta_frecuente = ?";

            $insertar = mysqli_prepare(
                $conexion,
                $sqlInsertar
            );

            $actualizar = mysqli_prepare(
                $conexion,
                $sqlActualizar
            );

            // Insertar o actualizar preguntas
            foreach ($ids as $i => $id) {

                $id = (int) $id;
                $titulo = $titulos[$i];
                $respuesta = $respuestas[$i];
                $orden = $i + 1;

                if ($id === 0) {

                    // Insertar nueva pregunta
                    if ($tieneBibliotecario) {

                        mysqli_stmt_bind_param(
                            $insertar,
                            "ssii",
                            $titulo,
                            $respuesta,
                            $orden,
                            $id_bibliotecario
                        );

                    } else {

                        mysqli_stmt_bind_param(
                            $insertar,
                            "ssi",
                            $titulo,
                            $respuesta,
                            $orden
                        );

                    }

                    mysqli_stmt_execute($insertar);

                } else {

                    // Actualizar pregunta existente
                    if ($tieneBibliotecario) {

                        mysqli_stmt_bind_param(
                            $actualizar,
                            "ssiii",
                            $titulo,
                            $respuesta,
                            $orden,
                            $id_bibliotecario,
                            $id
                        );

                    } else {

                        mysqli_stmt_bind_param(
                            $actualizar,
                            "ssii",
                            $titulo,
                            $respuesta,
                            $orden,
                            $id
                        );

                    }

                    mysqli_stmt_execute($actualizar);

                }

            }

            mysqli_stmt_close($insertar);
            mysqli_stmt_close($actualizar);

            mysqli_commit($conexion);

            $transaccion = false;

            $_SESSION["mensaje_preguntas"] =
                "Las preguntas frecuentes se guardaron correctamente.";

            header("Location: datos_biblioteca_preguntas.php");

            exit;

        }

    }

} catch (Exception $e) {

    if ($transaccion) {

        mysqli_rollback($conexion);

        $transaccion = false;

    }

    error_log("Preguntas: " . $e->getMessage());
    if ($e instanceof mysqli_sql_exception) {

        $error = "No se pudieron guardar las preguntas frecuentes.";

    } else {

        $error = $e->getMessage();

    }

}

// Consultar las preguntas frecuentes
try {

    if (!isset($sql)) {

        throw new RuntimeException(
            "No se pudo consultar la estructura de la tabla."
        );

    }

    $resultado = mysqli_query($conexion, $sql);

    $preguntasFaq = mysqli_fetch_all(
        $resultado,
        MYSQLI_ASSOC
    );

    mysqli_free_result($resultado);

    $revision = hash(
        "sha256",
        serialize($preguntasFaq)
    );

    $cargaCorrecta = true;

    // Obtener la última actualización
    $ultima = null;

    foreach ($preguntasFaq as $pregunta) {

        if (
            !empty($pregunta["actualizado_en"]) &&
            (
                $ultima === null ||
                $pregunta["actualizado_en"] > $ultima["actualizado_en"]
            )
        ) {

            $ultima = $pregunta;

        }

    }

    if ($ultima !== null) {

        $ultimaActualizacion = date(
            "d/m/Y, H:i",
            strtotime($ultima["actualizado_en"])
        );

        // Consultar al bibliotecario responsable
        if (
            $tieneBibliotecario &&
            !empty($ultima["id_bibliotecario"])
        ) {

            $consulta = mysqli_prepare(
                $conexion,
                "SELECT usuario
                 FROM tblbibliotecario
                 WHERE id_bibliotecario = ?"
            );

            $responsable = (int) $ultima["id_bibliotecario"];

            mysqli_stmt_bind_param(
                $consulta,
                "i",
                $responsable
            );

            mysqli_stmt_execute($consulta);

            mysqli_stmt_bind_result(
                $consulta,
                $usuario
            );

            if (mysqli_stmt_fetch($consulta)) {

                $ultimaActualizacion .= " · " . $usuario;

            }

            mysqli_stmt_close($consulta);

        }

    }

} catch (Exception $e) {

    error_log("Preguntas: " . $e->getMessage());
    $error = "No se pudo consultar la información de preguntas frecuentes.";

    $cargaCorrecta = false;

}

?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />

        <title>Preguntas frecuentes - Datos de la biblioteca</title>

        <!-- Fuente Poppins (guia de estilos) -->
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />

        <link rel="stylesheet" href="./css/bootstrap.min.css" />
        <link rel="stylesheet" href="./css/bibliotecario.css" />
        <link rel="stylesheet" href="./css/biblioteca_preguntas.css" />

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
                        <span>Preguntas frecuentes</span>
                    </nav>
                    <h1 class="page-title">Preguntas frecuentes</h1>
                    <p class="page-subtitle">Agrega, edita o elimina las preguntas que se muestran al público.</p>

                    <?php if ($error !== ""): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                    <?php endif; ?>
                    <?php if ($mensaje !== ""): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($mensaje, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></div>
                    <?php endif; ?>
                    <?php if ($cargaCorrecta && count($preguntasFaq) === 0): ?>
                        <div class="alert alert-warning" role="alert">
                            Todavía no hay preguntas frecuentes registradas. Agrega una pregunta para comenzar.
                        </div>
                    <?php endif; ?>

                    <form action="" method="POST">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["csrf_preguntas"], ENT_QUOTES, "UTF-8") ?>">
                        <input type="hidden" name="revision" value="<?= htmlspecialchars($revision, ENT_QUOTES, "UTF-8") ?>">
                        <div class="form-layout">
                            <!-- Columna izquierda -->
                            <div class="form-column">
                                <section class="form-card">
                                    <div class="card-header-row has-subtitle">
                                        <div class="card-icon">
                                            <svg class="icon"><use href="#i-circle-help" /></svg>
                                        </div>
                                        <div>
                                            <h2 class="card-title">Preguntas y respuestas</h2>
                                            <p class="card-subtitle">Escribe cada pregunta con su respuesta.</p>
                                        </div>
                                    </div>

                                    <div id="lista-pregunta" data-list data-label="Pregunta">
                                        <?php foreach ($preguntasFaq as $indice => $pregunta): ?>
                                            <div class="repeat-row">
                                                <input type="hidden" name="pregunta_id[]" value="<?= (int) $pregunta["id_pregunta_frecuente"] ?>">
                                                <div class="row-head">
                                                    <span class="row-label">Pregunta <?= $indice + 1 ?></span>
                                                    <button type="button" class="btn-delete" aria-label="Eliminar pregunta">
                                                        <svg class="icon"><use href="#i-trash" /></svg>
                                                    </button>
                                                </div>
                                                <input
                                                    type="text"
                                                    name="pregunta_titulo[]"
                                                    class="form-control mb-2"
                                                    placeholder="Pregunta frecuente"
                                                    maxlength="200"
                                                    value="<?= htmlspecialchars((string) $pregunta["pregunta"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>"
                                                    required
                                                />
                                                <textarea
                                                    name="pregunta_contenido[]"
                                                    class="form-control"
                                                    rows="3"
                                                    placeholder="Respuesta"
                                                    maxlength="2000"
                                                    required
                                                ><?= htmlspecialchars((string) $pregunta["respuesta"], ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></textarea>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <template id="tpl-lista-pregunta">
                                        <div class="repeat-row">
                                            <input type="hidden" name="pregunta_id[]" value="0">
                                            <div class="row-head">
                                                <span class="row-label">Pregunta 0</span>
                                                <button type="button" class="btn-delete" aria-label="Eliminar pregunta">
                                                    <svg class="icon"><use href="#i-trash" /></svg>
                                                </button>
                                            </div>
                                            <input
                                                type="text"
                                                name="pregunta_titulo[]"
                                                class="form-control mb-2"
                                                placeholder="Pregunta frecuente"
                                                maxlength="200"
                                                value=""
                                                required
                                            />
                                            <textarea
                                                name="pregunta_contenido[]"
                                                class="form-control"
                                                rows="3"
                                                placeholder="Respuesta"
                                                maxlength="2000"
                                                required
                                            ></textarea>
                                        </div>
                                    </template>

                                    <button type="button" class="btn-add" data-add="lista-pregunta" <?= !$cargaCorrecta ? "disabled" : "" ?>>
                                        <svg class="icon"><use href="#i-plus" /></svg> Agregar pregunta
                                    </button>
                                </section>
                            </div>

                            <!-- Columna derecha -->
                            <div class="form-column">
                                <section class="form-card">
                                    <div class="card-header-row">
                                        <div class="card-icon">
                                            <svg class="icon"><use href="#i-info" /></svg>
                                        </div>
                                        <h2 class="card-title">Cómo se muestra</h2>
                                    </div>
                                    <ul class="help-list">
                                        <li>
                                            <svg class="icon"><use href="#i-info" /></svg
                                            ><span>Todas las preguntas se muestran en Preguntas frecuentes junto con su buscador.</span>
                                        </li>
                                        <li>
                                            <svg class="icon"><use href="#i-info" /></svg
                                            ><span>Todas las preguntas aparecen en la página «Preguntas frecuentes», con su buscador.</span>
                                        </li>
                                        <li>
                                            <svg class="icon"><use href="#i-info" /></svg
                                            ><span>Se muestran en el mismo orden en que las escribes aquí.</span>
                                        </li>
                                    </ul>
                                </section>

                                <!-- Ultima actualizacion -->
                                <div class="update-box">
                                    <p class="label">Última actualización</p>
                                    <p class="value">
                                        <?= htmlspecialchars((string) $ultimaActualizacion, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?>
                                    </p>
                                    <p class="note">La fecha se actualiza al guardar las preguntas frecuentes.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Botones -->
                        <div class="actions-row">
                            <a href="panel_control.php" class="btn btn-secondary-brand">Cancelar</a>
                            <button type="submit" class="btn btn-primary-brand" <?= !$cargaCorrecta ? "disabled" : "" ?>>
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
        <script>
            // numera de nuevo los renglones de una lista (Pregunta 1, Pregunta 2...)
            function numerarLista(lista) {
                lista.querySelectorAll(".row-label").forEach(function (etiqueta, i) {
                    etiqueta.textContent = lista.dataset.label + " " + (i + 1);
                });
            }

            // botones "Agregar": copian el renglon vacio de la plantilla
            document.querySelectorAll("[data-add]").forEach(function (boton) {
                boton.addEventListener("click", function () {
                    var lista = document.getElementById(boton.dataset.add);
                    var plantilla = document.getElementById("tpl-" + boton.dataset.add);
                    if (lista.querySelectorAll(".repeat-row").length >= 30) {
                        alert("Puedes registrar hasta 30 preguntas.");
                        return;
                    }
                    lista.appendChild(plantilla.content.cloneNode(true));
                    numerarLista(lista);
                });
            });

            // botones de borrar renglon
            document.addEventListener("click", function (evento) {
                var boton = evento.target.closest(".btn-delete");
                if (!boton) return;
                var lista = boton.closest("[data-list]");
                boton.closest(".repeat-row").remove();
                numerarLista(lista);
            });
        </script>
    </body>
</html>
