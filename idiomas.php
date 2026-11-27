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

// Elimina un idioma por GET únicamente si ningún libro lo está utilizando
if (isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
    $idEliminar = (int)$_GET['eliminar'];

    $sqlUso  = "SELECT COUNT(*) AS total FROM tbllibros WHERE id_idioma = ?";
    $stmtUso = mysqli_prepare($conexion, $sqlUso);
    mysqli_stmt_bind_param($stmtUso, "i", $idEliminar);
    mysqli_stmt_execute($stmtUso);
    $resUso  = mysqli_stmt_get_result($stmtUso);
    $filaUso = mysqli_fetch_assoc($resUso);
    mysqli_stmt_close($stmtUso);

    if ((int)$filaUso['total'] === 0) {
        $sqlDel  = "DELETE FROM tblidiomas WHERE id_idioma = ?";
        $stmtDel = mysqli_prepare($conexion, $sqlDel);
        if ($stmtDel) {
            mysqli_stmt_bind_param($stmtDel, "i", $idEliminar);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);
        }
        header('Location: idiomas.php?eliminado=1');
        exit;
    } else {
        header('Location: idiomas.php?en_uso=1');
        exit;
    }
}

// Procesa el alta de un nuevo idioma comprobando que no exista otro igual
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombreIdioma = trim($_POST['nombre_idioma'] ?? '');

    if (empty($nombreIdioma)) {
        $error = 'Servidor: Ingresa el nombre del idioma.';
    } else {
        $sqlDup  = "SELECT id_idioma FROM tblidiomas WHERE nombre_idioma = ?";
        $stmtDup = mysqli_prepare($conexion, $sqlDup);
        mysqli_stmt_bind_param($stmtDup, "s", $nombreIdioma);
        mysqli_stmt_execute($stmtDup);
        $resDup = mysqli_stmt_get_result($stmtDup);
        $existe = mysqli_fetch_assoc($resDup);
        mysqli_stmt_close($stmtDup);

        if ($existe) {
            $error = 'Servidor: Ese idioma ya se encuentra registrado.';
        } else {
            $sql  = "INSERT INTO tblidiomas (nombre_idioma) VALUES (?)";
            $stmt = mysqli_prepare($conexion, $sql);

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $nombreIdioma);
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    header('Location: idiomas.php?guardado=1');
                    exit;
                } else {
                    $error = 'Error al registrar el idioma: ' . mysqli_error($conexion);
                }
            }
        }
    }
}

// Calcula la paginación y consulta el listado de idiomas registrados
$paginaActual = (isset($_GET['p']) && is_numeric($_GET['p']) && (int)$_GET['p'] > 0) ? (int)$_GET['p'] : 1;
$porPagina    = 8;

$resConteo    = mysqli_query($conexion, "SELECT COUNT(*) AS total FROM tblidiomas");
$filaConteo   = mysqli_fetch_assoc($resConteo);
$totalIdiomas = (int)($filaConteo['total'] ?? 0);
$totalPaginas = $totalIdiomas > 0 ? (int)ceil($totalIdiomas / $porPagina) : 1;
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$offset = ($paginaActual - 1) * $porPagina;

$sqlLista = "SELECT i.id_idioma, i.nombre_idioma,
             (SELECT COUNT(*) FROM tbllibros l WHERE l.id_idioma = i.id_idioma) AS total_libros
             FROM tblidiomas i
             ORDER BY i.id_idioma DESC
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
        <title>Idiomas - Biblioteca Escolar CBTa No. 5</title>

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
                            <h1 class="page-title">Gestión de idiomas</h1>
                            <p class="page-subtitle mb-0">Agrega nuevos idiomas disponibles para los ejemplares de la biblioteca.</p>
                        </div>
                        <a href="libro_agregar.php" class="btn btn-outline-brand">
                            <svg class="icon"><use href="#i-arrow-left" /></svg> Volver a Alta de libro
                        </a>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                    <?php elseif (isset($_GET['guardado'])): ?>
                        <div class="alert alert-success py-2">El idioma se registró correctamente.</div>
                    <?php elseif (isset($_GET['eliminado'])): ?>
                        <div class="alert alert-secondary py-2">El idioma fue eliminado correctamente.</div>
                    <?php elseif (isset($_GET['en_uso'])): ?>
                        <div class="alert alert-warning py-2">No se puede eliminar este idioma porque ya tiene libros asociados.</div>
                    <?php endif; ?>

                    <form id="formIdioma" method="POST" action="" novalidate class="panel p-4 mb-4">
                        <h2 class="h6 fw-semibold section-green-title mb-3">Agregar nuevo idioma</h2>
                        <div class="row g-3 align-items-start">
                            <div class="col-md-9">
                                <label for="nombre_idioma" class="form-label fw-medium">Nombre del idioma *</label>
                                <input type="text" name="nombre_idioma" id="nombre_idioma" class="form-control" value="<?= htmlspecialchars($_POST['nombre_idioma'] ?? '') ?>" placeholder="Ej. Náhuatl o Italiano" />
                                <div id="feedback-idioma-catalogo" class="invalid-feedback"></div>
                            </div>

                            <div class="col-md-3 d-grid">
                                <label class="form-label d-none d-md-block">&nbsp;</label>
                                <button type="submit" class="btn btn-brand">Guardar idioma</button>
                            </div>
                        </div>
                    </form>

                    <section class="panel overflow-hidden">
                        <div class="d-flex justify-content-between align-items-center px-4 py-3 border-bottom">
                            <h2 class="h6 fw-semibold section-green-title mb-0">Idiomas registrados</h2>
                            <span class="small text-secondary">Total: <?= $totalIdiomas ?></span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover table-lib align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre del idioma</th>
                                        <th>Libros asociados</th>
                                        <th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($resultado && mysqli_num_rows($resultado) > 0): ?>
                                        <?php while ($idm = mysqli_fetch_assoc($resultado)): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= $idm['id_idioma'] ?></td>
                                                <td><?= htmlspecialchars($idm['nombre_idioma']) ?></td>
                                                <td><?= (int)$idm['total_libros'] ?></td>
                                                <td class="text-end text-nowrap">
                                                    <?php if ((int)$idm['total_libros'] === 0): ?>
                                                        <a
                                                            href="idiomas.php?eliminar=<?= $idm['id_idioma'] ?>"
                                                            class="btn btn-danger-soft btn-sm"
                                                            onclick="return confirm('¿Seguro que deseas eliminar el idioma: <?= htmlspecialchars($idm['nombre_idioma'], ENT_QUOTES) ?>?');"
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
                                            <td colspan="4" class="text-center py-4 text-muted">No hay idiomas registrados.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <?php if ($totalPaginas > 1): ?>
                        <nav class="pagination-admin d-flex justify-content-center mt-4" aria-label="Paginación de idiomas">
                            <ul class="pagination mb-0">
                                <li class="page-item <?= $paginaActual <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="idiomas.php?p=<?= $paginaActual - 1 ?>">
                                        <svg class="icon"><use href="#i-arrow-left" /></svg> Anterior
                                    </a>
                                </li>
                                <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                    <li class="page-item <?= $i === $paginaActual ? 'active' : '' ?>">
                                        <a class="page-link" href="idiomas.php?p=<?= $i ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $paginaActual >= $totalPaginas ? 'disabled' : '' ?>">
                                    <a class="page-link" href="idiomas.php?p=<?= $paginaActual + 1 ?>">
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