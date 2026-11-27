<?php
require "includes/sesion.php";
exigir_lector();
require "config/conexion.php"; // aquí viene la conexión $pdo
require "includes/consultas_lector.php";
$pagina_activa = "mi_cuenta.php";
$titulo = "Mis adeudos";
$subtitulo = "Multas por retraso y reposiciones de libros.";
$icono = "i-triangle-alert";

// Todos los adeudos del lector (tblmultas y tblreposiciones).
// El total suma solo las multas que siguen pendientes.
[$adeudos, $resumen] = adeudos_del_lector($pdo);

// Filtro de la tabla: todos, pendientes o resueltos
$ver = $_GET["ver"] ?? "todos";

if (!in_array($ver, ["todos", "pendientes", "resueltos"], true)) {
    $ver = "todos";
}

$cuantos_pendientes = $resumen["pendientes"];
$cuantos_resueltos = count($adeudos) - $cuantos_pendientes;

$filtros = [
    "todos" => ["Todos", count($adeudos)],
    "pendientes" => ["Pendientes", $cuantos_pendientes],
    "resueltos" => ["Resueltos", $cuantos_resueltos],
];

$mostrar = [];

foreach ($adeudos as $adeudo) {
    if ($ver === "todos" || ($ver === "pendientes" && $adeudo["pendiente"]) || ($ver === "resueltos" && !$adeudo["pendiente"])) {
        $mostrar[] = $adeudo;
    }
}

include "includes/inicio_cuenta.php";
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($filtros as $clave => [$texto, $cantidad]): ?>
        <a href="mis_adeudos.php?ver=<?= $clave ?>" class="btn btn-sm <?= $ver === $clave ? "btn-success" : "btn-outline-green" ?>">
            <?= e($texto) ?> (<?= $cantidad ?>)
        </a>
    <?php endforeach; ?>
</div>

<div class="panel overflow-hidden">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Libro</th>
                    <th>Detalle</th>
                    <th>Estado</th>
                    <th class="text-end">Monto</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($mostrar) === 0): ?>
                    <tr>
                        <td colspan="5" class="text-center text-secondary py-5">
                            <?= count($adeudos) === 0 ? "No tienes adeudos. ¡Qué bien!" : "No hay adeudos en este filtro." ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($mostrar as $adeudo): ?>
                    <tr>
                        <td><?= e($adeudo["tipo"]) ?></td>
                        <td>
                            <?= e($adeudo["titulo_libro"]) ?>
                            <?php if ($adeudo["inventario"]): ?>
                                <small class="d-block text-secondary">Ejemplar <?= e($adeudo["inventario"]) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($adeudo["detalle"]) ?>
                            <?php if ($adeudo["fecha_texto"] !== ""): ?>
                                <small class="d-block text-secondary"><?= e($adeudo["fecha_texto"]) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($adeudo["pendiente"]): ?>
                                <span class="tag tag-danger"><svg class="icon"><use href="#i-triangle-alert" /></svg> <?= e($adeudo["estado"]) ?></span>
                            <?php else: ?>
                                <span class="tag tag-ok"><svg class="icon"><use href="#i-circle-check" /></svg> <?= e($adeudo["estado"]) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold">
                            <?= $adeudo["monto"] === null ? "—" : dinero($adeudo["monto"]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="debt-total d-flex justify-content-between align-items-center px-4 py-3">
        <span class="fs-5">Total de multas pendientes</span>
        <strong class="fs-3 <?= $resumen["total"] > 0 ? "text-danger" : "" ?>"><?= dinero($resumen["total"]) ?></strong>
    </div>

    <?php if ($resumen["reposiciones"] > 0): ?>
        <p class="d-flex align-items-center gap-2 border-top px-4 py-3 mb-0 text-secondary">
            <svg class="icon"><use href="#i-info" /></svg>
            Tienes <?= plural($resumen["reposiciones"], "reposición pendiente", "reposiciones pendientes") ?>.
            Las reposiciones no tienen monto: el bibliotecario te indica cómo reponer el libro.
        </p>
    <?php endif; ?>

    <p class="d-flex align-items-center gap-2 border-top px-4 py-3 mb-0 text-secondary">
        <svg class="icon"><use href="#i-info" /></svg> Los adeudos se liquidan de forma presencial en la biblioteca.
    </p>
</div>
<?php include "includes/fin_cuenta.php"; ?>
