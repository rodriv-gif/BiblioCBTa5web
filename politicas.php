
<?php
require_once __DIR__ . "/includes/sesion.php";
require_once __DIR__ . "/config/conexion.php";

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    die("No se encontró una conexión MySQLi válida.");
}

$politicas = array();
$politicasDesdeBD = false;
$errorPoliticas = false;
$ultimaActualizacion = null;

$informacionBiblioteca = array(
    "nombre_biblioteca" => "Biblioteca Escolar",
    "direccion" => "",
    "ubicacion" => "",
    "correo" => "",
    "telefono" => "",
    "lema" => ""
);

$escapar = function ($valor) {
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        "UTF-8"
    );
};

try {

    $sqlPoliticas = "SELECT id_politica,
                            titulo,
                            contenido,
                            orden,
                            actualizado_en
                     FROM tblpoliticas_servicio
                     ORDER BY orden ASC, id_politica ASC";

    $resultadoPoliticas = mysqli_query(
        $conexion,
        $sqlPoliticas
    );

    if ($resultadoPoliticas === false) {
        throw new RuntimeException(
            mysqli_error($conexion)
        );
    }

    while ($fila = mysqli_fetch_assoc($resultadoPoliticas)) {

        $politicas[] = $fila;

        if (!empty($fila["actualizado_en"])) {

            if (
                $ultimaActualizacion === null ||
                $fila["actualizado_en"] > $ultimaActualizacion
            ) {
                $ultimaActualizacion = $fila["actualizado_en"];
            }
        }
    }

    mysqli_free_result($resultadoPoliticas);

    $politicasDesdeBD = count($politicas) > 0;

} catch (Throwable $e) {

    $errorPoliticas = true;

    error_log(
        "Error al consultar políticas: " . $e->getMessage()
    );
}

try {

    $sqlBiblioteca = "SELECT nombre_biblioteca,
                             direccion,
                             direccion AS ubicacion,
                             correo,
                             telefono,
                             lema
                      FROM tblbiblioteca
                      ORDER BY id_biblioteca ASC
                      LIMIT 1";

    $resultadoBiblioteca = mysqli_query(
        $conexion,
        $sqlBiblioteca
    );

    if ($resultadoBiblioteca === false) {
        throw new RuntimeException(
            mysqli_error($conexion)
        );
    }

    $datosBiblioteca = mysqli_fetch_assoc(
        $resultadoBiblioteca
    );

    if (is_array($datosBiblioteca)) {

        $informacionBiblioteca = array_replace(
            $informacionBiblioteca,
            $datosBiblioteca
        );
    }

    mysqli_free_result($resultadoBiblioteca);

} catch (Throwable $e) {

    error_log(
        "Error al consultar biblioteca: " . $e->getMessage()
    );
}

$correoBiblioteca = trim(
    (string) ($informacionBiblioteca["correo"] ?? "")
);

$telefonoBiblioteca = trim(
    (string) ($informacionBiblioteca["telefono"] ?? "")
);

$direccionBiblioteca = trim(
    (string) ($informacionBiblioteca["direccion"] ?? "")
);

$fechaActualizacion = "Sin registro";

if (!empty($ultimaActualizacion)) {

    $fecha = strtotime($ultimaActualizacion);

    if ($fecha !== false) {

        $fechaActualizacion = date(
            "d/m/Y H:i",
            $fecha
        );
    }
}

?>

<!doctype html>
<html lang="es">

    <head>

        <meta charset="UTF-8" />

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        />

        <title>
            Políticas y condiciones del servicio bibliotecario - Biblioteca Escolar
        </title>

        <link rel="preconnect" href="https://fonts.googleapis.com" />

        <link
            rel="preconnect"
            href="https://fonts.gstatic.com"
            crossorigin
        />

        <link
            href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
            rel="stylesheet"
        />

        <link rel="stylesheet" href="./css/bootstrap.min.css" />

        <link rel="stylesheet" href="./css/politicas.css" />

        <link rel="stylesheet" href="./css/footer.css" />

        <link rel="stylesheet" href="./css/header_lector.css" />

    </head>

    <body
        data-bs-spy="scroll"
        data-bs-target="#policyIndex"
        data-bs-offset="40"
    >

        <svg
            xmlns="http://www.w3.org/2000/svg"
            style="display: none"
        >

            <symbol id="i-circle-check" viewBox="0 0 24 24">

                <circle cx="12" cy="12" r="10" />

                <path d="m9 12 2 2 4-4" />

            </symbol>

            <symbol id="i-arrow-left" viewBox="0 0 24 24">

                <path d="m12 19-7-7 7-7" />

                <path d="M19 12H5" />

            </symbol>

            <symbol id="i-map-pin" viewBox="0 0 24 24">

                <path
                    d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"
                />

                <circle cx="12" cy="10" r="3" />

            </symbol>

            <symbol id="i-mail" viewBox="0 0 24 24">

                <rect
                    width="20"
                    height="16"
                    x="2"
                    y="4"
                    rx="2"
                />

                <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />

            </symbol>

            <symbol id="i-phone" viewBox="0 0 24 24">

                <path
                    d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"
                />

            </symbol>

        </svg>

        <?php if (es_lector()): ?>

            <?php include __DIR__ . "/includes/iconos.php"; ?>

            <?php include __DIR__ . "/includes/header_lector.php"; ?>

        <?php else: ?>

            <header class="site-header d-flex align-items-center">

                <nav class="navbar navbar-expand-lg py-2 w-100">

                    <div class="container">

                        <a
                            class="navbar-brand d-flex align-items-center gap-2 m-0"
                            href="index.php"
                        >

                            <img
                                src="./img/logo-cbta5-blanco.png"
                                alt="Logotipo del CBTa No. 5"
                                class="brand-logo"
                            />

                            <span>

                                <span class="brand-name d-block">
                                    Biblioteca Escolar
                                </span>

                                <span class="brand-sub d-block">
                                    CBTa No. 5 · Huejutla, Hgo.
                                </span>

                            </span>

                        </a>

                        <button
                            class="navbar-toggler border-light"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#mainMenu"
                            aria-controls="mainMenu"
                            aria-expanded="false"
                            aria-label="Abrir menú"
                        >

                            <span
                                class="navbar-toggler-icon"
                                style="filter: invert(1)"
                            ></span>

                        </button>

                        <div
                            class="collapse navbar-collapse"
                            id="mainMenu"
                        >

                            <ul class="navbar-nav mx-lg-auto mb-3 mb-lg-0 gap-lg-3">

                                <li class="nav-item">

                                    <a
                                        class="nav-link px-lg-3"
                                        href="index.php"
                                    >
                                        Inicio
                                    </a>

                                </li>

                                <li class="nav-item">

                                    <a
                                        class="nav-link px-lg-3"
                                        href="nosotros.php"
                                    >
                                        Nosotros
                                    </a>

                                </li>

                                <li class="nav-item">

                                    <a
                                        class="nav-link px-lg-3"
                                        href="preguntas.php"
                                    >
                                        Preguntas
                                    </a>

                                </li>

                                <li class="nav-item">

                                    <a
                                        class="nav-link px-lg-3 active"
                                        aria-current="page"
                                        href="politicas.php"
                                    >
                                        Políticas
                                    </a>

                                </li>

                            </ul>

                            <div class="d-flex gap-2">

                                <a
                                    href="registro.php"
                                    class="btn btn-brand-outline"
                                >
                                    Registrarse
                                </a>

                                <a
                                    href="login.php"
                                    class="btn btn-brand-light"
                                >
                                    Iniciar sesión
                                </a>

                            </div>

                        </div>

                    </div>

                </nav>

            </header>

        <?php endif; ?>

        <main class="page-block">

            <div class="container">

                <?php if ($errorPoliticas): ?>

                    <div
                        class="alert alert-warning"
                        role="alert"
                    >

                        No se pudieron cargar las políticas del servicio.
                        Intenta nuevamente más tarde.

                    </div>

                <?php elseif (!$politicasDesdeBD): ?>

                    <div
                        class="alert alert-warning"
                        role="alert"
                    >

                        Todavía no hay políticas del servicio registradas.

                    </div>

                <?php endif; ?>

                <div class="breadcrumb-line">

                    <a href="index.php">
                        Inicio
                    </a>

                    <span>›</span>

                    <span>
                        Políticas y condiciones del servicio bibliotecario
                    </span>

                </div>

                <h1 class="page-title">

                    Políticas y condiciones del servicio bibliotecario

                </h1>

                <p class="page-lead">

                    Conoce las reglas para reservar, pedir prestados
                    y devolver libros, y cómo cuidamos tus datos
                    al usar la biblioteca del CBTa No. 5.

                </p>

                <div class="row g-4 mt-2">

                    <div class="col-lg-3">

                        <nav
                            class="index-card"
                            id="policyIndex"
                        >

                            <h2>En esta página</h2>

                            <div class="nav flex-column">

                                <?php foreach (
                                    $politicas as $indice => $politica
                                ): ?>

                                    <a
                                        class="nav-link"
                                        href="#politica<?= $indice + 1 ?>"
                                    >

                                        <span>

                                            <?= $indice + 1 ?>

                                        </span>

                                        <?= $escapar(
                                            $politica["titulo"]
                                        ) ?>

                                    </a>

                                <?php endforeach; ?>

                                <?php if (!$politicasDesdeBD): ?>

                                    <p class="text-secondary mb-0">

                                        No hay políticas disponibles.

                                    </p>

                                <?php endif; ?>

                            </div>

                        </nav>

                    </div>

                    <div class="col-lg-9">

                        <?php foreach (
                            $politicas as $indice => $politica
                        ): ?>

                            <?php

                            $contenido = trim(
                                (string) ($politica["contenido"] ?? "")
                            );

                            $lineas = preg_split(
                                '/\R/u',
                                $contenido
                            );

                            if ($lineas === false) {
                                $lineas = array($contenido);
                            }

                            $lineas = array_values(
                                array_filter(
                                    array_map("trim", $lineas),
                                    function ($linea) {
                                        return $linea !== "";
                                    }
                                )
                            );

                            ?>

                            <section
                                class="policy-card"
                                id="politica<?= $indice + 1 ?>"
                            >

                                <div class="policy-head">

                                    <div class="policy-number">

                                        <?= $indice + 1 ?>

                                    </div>

                                    <h2>

                                        <?= $escapar(
                                            $politica["titulo"]
                                        ) ?>

                                    </h2>

                                </div>

                                <?php if (count($lineas) > 1): ?>

                                    <ul class="policy-list">

                                        <?php foreach (
                                            $lineas as $linea
                                        ): ?>

                                            <li>

                                                <svg class="icon">

                                                    <use href="#i-circle-check" />

                                                </svg>

                                                <span>

                                                    <?= $escapar(
                                                        $linea
                                                    ) ?>

                                                </span>

                                            </li>

                                        <?php endforeach; ?>

                                    </ul>

                                <?php elseif (count($lineas) === 1): ?>

                                    <p>

                                        <?= $escapar(
                                            $lineas[0]
                                        ) ?>

                                    </p>

                                <?php else: ?>

                                    <p class="text-secondary">

                                        Contenido no disponible.

                                    </p>

                                <?php endif; ?>

                            </section>

                        <?php endforeach; ?>

                        <div class="update-box">

                            <div>

                                <strong>

                                    Última actualización

                                </strong>

                                <?= $escapar(
                                    $fechaActualizacion
                                ) ?>

                            </div>

                            <a
                                href="index.php"
                                class="btn-back"
                            >

                                <svg class="icon">

                                    <use href="#i-arrow-left" />

                                </svg>

                                Volver al inicio

                            </a>

                        </div>

                    </div>

                </div>

            </div>

        </main>

        <?php include __DIR__ . "/includes/footer.php"; ?>

        <script src="./js/bootstrap.bundle.min.js"></script>

    </body>

</html>
