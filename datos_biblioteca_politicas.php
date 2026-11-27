<?php
require_once __DIR__ . "/includes/sesion.php";
exigir_bibliotecario();
require_once __DIR__ . "/config/conexion.php";
/** @var mysqli $conexion */
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    die("No se pudo establecer la conexión con la base de datos.");
}
$pagina_activa = "datos_biblioteca_politicas.php";
$mensaje = $_SESSION["mensaje_politicas"] ?? "";
unset($_SESSION["mensaje_politicas"]);
$error = "";
$politicas = [];
$ultimaActualizacion = "Sin registro";
$revision = "";
$cargaCorrecta = false;
$politicasDesdeBD = false;
$id_bibliotecario = (int) ($_SESSION["id_bibliotecario"] ?? 0);
if (empty($_SESSION["csrf_politicas"])) {
    $_SESSION["csrf_politicas"] = bin2hex(random_bytes(32));
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$transaccion = false;
$sql = "SELECT id_politica, titulo, contenido, orden,
               actualizado_en, id_bibliotecario
        FROM tblpoliticas_servicio
        ORDER BY orden, id_politica";
try {
    // Guardar altas, modificaciones y bajas en una sola transacción.
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $token = $_POST["csrf"] ?? "";
        $ids = $_POST["politica_id"] ?? [];
        $titulos = $_POST["politica_titulo"] ?? [];
        $contenidos = $_POST["politica_contenido"] ?? [];
        if (!is_string($token) || !hash_equals($_SESSION["csrf_politicas"], $token) || $id_bibliotecario <= 0) {
            $error = "El formulario o la sesión no son válidos.";
        } elseif (!is_array($ids) || !is_array($titulos) || !is_array($contenidos) ||
            count($ids) !== count($titulos) || count($ids) !== count($contenidos) ||
            count($ids) < 1 || count($ids) > 30) {
            $error = "Debes guardar entre 1 y 30 secciones completas.";
        } else {
            $ids = array_values($ids);
            $titulos = array_values($titulos);
            $contenidos = array_values($contenidos);
            foreach ($ids as $i => $id) {
                if (!is_string($id) || !ctype_digit($id) || !is_string($titulos[$i]) || !is_string($contenidos[$i])) {
                    $error = "Los datos del formulario no son válidos.";
                    break;
                }
                $titulos[$i] = trim($titulos[$i]);
                $contenidos[$i] = trim($contenidos[$i]);
                if ($titulos[$i] === "" || $contenidos[$i] === "" ||
                    mb_strlen($titulos[$i], "UTF-8") > 200 || mb_strlen($contenidos[$i], "UTF-8") > 6000) {
                    $error = "Cada sección debe tener título y contenido dentro de los límites permitidos.";
                    break;
                }
            }
        }
        if ($error === "") {
            mysqli_begin_transaction($conexion);
            $transaccion = true;
            $resultado = mysqli_query($conexion, $sql . " FOR UPDATE");
            $anteriores = mysqli_fetch_all($resultado, MYSQLI_ASSOC);
            mysqli_free_result($resultado);
            $revisionRecibida = $_POST["revision"] ?? "";
            if (!is_string($revisionRecibida) || !hash_equals(hash("sha256", serialize($anteriores)), $revisionRecibida)) {
                throw new RuntimeException("La lista cambió. Recarga la página antes de guardar.");
            }
            $existentes = array_column($anteriores, null, "id_politica");
            $conservar = [];
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    if (!isset($existentes[$id]) || isset($conservar[$id])) {
                        throw new RuntimeException("Identificador de política no válido.");
                    }
                    $conservar[$id] = true;
                }
            }
            // Eliminar únicamente los registros retirados del formulario.
            $eliminar = mysqli_prepare($conexion, "DELETE FROM tblpoliticas_servicio WHERE id_politica = ?");
            foreach ($existentes as $id => $registro) {
                if (!isset($conservar[$id])) {
                    mysqli_stmt_bind_param($eliminar, "i", $id);
                    mysqli_stmt_execute($eliminar);
                      }
            }
            mysqli_stmt_close($eliminar);
            $insertar = mysqli_prepare(
                $conexion,
                "INSERT INTO tblpoliticas_servicio
                    (titulo, contenido, orden, actualizado_en, id_bibliotecario)
                 VALUES (?, ?, ?, NOW(), ?)"
            );
            $actualizar = mysqli_prepare(
                $conexion,
                "UPDATE tblpoliticas_servicio SET
                    titulo = ?, contenido = ?, orden = ?,
                    actualizado_en = NOW(), id_bibliotecario = ?
                 WHERE id_politica = ?"
            );
            foreach ($ids as $i => $id) {
                $id = (int) $id;
                $titulo = $titulos[$i];
                $contenido = $contenidos[$i];
                $orden = $i + 1;
                if ($id === 0) {
                    mysqli_stmt_bind_param(
                        $insertar, "ssii",
                        $titulo, $contenido, $orden, $id_bibliotecario
                    );
                    mysqli_stmt_execute($insertar);
                } else {
                    mysqli_stmt_bind_param(
                        $actualizar, "ssiii",
                        $titulo, $contenido, $orden, $id_bibliotecario, $id
                    );
                    mysqli_stmt_execute($actualizar);
                }
            }
            mysqli_stmt_close($insertar);
            mysqli_stmt_close($actualizar);
            mysqli_commit($conexion);
            $transaccion = false;
            $_SESSION["mensaje_politicas"] = "Las políticas se guardaron correctamente.";
            header("Location: datos_biblioteca_politicas.php");
            exit;
        }
    }
} catch (Exception $e) {
    if ($transaccion) {
        mysqli_rollback($conexion);
    }
    $error = $e instanceof mysqli_sql_exception ? "No se pudieron guardar o consultar las políticas." : $e->getMessage();
}
// Consultar las políticas reales, sin contenido ficticio.
try {
    if (!isset($sql)) {
        throw new RuntimeException("No se pudo consultar la estructura de la tabla.");
    }
    $resultado = mysqli_query($conexion, $sql);
    $politicas = mysqli_fetch_all($resultado, MYSQLI_ASSOC);
    mysqli_free_result($resultado);
    $revision = hash("sha256", serialize($politicas));
    $cargaCorrecta = true;
        $politicasDesdeBD = count($politicas) > 0;
    $ultima = null;
    foreach ($politicas as $titulo) {
        if (!empty($titulo["actualizado_en"]) && ($ultima === null || $titulo["actualizado_en"] > $ultima["actualizado_en"])) {
            $ultima = $titulo;
        }
    }
    if ($ultima !== null) {
        $ultimaActualizacion = date("d/m/Y, H:i", strtotime($ultima["actualizado_en"]));
        if (!empty($ultima["id_bibliotecario"])) {
            $consulta = mysqli_prepare($conexion, "SELECT usuario FROM tblbibliotecario WHERE id_bibliotecario = ?");
            $responsable = (int) $ultima["id_bibliotecario"];
            mysqli_stmt_bind_param($consulta, "i", $responsable);
            mysqli_stmt_execute($consulta);
            mysqli_stmt_bind_result($consulta, $usuario);
            if (mysqli_stmt_fetch($consulta)) {
                $ultimaActualizacion .= " · " . $usuario;
            }
            mysqli_stmt_close($consulta);
        }
    }
} catch (Exception $e) {
    $error = "No se pudo consultar la información de políticas.";
    $cargaCorrecta = false;
}
?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Políticas del servicio - Datos de la biblioteca</title>
        <!-- Fuente Poppins (guia de estilos) -->
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />

        <link rel="stylesheet" href="./css/bootstrap.min.css" />
        <link rel="stylesheet" href="./css/bibliotecario.css" />
        <link rel="stylesheet" href="./css/biblioteca_politicas.css" />
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
                        <span>Políticas del servicio</span>
                    </nav>
                    <h1 class="page-title">Políticas del servicio</h1>
                    <p class="page-subtitle">Edita las secciones de la página de políticas y condiciones del servicio bibliotecario.</p>

                    <?php if ($error !== ""): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div>
                    <?php endif; ?>
                    <?php if ($mensaje !== ""): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($mensaje, ENT_QUOTES, "UTF-8") ?></div>
                    <?php endif; ?>
                    <?php if (!$politicasDesdeBD): ?>
                        <div class="alert alert-warning" role="alert">
                            No se encontraron políticas guardadas. Puedes agregar una sección si la consulta fue correcta.
                        </div>
                    <?php endif; ?>
                    <form action="" method="POST">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["csrf_politicas"], ENT_QUOTES, "UTF-8") ?>" />
                        <input type="hidden" name="revision" value="<?= htmlspecialchars($revision, ENT_QUOTES, "UTF-8") ?>" />
                        <div class="form-layout">
                            <!-- Columna izquierda -->
                            <div class="form-column">
                                <section class="form-card">
                                    <div class="card-header-row has-subtitle">
                                        <div class="card-icon">
                                            <svg class="icon"><use href="#i-shield-check" /></svg>
                                        </div>
                                        <div>
                                            <h2 class="card-title">Secciones de las políticas</h2>
                                            <p class="card-subtitle">Cada sección tiene un título y su contenido.</p>
                                        </div>
                                    </div>
                                    <div id="lista-politica" data-list data-label="Sección">
                                        <?php foreach ($politicas as $indice => $politica): ?>
                                            <div class="repeat-row">
                                                <input type="hidden" name="politica_id[]" value="<?= (int) $politica["id_politica"] ?>" />
                                                <div class="row-head">
                                                    <span class="row-label">Sección <?= $indice + 1 ?></span>
                                                    <button type="button" class="btn-delete" aria-label="Eliminar sección">
                                                        <svg class="icon"><use href="#i-trash" /></svg>
                                                    </button>
                                                </div>
                                                <input
                                                    type="text"
                                                    name="politica_titulo[]"
                                                    class="form-control mb-2"
                                                    placeholder="Título de la sección"
                                                    maxlength="200"
                                                    value="<?= htmlspecialchars((string) $politica["titulo"], ENT_QUOTES, "UTF-8") ?>"
                                                    required
                                                />
                                                <textarea
                                                    name="politica_contenido[]"
                                                    class="form-control"
                                                    rows="3"
                                                    maxlength="6000"
                                                    placeholder="Contenido de la sección"
                                                    required
                                                ><?= htmlspecialchars((string) $politica["contenido"], ENT_QUOTES, "UTF-8") ?></textarea>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <template id="tpl-lista-politica">
                                        <div class="repeat-row">
                                            <input type="hidden" name="politica_id[]" value="0" />
                                            <div class="row-head">
                                                <span class="row-label">Sección 0</span>
                                                <button type="button" class="btn-delete" aria-label="Eliminar">
                                                    <svg class="icon"><use href="#i-trash" /></svg>
                                                </button>
                                            </div>
                                            <input
                                                type="text"
                                                name="politica_titulo[]"
                                                class="form-control mb-2"
                                                placeholder="Título de la sección"
                                                maxlength="200"
                                                value="" required
                                            />
                                            <textarea
                                                name="politica_contenido[]"
                                                class="form-control"
                                                rows="3"
                                                maxlength="6000"
                                                placeholder="Contenido de la sección" required
                                            ></textarea>
                                        </div>
                                    </template>
                                    <button type="button" class="btn-add" data-add="lista-politica">
                                        <svg class="icon"><use href="#i-plus" /></svg> Agregar sección
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
                                            ><span
                                                >Se muestran en la página «Políticas y condiciones del servicio bibliotecario», que se abre
                                                desde el pie de página.</span
                                            >
                                        </li>
                                        <li>
                                            <svg class="icon"><use href="#i-info" /></svg
                                            ><span>El contenido de una línea se muestra como párrafo; varias líneas se muestran como puntos.</span>
                                        </li>
                                        <li>
                                            <svg class="icon"><use href="#i-info" /></svg
                                            ><span>El contenido se muestra como está guardado. Verifica que límites y montos coincidan con las reglas vigentes.</span>
                                        </li>
                                    </ul>
                                </section>
                                <!-- Ultima actualizacion -->
                                <div class="update-box">
                                    <p class="label">Última actualización</p>
                                    <p class="value">
                                        <?= htmlspecialchars($ultimaActualizacion, ENT_QUOTES, "UTF-8") ?>
                                    </p>
                                    <p class="note">La fecha se actualiza al guardar las políticas.</p>
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
        <?php include "includes/iconos.php"; ?>
        <?php include "includes/iconos_catalogo.php"; ?>
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
                    if (lista.querySelectorAll(".repeat-row").length >= 30) return;
                    lista.appendChild(plantilla.content.cloneNode(true));
                    numerarLista(lista);
                });
            });
            // botones de borrar renglon
            document.addEventListener("click", function (evento) {
                var boton = evento.target.closest(".btn-delete");
                if (!boton) return;
                var lista = boton.closest("[data-list]");
                if (!lista || lista.querySelectorAll(".repeat-row").length <= 1) return;
                boton.closest(".repeat-row").remove();
                numerarLista(lista);
            });
        </script>
    </body>
</html>
