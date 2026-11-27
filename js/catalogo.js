// Actualiza los libros del catálogo en tiempo real conforme el usuario escribe o cambia un filtro
const formCatalogo = document.getElementById('formCatalogo');
const buscadorCatalogo = document.getElementById('buscadorCatalogo');
const selectCategoria = document.getElementById('categoria');
const inputDisp = document.getElementById('inputDisp');
const contenedorResultados = document.getElementById('resultadosCatalogo');
let temporizadorBusqueda = null;

function cargarResultadosCatalogo(pagina) {
    if (!contenedorResultados) {
        return;
    }

    const texto = buscadorCatalogo ? buscadorCatalogo.value.trim() : '';
    const categoria = selectCategoria ? selectCategoria.value : '0';
    const disp = inputDisp ? inputDisp.value : '0';
    const numPagina = pagina || 1;

    const parametros = new URLSearchParams({
        q: texto,
        categoria: categoria,
        disp: disp,
        p: numPagina
    });

    // Actualiza la URL del navegador sin recargar la página para conservar el filtro al volver
    window.history.replaceState(null, '', 'catalogo.php?' + parametros.toString());

    parametros.set('ajax', '1');
    fetch('catalogo.php?' + parametros.toString())
        .then(function (respuesta) {
            return respuesta.text();
        })
        .then(function (html) {
            contenedorResultados.innerHTML = html;
        });
}

if (formCatalogo) {
    formCatalogo.addEventListener('submit', function (e) {
        e.preventDefault();
        cargarResultadosCatalogo(1);
    });
}

if (buscadorCatalogo) {
    buscadorCatalogo.addEventListener('input', function () {
        clearTimeout(temporizadorBusqueda);
        temporizadorBusqueda = setTimeout(function () {
            cargarResultadosCatalogo(1);
        }, 250);
    });
}

if (selectCategoria) {
    selectCategoria.addEventListener('change', function () {
        cargarResultadosCatalogo(1);
    });
}

// Permite cambiar entre Todos y Solo disponibles sin recargar toda la pantalla
document.querySelectorAll('.filter-pill').forEach(function (pastilla) {
    pastilla.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelectorAll('.filter-pill').forEach(function (item) {
            item.classList.remove('active');
        });
        pastilla.classList.add('active');
        if (inputDisp) {
            inputDisp.value = pastilla.getAttribute('data-disp');
        }
        cargarResultadosCatalogo(1);
    });
});

// Delega los clics de paginación dentro del contenedor dinámico
if (contenedorResultados) {
    contenedorResultados.addEventListener('click', function (e) {
        const enlacePagina = e.target.closest('.page-link');
        if (!enlacePagina) {
            return;
        }
        const itemPadre = enlacePagina.closest('.page-item');
        if (itemPadre && itemPadre.classList.contains('disabled')) {
            e.preventDefault();
            return;
        }
        const paginaDestino = parseInt(enlacePagina.getAttribute('data-page'), 10);
        if (!isNaN(paginaDestino) && paginaDestino > 0) {
            e.preventDefault();
            cargarResultadosCatalogo(paginaDestino);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
}