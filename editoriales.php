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

// Elimina una editorial por GET únicamente si ningún libro la está utilizando
if (isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
    $idEliminar = (int)$_GET['eliminar'];

    $sqlUso  = "SELECT COUNT(*) AS total FROM tbllibros WHERE id_editorial = ?";
    $stmtUso = mysqli_prepare($conexion, $sqlUso);
    mysqli_stmt_bind_param($stmtUso, "i", $idEliminar);
    mysqli_stmt_execute($stmtUso);
    $resUso  = mysqli_stmt_get_result($stmtUso);
    $filaUso = mysqli_fetch_assoc($resUso);
    mysqli_stmt_close($stmtUso);

    if ((int)$filaUso['total'] === 0) {
        $sqlDel  = "DELETE FROM tbleditoriales WHERE id_editorial = ?";
        $stmtDel = mysqli_prepare($conexion, $sqlDel);
        if ($stmtDel) {
            mysqli_stmt_bind_param($stmtDel, "i", $idEliminar);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);
        }
        header('Location: editoriales.php?eliminado=1');
        exit;
    } else {
        header('Location: editoriales.php?en_uso=1');
        exit;
    }
}

// Procesa el alta de una nueva editorial validando que no esté repetida
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombreEditorial = trim($_POST['nombre_editorial'] ?? '');

    if (empty($nombreEditorial)) {
        $error = 'Servidor: Ingresa el nombre de la editorial.';
    } else {
        $sqlDup  = "SELECT id_editorial FROM tbleditoriales WHERE nombre_editorial = ?";
        $stmtDup = mysqli_prepare($conexion, $sqlDup);
        mysqli_stmt_bind_param($stmtDup, "s", $nombreEditorial);
        mysqli_stmt_execute($stmtDup);
        $resDup = mysqli_stmt_get_result($stmtDup);
        $existe = mysqli_fetch_assoc($resDup);
        mysqli_stmt_close($stmtDup);

        if ($existe) {
            $error = 'Servidor: Esa editorial ya se encuentra registrada.';
        } else {
            $sql  = "INSERT INTO tbleditoriales (nombre_editorial) VALUES (?)";
            $stmt = mysqli_prepare($conexion, $sql);

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $nombreEditorial);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    header('Location: editoriales.php?guardado=1');
                    exit;
                } else {
                    $error = 'Error al registrar la editorial: ' . mysqli_error($conexion);
                }
            }
        }
    }
}

// Calcula la paginación y obtiene el listado de editoriales registradas
$paginaActual     = (isset($_GET['p']) && is_numeric($_GET['p']) && (int)$_GET['p'] > 0) ? (int)$_GET['p'] : 1;
$porPagina        = 8;

$resConteo        = mysqli_query($conexion, "SELECT COUNT(*) AS total FROM tbleditoriales");
$filaConteo       = mysqli_fetch_assoc($resConteo);
$totalEditoriales = (int)($filaConteo['total'] ?? 0);
$totalPaginas     = $totalEditoriales > 0 ? (int)ceil($totalEditoriales / $porPagina) : 1;
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$offset = ($paginaActual - 1) * $porPagina;

$sqlLista = "SELECT e.id_editorial, e.nombre_editorial,
             (SELECT COUNT(*) FROM tbllibros l WHERE l.id_editorial = e.id_editorial) AS total_libros
             FROM tbleditoriales e
             ORDER BY e.id_editorial DESC
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
        <title>Editoriales - Biblioteca Escolar CBTa No. 5</title>

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
                            <h1 class="page-title">Gestión de editoriales</h1>
                            <p class="page-subtitle mb-0">Agrega nuevas editoriales para asignarlas en el registro de libros.</p>
                        </div>
                        <a href="libro_agregar.php" class="btn btn-outline-brand">
                            <svg class="icon"><use href="#i-arrow-left" /></svg> Volver a Alta de libro
                        </a>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                    <?php elseif (isset($_GET['guardado'])): ?>
                        <div class="alert alert-success py-2">La editorial se registró correctamente.</div>
                    <?php elseif (isset($_GET['eliminado'])): ?>
                        <div class="alert alert-secondary py-2">La editorial fue eliminada correctamente.</div>
                    <?php elseif (isset($_GET['en_uso'])): ?>
                        <div class="alert alert-warning py-2">No se puede eliminar esta editorial porque ya tiene libros registrados.</div>
                    <?php endif; ?>

                    <form id="formEditorial" method="POST" action="" novalidate class="panel p-4 mb-4">
                        <h2 class="h6 fw-semibold section-green-title mb-3">Agregar nueva editorial</h2>
                        <div class="row g-3 align-items-start">
                            <div class="col-md-9">
                                <label for="nombre_editorial" class="form-label fw-medium">Nombre de la editorial *</label>
                                <input type="text" name="nombre_editorial" id="nombre_editorial" class="form-control" value="<?= htmlspecialchars($_POST['nombre_editorial'] ?? '') ?>" placeholder="Ej. Editorial Siglo XXI" />
                                <div id="feedback-editorial" class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-3 d-grid">
                                <label class="form-label d-none d-md-block">&nbsp;</label>
                                <button type="submit" class="btn btn-brand">Guardar editorial</button>
                            </div>
                        </div>
                    </form>

                    <section class="panel overflow-hidden">
                        <div class="d-flex justify-content-between align-items-center px-4 py-3 border-bottom">
                            <h2 class="h6 fw-semibold section-green-title mb-0">Editoriales registradas</h2>
                            <span class="small text-secondary">Total: <?= $totalEditoriales ?></span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-lib align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre de la editorial</th>
                                        <th>Libros asociados</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($resultado && mysqli_num_rows($resultado) > 0): ?>
                                        <?php while ($editorial = mysqli_fetch_assoc($resultado)): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= $editorial['id_editorial'] ?></td>
                                                <td><?= htmlspecialchars($editorial['nombre_editorial']) ?></td>
                                                <td><?= (int)$editorial['total_libros'] ?></td>
                                                <td class="text-end text-nowrap">
                                                    <?php if ((int)$editorial['total_libros'] === 0): ?>
                                                        <a
                                                            href="editoriales.php?eliminar=<?= $editorial['id_editorial'] ?>"
                                                            class="btn btn-danger-soft btn-sm"
                                                            onclick="return confirm('¿Seguro que deseas eliminar la editorial: <?= htmlspecialchars($editorial['nombre_editorial'], ENT_QUOTES) ?>?');"
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
                                            <td colspan="4" class="text-center py-4 text-muted">No hay editoriales registradas.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <?php if ($totalPaginas > 1): ?>
                        <nav class="pagination-admin d-flex justify-content-center mt-4" aria-label="Paginación de editoriales">
                            <ul class="pagination mb-0">
                                <li class="page-item <?= $paginaActual <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="editoriales.php?p=<?= $paginaActual - 1 ?>">
                                        <svg class="icon"><use href="#i-arrow-left" /></svg> Anterior
                                    </a>
                                </li>
                                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                    <li class="page-item <?= $i === $paginaActual ? 'active' : '' ?>">
                                        <a class="page-link" href="editoriales.php?p=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $paginaActual >= $totalPaginas ? 'disabled' : '' ?>">
                                    <a class="page-link" href="editoriales.php?p=<?= $paginaActual + 1 ?>">
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