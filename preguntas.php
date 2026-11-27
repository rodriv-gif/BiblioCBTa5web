
<?php
require_once __DIR__ . "/includes/sesion.php";
require_once __DIR__ . "/config/conexion.php";

if (!isset($conexion) || !($conexion instanceof mysqli)) {
    die("No se encontró una conexión MySQLi válida.");
}

$preguntasFaq = array();
$preguntasDesdeBD = false;
$errorPreguntas = false;

$informacionBiblioteca = array(
    "nombre_biblioteca" => "Biblioteca Escolar",
    "ubicacion" => "",
    "correo" => "",
    "telefono" => ""
);

$escapar = function ($valor) {
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        "UTF-8"
    );
};

try {

    $sql = "SELECT pregunta,
                   respuesta
            FROM tblpreguntas_frecuentes
            ORDER BY orden ASC,
                     id_pregunta_frecuente ASC";

    $resultado = mysqli_query($conexion, $sql);

    if ($resultado === false) {
        throw new RuntimeException(
            mysqli_error($conexion)
        );
    }

    while ($fila = mysqli_fetch_assoc($resultado)) {

        $preguntasFaq[] = $fila;

    }

    mysqli_free_result($resultado);

    $preguntasDesdeBD = count($preguntasFaq) > 0;

} catch (Throwable $e) {

    $errorPreguntas = true;

    error_log(
        "Error al consultar preguntas frecuentes: "
        . $e->getMessage()
    );
}

try {

    $sqlBiblioteca = "SELECT nombre_biblioteca,
                            direccion AS ubicacion,
                            correo,
                            telefono
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
        "Error al consultar biblioteca: "
        . $e->getMessage()
    );
}

$correoBiblioteca = trim(
    (string) ($informacionBiblioteca["correo"] ?? "")
);

$telefonoBiblioteca = trim(
    (string) ($informacionBiblioteca["telefono"] ?? "")
);

?>

<!doctype html>
<html lang="es">

    <head>

        <meta charset="UTF-8" />

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        />

        <title>Preguntas frecuentes - Biblioteca Escolar</title>

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

        <link rel="stylesheet" href="./css/preguntas.css" />

        <link rel="stylesheet" href="./css/footer.css" />

        <link rel="stylesheet" href="./css/header_lector.css" />

    </head>

    <body>

        <svg
            xmlns="http://www.w3.org/2000/svg"
            style="display: none"
        >

            <symbol id="i-search" viewBox="0 0 24 24">

                <circle cx="11" cy="11" r="8" />

                <path d="m21 21-4.3-4.3" />

            </symbol>

            <symbol id="i-school" viewBox="0 0 24 24">

                <path d="M14 22v-4a2 2 0 1 0-4 0v4" />

                <path d="m18 10 4 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-8l4-2" />

                <path d="M18 5v17" />

                <path d="m4 6 8-4 8 4" />

                <path d="M6 5v17" />

                <circle cx="12" cy="9" r="2" />

            </symbol>

            <symbol id="i-book-open" viewBox="0 0 24 24">

                <path d="M12 7v14" />

                <path
                    d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"
                />

            </symbol>

            <symbol id="i-bookmark" viewBox="0 0 24 24">

                <path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z" />

            </symbol>

            <symbol id="i-library" viewBox="0 0 24 24">

                <path d="m16 6 4 14" />

                <path d="M12 6v14" />

                <path d="M8 8v12" />

                <path d="M4 4v16" />

            </symbol>

            <symbol id="i-circle-check" viewBox="0 0 24 24">

                <circle cx="12" cy="12" r="10" />

                <path d="m9 12 2 2 4-4" />

            </symbol>

            <symbol id="i-circle-x" viewBox="0 0 24 24">

                <circle cx="12" cy="12" r="10" />

                <path d="m15 9-6 6" />

                <path d="m9 9 6 6" />

            </symbol>

            <symbol id="i-calendar" viewBox="0 0 24 24">

                <path d="M8 2v4" />

                <path d="M16 2v4" />

                <rect
                    width="18"
                    height="18"
                    x="3"
                    y="4"
                    rx="2"
                />

                <path d="M3 10h18" />

            </symbol>

            <symbol id="i-clock" viewBox="0 0 24 24">

                <circle cx="12" cy="12" r="10" />

                <polyline points="12 6 12 12 16 14" />

            </symbol>

            <symbol id="i-map-pin" viewBox="0 0 24 24">

                <path
                    d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"
                />

                <circle cx="12" cy="10" r="3" />

            </symbol>

            <symbol id="i-target" viewBox="0 0 24 24">

                <circle cx="12" cy="12" r="10" />

                <circle cx="12" cy="12" r="6" />

                <circle cx="12" cy="12" r="2" />

            </symbol>

            <symbol id="i-eye" viewBox="0 0 24 24">

                <path
                    d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"
                />

                <circle cx="12" cy="12" r="3" />

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
                                        class="nav-link px-lg-3 active"
                                        aria-current="page"
                                        href="preguntas.php"
                                    >
                                        Preguntas
                                    </a>

                                </li>

                                <li class="nav-item">

                                    <a
                                        class="nav-link px-lg-3"
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

        <main>

            <?php if ($errorPreguntas): ?>

                <div class="container mt-3">

                    <div
                        class="alert alert-warning mb-0"
                        role="alert"
                    >

                        No se pudieron cargar las preguntas frecuentes.
                        Intenta nuevamente más tarde.

                    </div>

                </div>

            <?php endif; ?>

            <section class="page-header">

                <div class="container">

                    <p class="breadcrumb-line mb-0">

                        <a href="index.php">Inicio</a>
                        › Preguntas frecuentes

                    </p>

                    <h1 class="page-title">
                        Preguntas frecuentes
                    </h1>

                    <p class="page-subtitle">

                        Resuelve tus dudas sobre cuentas,
                        reservas, préstamos y devoluciones.

                    </p>

                </div>

            </section>

            <section class="section-block">

                <div class="container">

                    <div class="row g-4">

                        <div class="col-lg-8">

                            <div class="search-field">

                                <svg class="icon">

                                    <use href="#i-search" />

                                </svg>

                                <input
                                    type="search"
                                    id="faqSearch"
                                    class="form-control"
                                    placeholder="Buscar una pregunta"
                                    aria-label="Buscar una pregunta"
                                />

                            </div>

                            <div
                                class="accordion faq-list"
                                id="faqList"
                            >

                                <?php foreach (
                                    $preguntasFaq as $indice => $pregunta
                                ): ?>

                                    <?php
                                    $primeraPregunta = $indice === 0;
                                    ?>

                                    <div class="accordion-item">

                                        <h3 class="accordion-header">

                                            <button
                                                class="accordion-button <?= $primeraPregunta ? "" : "collapsed" ?>"
                                                type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#faq<?= $indice ?>"
                                                aria-expanded="<?= $primeraPregunta ? "true" : "false" ?>"
                                                aria-controls="faq<?= $indice ?>"
                                            >

                                                <?= $escapar(
                                                    $pregunta["pregunta"]
                                                ) ?>

                                            </button>

                                        </h3>

                                        <div
                                            id="faq<?= $indice ?>"
                                            class="accordion-collapse collapse <?= $primeraPregunta ? "show" : "" ?>"
                                            data-bs-parent="#faqList"
                                        >

                                            <div class="accordion-body">

                                                <?= $escapar(
                                                    $pregunta["respuesta"]
                                                ) ?>

                                            </div>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                            <p
                                id="faqEmpty"
                                class="text-secondary mt-3 <?= $preguntasDesdeBD ? "d-none" : "" ?>"
                            >

                                <?php if ($errorPreguntas): ?>

                                    Preguntas no disponibles.

                                <?php elseif (!$preguntasDesdeBD): ?>

                                    Todavía no hay preguntas frecuentes
                                    registradas.

                                <?php else: ?>

                                    No encontramos preguntas
                                    con esa búsqueda.

                                <?php endif; ?>

                            </p>

                        </div>

                        <div class="col-lg-4">

                            <div class="faq-help mt-0">

                                <h3>
                                    ¿No encuentras tu respuesta?
                                </h3>

                                <p>

                                    Pregunta directamente en la biblioteca
                                    o escríbenos y con gusto te ayudamos.

                                </p>

                                <p class="mb-1">

                                    <svg class="icon me-2">

                                        <use href="#i-mail" />

                                    </svg>

                                    <?php if ($correoBiblioteca !== ""): ?>

                                        <a href="mailto:<?= $escapar($correoBiblioteca) ?>">

                                            <?= $escapar(
                                                $correoBiblioteca
                                            ) ?>

                                        </a>

                                    <?php else: ?>

                                        Correo no disponible.

                                    <?php endif; ?>

                                </p>

                                <p class="mb-0">

                                    <svg class="icon me-2">

                                        <use href="#i-phone" />

                                    </svg>

                                    <?php if ($telefonoBiblioteca !== ""): ?>

                                        <a href="tel:<?= $escapar(
                                            preg_replace(
                                                '/[^0-9+]/',
                                                '',
                                                $telefonoBiblioteca
                                            )
                                        ) ?>">

                                            <?= $escapar(
                                                $telefonoBiblioteca
                                            ) ?>

                                        </a>

                                    <?php else: ?>

                                        Teléfono no disponible.

                                    <?php endif; ?>

                                </p>

                            </div>

                            <div class="faq-help">

                                <h3>
                                    Políticas del servicio
                                </h3>

                                <p>

                                    Consulta las reglas completas de
                                    reservas, préstamos y devoluciones.

                                </p>

                                <a href="politicas.php">
                                    Ver políticas →
                                </a>

                            </div>

                        </div>

                    </div>

                </div>

            </section>

        </main>

        <?php include __DIR__ . "/includes/footer.php"; ?>

        <script src="./js/bootstrap.bundle.min.js"></script>

        <script>

            var buscador = document.getElementById("faqSearch");

            var mensajeVacio = document.getElementById("faqEmpty");

            var preguntas = document.querySelectorAll(
                "#faqList .accordion-item"
            );

            function normalizarTexto(texto) {

                return texto
                    .toLowerCase()
                    .normalize("NFD")
                    .replace(/[\u0300-\u036f]/g, "")
                    .trim();

            }

            buscador.addEventListener("input", function () {

                if (preguntas.length === 0) {

                    return;

                }

                var texto = normalizarTexto(buscador.value);

                var visibles = 0;

                preguntas.forEach(function (item) {

                    var contenido = normalizarTexto(
                        item.textContent
                    );

                    var coincide =
                        contenido.indexOf(texto) !== -1;

                    item.classList.toggle(
                        "d-none",
                        !coincide
                    );

                    if (coincide) {

                        visibles++;

                    }

                });

                mensajeVacio.textContent =
                    "No encontramos preguntas con esa búsqueda.";

                mensajeVacio.classList.toggle(
                    "d-none",
                    visibles > 0
                );

            });

        </script>

    </body>

</html>
