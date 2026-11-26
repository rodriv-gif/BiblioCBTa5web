<?php
session_start();

// Candado de seguridad: verifica que exista sesión de bibliotecario activa
if (!isset($_SESSION['id_bibliotecario'])) {
    header('Location: login.php?error=acceso_denegado');
    exit;
}

require_once __DIR__ . '/config/conexion.php';
$pagina_activa = "libro_agregar.php";
$error = '';

// Elimina un autor recibido por GET únicamente si no tiene libros asociados
if (isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
    $idEliminar = (int)$_GET['eliminar'];

    $sqlUso  = "SELECT COUNT(*) AS total FROM tbllibro_autor WHERE id_autor = ?";
    $stmtUso = mysqli_prepare($conexion, $sqlUso);
    mysqli_stmt_bind_param($stmtUso, "i", $idEliminar);
    mysqli_stmt_execute($stmtUso);
    $resUso  = mysqli_stmt_get_result($stmtUso);
    $filaUso = mysqli_fetch_assoc($resUso);
    mysqli_stmt_close($stmtUso);

    if ((int)$filaUso['total'] === 0) {
        $sqlDel  = "DELETE FROM tblautores WHERE id_autor = ?";
        $stmtDel = mysqli_prepare($conexion, $sqlDel);
        if ($stmtDel) {
            mysqli_stmt_bind_param($stmtDel, "i", $idEliminar);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);
        }
        header('Location: autores.php?eliminado=1');
        exit;
    } else {
        header('Location: autores.php?en_uso=1');
        exit;
    }
}

// Procesa el alta de un nuevo autor cuando el formulario se envía por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre          = trim($_POST['nombre'] ?? '');
    $apellidoPaterno = trim($_POST['apellido_paterno'] ?? '');
    $apellidoMaterno = trim($_POST['apellido_materno'] ?? '');

    if (empty($nombre) || empty($apellidoPaterno)) {
        $error = 'Servidor: El nombre y el apellido paterno son obligatorios.';
    } else {
        $maternoGuardar = ($apellidoMaterno !== '') ? $apellidoMaterno : null;

        $sql  = "INSERT INTO tblautores (nombre, apellido_paterno, apellido_materno) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($conexion, $sql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "sss", $nombre, $apellidoPaterno, $maternoGuardar);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                header('Location: autores.php?guardado=1');
                exit;
            } else {
                $error = 'Error al registrar el autor: ' . mysqli_error($conexion);
            }
        }
    }
}

// Calcula la paginación y obtiene el listado de autores ordenados del más reciente al más antiguo
$paginaActual = (isset($_GET['p']) && is_numeric($_GET['p']) && (int)$_GET['p'] > 0) ? (int)$_GET['p'] : 1;
$porPagina    = 8;

$resConteo    = mysqli_query($conexion, "SELECT COUNT(*) AS total FROM tblautores");
$filaConteo   = mysqli_fetch_assoc($resConteo);
$totalAutores = (int)($filaConteo['total'] ?? 0);
$totalPaginas = $totalAutores > 0 ? (int)ceil($totalAutores / $porPagina) : 1;
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$offset = ($paginaActual - 1) * $porPagina;

$sqlLista = "SELECT a.id_autor, a.nombre, a.apellido_paterno, a.apellido_materno,
             (SELECT COUNT(*) FROM tbllibro_autor la WHERE la.id_autor = a.id_autor) AS total_libros
             FROM tblautores a
             ORDER BY a.id_autor DESC
             LIMIT ? OFFSET ?";
$stmtLista = mysqli_prepare($conexion, $sqlLista);
mysqli_stmt_bind_param($stmtLista, "ii", $porPagina, $offset);
mysqli_stmt_execute($stmtLista);
$resultado = mysqli_stmt_get_result($stmtLista);
?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Autores - Biblioteca Escolar CBTa No. 5</title>

        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" />
        <link rel="stylesheet" href="./css/bootstrap.min.css" />
        <link rel="stylesheet" href="./css/estilos.css" />
        <link rel="stylesheet" href="./css/bibliotecario.css" />
    </head>

    <body>
        <?php include "includes/iconos.php"; ?>
        <?php include "includes/iconos_catalogo.php"; ?>

        <div class="admin-layout">
            <?php include "includes/sidebar_bibliotecario.php"; ?>

            <main class="admin-main">
                <div class="admin-content">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                        <div>
                            <h1 class="page-title">Gestión de autores</h1>
                            <p class="page-subtitle mb-0">Agrega nuevos autores para seleccionarlos al registrar o editar un libro.</p>
                        </div>
                        <a href="libro_agregar.php" class="btn btn-outline-brand">
                            <svg class="icon"><use href="#i-arrow-left" /></svg> Volver a Alta de libro
                        </a>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                    <?php elseif (isset($_GET['guardado'])): ?>
                        <div class="alert alert-success py-2">El autor se registró correctamente.</div>
                    <?php elseif (isset($_GET['eliminado'])): ?>
                        <div class="alert alert-secondary py-2">El autor fue eliminado correctamente.</div>
                    <?php elseif (isset($_GET['en_uso'])): ?>
                        <div class="alert alert-warning py-2">No se puede eliminar este autor porque ya tiene libros asignados.</div>
                    <?php endif; ?>

                    <form id="formAutor" method="POST" action="" novalidate class="panel p-4 mb-4">
                        <h2 class="h6 fw-semibold section-green-title mb-3">Agregar nuevo autor</h2>
                        <div class="row g-3 align-items-start">
                            <div class="col-md-4">
                                <label for="nombre" class="form-label fw-medium">Nombre(s) *</label>
                                <input type="text" name="nombre" id="nombre" class="form-control" value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" />
                                <div id="feedback-nombre" class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-3">
                                <label for="apellido_paterno" class="form-label fw-medium">Apellido paterno *</label>
                                <input type="text" name="apellido_paterno" id="apellido_paterno" class="form-control" value="<?= htmlspecialchars($_POST['apellido_paterno'] ?? '') ?>" />
                                <div id="feedback-paterno" class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-3">
                                <label for="apellido_materno" class="form-label fw-medium">Apellido materno</label>
                                <input type="text" name="apellido_materno" id="apellido_materno" class="form-control" value="<?= htmlspecialchars($_POST['apellido_materno'] ?? '') ?>" />
                            </div>

                            <div class="col-md-2 d-grid">
                                <label class="form-label d-none d-md-block">&nbsp;</label>
                                <button type="submit" class="btn btn-brand">Guardar</button>
                            </div>
                        </div>
                    </form>

                    <section class="panel overflow-hidden">
                        <div class="d-flex justify-content-between align-items-center px-4 py-3 border-bottom">
                            <h2 class="h6 fw-semibold section-green-title mb-0">Autores registrados</h2>
                            <span class="small text-secondary">Total: <?= $totalAutores ?></span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-lib align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre completo</th>
                                        <th>Libros asociados</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($resultado && mysqli_num_rows($resultado) > 0): ?>
                                        <?php while ($autor = mysqli_fetch_assoc($resultado)): ?>
                                            <?php
                                            $nombreCompleto = trim($autor['nombre'] . ' ' . $autor['apellido_paterno'] . ' ' . ($autor['apellido_materno'] ?? ''));
                                            ?>
                                            <tr>
                                                <td class="fw-semibold"><?= $autor['id_autor'] ?></td>
                                                <td><?= htmlspecialchars($nombreCompleto) ?></td>
                                                <td><?= (int)$autor['total_libros'] ?></td>
                                                <td class="text-end text-nowrap">
                                                    <?php if ((int)$autor['total_libros'] === 0): ?>
                                                        <a
                                                            href="autores.php?eliminar=<?= $autor['id_autor'] ?>"
                                                            class="btn btn-danger-soft btn-sm"
                                                            onclick="return confirm('¿Seguro que deseas eliminar al autor: <?= htmlspecialchars($nombreCompleto, ENT_QUOTES) ?>?');"
                                                        >
                                                            <svg class="icon"><use href="#i-trash" /></svg> Eliminar
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="small text-secondary">En uso</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">No hay autores registrados.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <?php if ($totalPaginas > 1): ?>
                        <nav class="pagination-admin d-flex justify-content-center mt-4" aria-label="Paginación de autores">
                            <ul class="pagination mb-0">
                                <li class="page-item <?= $paginaActual <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="autores.php?p=<?= $paginaActual - 1 ?>">
                                        <svg class="icon"><use href="#i-arrow-left" /></svg> Anterior
                                    </a>
                                </li>
                                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                    <li class="page-item <?= $i === $paginaActual ? 'active' : '' ?>">
                                        <a class="page-link" href="autores.php?p=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $paginaActual >= $totalPaginas ? 'disabled' : '' ?>">
                                    <a class="page-link" href="autores.php?p=<?= $paginaActual + 1 ?>">
                                        Siguiente <svg class="icon"><use href="#i-arrow-right" /></svg>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </main>
        </div>

        <?php mysqli_stmt_close($stmtLista); ?>
        <script src="./js/bootstrap.bundle.min.js"></script>
        <script src="./js/ver_password.js"></script>
        <script src="./js/validar_catalogos.js"></script>
    </body>
</html>