<?php
session_start();

// Candado de seguridad: verifica que exista sesión de bibliotecario activa
if (!isset($_SESSION['id_bibliotecario'])) {
    header('Location: login.php?error=acceso_denegado');
    exit;
}

require_once __DIR__ . '/config/conexion.php';
$pagina_activa = "libro_agregar.php";
$error         = '';

// Elimina una categoría por GET únicamente si ningún libro la está utilizando
if (isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
    $idEliminar = (int)$_GET['eliminar'];

    $sqlUso  = "SELECT COUNT(*) AS total FROM tbllibros WHERE id_categoria = ?";
    $stmtUso = mysqli_prepare($conexion, $sqlUso);
    mysqli_stmt_bind_param($stmtUso, "i", $idEliminar);
    mysqli_stmt_execute($stmtUso);
    $resUso  = mysqli_stmt_get_result($stmtUso);
    $filaUso = mysqli_fetch_assoc($resUso);
    mysqli_stmt_close($stmtUso);

    if ((int)$filaUso['total'] === 0) {
        $sqlDel  = "DELETE FROM tblcategorias WHERE id_categoria = ?";
        $stmtDel = mysqli_prepare($conexion, $sqlDel);
        if ($stmtDel) {
            mysqli_stmt_bind_param($stmtDel, "i", $idEliminar);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);
        }
        header('Location: categorias.php?eliminado=1');
        exit;
    } else {
        header('Location: categorias.php?en_uso=1');
        exit;
    }
}

// Procesa el alta de una nueva categoría comprobando que no exista otra con el mismo nombre
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombreCategoria = trim($_POST['nombre_categoria'] ?? '');

    if (empty($nombreCategoria)) {
        $error = 'Servidor: Ingresa el nombre de la categoría.';
    } else {
        $sqlDup  = "SELECT id_categoria FROM tblcategorias WHERE nombre = ?";
        $stmtDup = mysqli_prepare($conexion, $sqlDup);
        mysqli_stmt_bind_param($stmtDup, "s", $nombreCategoria);
        mysqli_stmt_execute($stmtDup);
        $resDup = mysqli_stmt_get_result($stmtDup);
        $existe = mysqli_fetch_assoc($resDup);
        mysqli_stmt_close($stmtDup);

        if ($existe) {
            $error = 'Servidor: Esa categoría ya se encuentra registrada.';
        } else {
            $sql  = "INSERT INTO tblcategorias (nombre) VALUES (?)";
            $stmt = mysqli_prepare($conexion, $sql);

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $nombreCategoria);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    header('Location: categorias.php?guardado=1');
                    exit;
                } else {
                    $error = 'Error al registrar la categoría: ' . mysqli_error($conexion);
                }
            }
        }
    }
}

// Calcula la paginación y consulta el listado de categorías registradas
$paginaActual    = (isset($_GET['p']) && is_numeric($_GET['p']) && (int)$_GET['p'] > 0) ? (int)$_GET['p'] : 1;
$porPagina       = 8;

$resConteo       = mysqli_query($conexion, "SELECT COUNT(*) AS total FROM tblcategorias");
$filaConteo      = mysqli_fetch_assoc($resConteo);
$totalCategorias = (int)($filaConteo['total'] ?? 0);
$totalPaginas    = $totalCategorias > 0 ? (int)ceil($totalCategorias / $porPagina) : 1;
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$offset = ($paginaActual - 1) * $porPagina;

$sqlLista = "SELECT c.id_categoria, c.nombre,
             (SELECT COUNT(*) FROM tbllibros l WHERE l.id_categoria = c.id_categoria) AS total_libros
             FROM tblcategorias c
             ORDER BY c.id_categoria DESC
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
        <title>Categorías - Biblioteca Escolar CBTa No. 5</title>

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
                            <h1 class="page-title">Gestión de categorías</h1>
                            <p class="page-subtitle mb-0">Agrega nuevas áreas o categorías temáticas para clasificar los libros.</p>
                        </div>
                        <a href="libro_agregar.php" class="btn btn-outline-brand">
                            <svg class="icon"><use href="#i-arrow-left" /></svg> Volver a Alta de libro
                        </a>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                    <?php elseif (isset($_GET['guardado'])): ?>
                        <div class="alert alert-success py-2">La categoría se registró correctamente.</div>
                    <?php elseif (isset($_GET['eliminado'])): ?>
                        <div class="alert alert-secondary py-2">La categoría fue eliminada correctamente.</div>
                    <?php elseif (isset($_GET['en_uso'])): ?>
                        <div class="alert alert-warning py-2">No se puede eliminar esta categoría porque ya tiene libros asociados.</div>
                    <?php endif; ?>

                    <form id="formCategoria" method="POST" action="" novalidate class="panel p-4 mb-4">
                        <h2 class="h6 fw-semibold section-green-title mb-3">Agregar nueva categoría</h2>
                        <div class="row g-3 align-items-start">
                            <div class="col-md-9">
                                <label for="nombre_categoria" class="form-label fw-medium">Nombre de la categoría *</label>
                                <input type="text" name="nombre_categoria" id="nombre_categoria" class="form-control" value="<?= htmlspecialchars($_POST['nombre_categoria'] ?? '') ?>" placeholder="Ej. Agropecuaria y Desarrollo Sustentable" />
                                <div id="feedback-categoria-catalogo" class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-3 d-grid">
                                <label class="form-label d-none d-md-block">&nbsp;</label>
                                <button type="submit" class="btn btn-brand">Guardar categoría</button>
                            </div>
                        </div>
                    </form>

                    <section class="panel overflow-hidden">
                        <div class="d-flex justify-content-between align-items-center px-4 py-3 border-bottom">
                            <h2 class="h6 fw-semibold section-green-title mb-0">Categorías registradas</h2>
                            <span class="small text-secondary">Total: <?= $totalCategorias ?></span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-lib align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre de la categoría</th>
                                        <th>Libros asociados</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($resultado && mysqli_num_rows($resultado) > 0): ?>
                                        <?php while ($cat = mysqli_fetch_assoc($resultado)): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= $cat['id_categoria'] ?></td>
                                                <td><?= htmlspecialchars($cat['nombre']) ?></td>
                                                <td><?= (int)$cat['total_libros'] ?></td>
                                                <td class="text-end text-nowrap">
                                                    <?php if ((int)$cat['total_libros'] === 0): ?>
                                                        <a
                                                            href="categorias.php?eliminar=<?= $cat['id_categoria'] ?>"
                                                            class="btn btn-danger-soft btn-sm"
                                                            onclick="return confirm('¿Seguro que deseas eliminar la categoría: <?= htmlspecialchars($cat['nombre'], ENT_QUOTES) ?>?');"
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
                                            <td colspan="4" class="text-center py-4 text-muted">No hay categorías registradas.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <?php if ($totalPaginas > 1): ?>
                        <nav class="pagination-admin d-flex justify-content-center mt-4" aria-label="Paginación de categorías">
                            <ul class="pagination mb-0">
                                <li class="page-item <?= $paginaActual <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="categorias.php?p=<?= $paginaActual - 1 ?>">
                                        <svg class="icon"><use href="#i-arrow-left" /></svg> Anterior
                                    </a>
                                </li>
                                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                    <li class="page-item <?= $i === $paginaActual ? 'active' : '' ?>">
                                        <a class="page-link" href="categorias.php?p=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $paginaActual >= $totalPaginas ? 'disabled' : '' ?>">
                                    <a class="page-link" href="categorias.php?p=<?= $paginaActual + 1 ?>">
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