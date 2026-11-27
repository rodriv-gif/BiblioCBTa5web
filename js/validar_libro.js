// Permite agregar y quitar filas dinámicas de autores cuando un libro tiene coautores
const contenedorAutores = document.getElementById('contenedor-autores');
const btnAgregarAutor = document.getElementById('btnAgregarAutor');
const patronIsbn = /^(?:ISBN(?:-1[03])?:? )?(?=[0-9X]{10}$|(?=(?:[0-9]+[- ]){3})[- 0-9X]{13}$|97[89][0-9]{10}$|(?=(?:[0-9]+[- ]){4})[- 0-9]{17}$)(?:97[89][- ]?)?[0-9]{1,5}[- ]?[0-9]+[- ]?[0-9]+[- ]?[0-9X]$/;

if (btnAgregarAutor && contenedorAutores) {
    btnAgregarAutor.addEventListener('click', function () {
        const primerSelect = contenedorAutores.querySelector('.select-autor');
        if (!primerSelect) {
            return;
        }

        const nuevaFila = document.createElement('div');
        nuevaFila.className = 'fila-autor d-flex gap-2 mt-2';

        const nuevoSelect = document.createElement('select');
        nuevoSelect.name = 'autores[]';
        nuevoSelect.className = 'form-select select-autor';
        nuevoSelect.innerHTML = primerSelect.innerHTML;
        nuevoSelect.value = '0';

        const botonQuitar = document.createElement('button');
        botonQuitar.type = 'button';
        botonQuitar.className = 'btn btn-danger-soft btn-quitar-autor';
        botonQuitar.textContent = 'Quitar';

        nuevaFila.appendChild(nuevoSelect);
        nuevaFila.appendChild(botonQuitar);
        contenedorAutores.appendChild(nuevaFila);
    });

    contenedorAutores.addEventListener('click', function (e) {
        const boton = e.target.closest('.btn-quitar-autor');
        if (boton) {
            const fila = boton.closest('.fila-autor');
            if (fila) {
                fila.remove();
            }
        }
    });
}

// Valida los campos del formulario de libro antes de enviarlos al servidor PHP
const formLibro = document.getElementById('formLibro');

if (formLibro) {
    formLibro.addEventListener('submit', function (e) {
        let esValido = true;

        const titulo        = document.getElementById('titulo_libro');
        const isbn          = document.getElementById('isbn');
        const categoria     = document.getElementById('id_categoria');
        const editorial     = document.getElementById('id_editorial');
        const idioma        = document.getElementById('id_idioma');
        const paginas       = document.getElementById('paginas');
        const ejemplares    = document.getElementById('ejemplares');
        const selectsAutor  = document.querySelectorAll('.select-autor');
        const feedbackAutor = document.getElementById('feedback-autor');

        const campos = [titulo, isbn, categoria, editorial, idioma, paginas, ejemplares];
        campos.forEach(function (campo) {
            if (campo) {
                campo.classList.remove('is-invalid');
            }
        });
        selectsAutor.forEach(function (sel) {
            sel.classList.remove('is-invalid');
        });
        if (feedbackAutor) {
            feedbackAutor.textContent = '';
        }

        if (titulo.value.trim() === '') {
            marcarError(titulo, 'feedback-titulo', 'Por favor ingresa el título del libro.');
            esValido = false;
        } else if (titulo.value.trim().length < 3) {
            marcarError(titulo, 'feedback-titulo', 'El título debe tener al menos 3 caracteres.');
            esValido = false;
        }

        if (isbn.value.trim() === '') {
            marcarError(isbn, 'feedback-isbn', 'Por favor ingresa el código ISBN.');
            esValido = false;
        } else if (!patronIsbn.test(isbn.value.trim())) {
            marcarError(isbn, 'feedback-isbn', 'Ingresa un ISBN-10 o ISBN-13 válido (ej. 978-3-16-148410-0).');
            esValido = false;
        }

        const autoresElegidos = [];
        let faltaAutor = false;
        let autorRepetido = false;

        selectsAutor.forEach(function (sel) {
            if (sel.value === '0' || sel.value === '') {
                sel.classList.add('is-invalid');
                faltaAutor = true;
            } else if (autoresElegidos.indexOf(sel.value) !== -1) {
                sel.classList.add('is-invalid');
                autorRepetido = true;
            } else {
                autoresElegidos.push(sel.value);
            }
        });

        if (faltaAutor) {
            if (feedbackAutor) {
                feedbackAutor.textContent = 'Selecciona el autor en cada fila agregada o quita las filas vacías.';
            }
            esValido = false;
        } else if (autorRepetido) {
            if (feedbackAutor) {
                feedbackAutor.textContent = 'No selecciones el mismo autor dos veces en el mismo libro.';
            }
            esValido = false;
        }

        if (categoria.value === '0' || categoria.value === '') {
            marcarError(categoria, 'feedback-categoria', 'Selecciona una categoría.');
            esValido = false;
        }

        if (editorial.value === '0' || editorial.value === '') {
            marcarError(editorial, 'feedback-editorial', 'Selecciona una editorial.');
            esValido = false;
        }

        if (idioma.value === '0' || idioma.value === '') {
            marcarError(idioma, 'feedback-idioma', 'Selecciona un idioma.');
            esValido = false;
        }

        if (paginas && paginas.value.trim() !== '') {
            const valPaginas = parseInt(paginas.value, 10);
            if (isNaN(valPaginas) || valPaginas <= 0) {
                marcarError(paginas, 'feedback-paginas', 'El número de páginas debe ser mayor a 0.');
                esValido = false;
            }
        }

        if (ejemplares) {
            const valEjemplares = parseInt(ejemplares.value, 10);
            if (ejemplares.value.trim() === '' || isNaN(valEjemplares) || valEjemplares < 0) {
                marcarError(ejemplares, 'feedback-ejemplares', 'Ingresa una cantidad de ejemplares mayor o igual a 0.');
                esValido = false;
            }
        }

        if (!esValido) {
            e.preventDefault();
        }
    });
}

// Marca un campo en rojo con su mensaje descriptivo usando las clases de Bootstrap
function marcarError(input, idFeedback, mensaje) {
    input.classList.add('is-invalid');
    const contenedor = document.getElementById(idFeedback);
    if (contenedor) {
        contenedor.textContent = mensaje;
    }
}

// Actualiza el contador de caracteres de la sinopsis mientras escribe el bibliotecario
const campoSinopsis = document.getElementById('sinopsis');
const contadorSinopsis = document.getElementById('contador-sinopsis');
if (campoSinopsis && contadorSinopsis) {
    contadorSinopsis.textContent = campoSinopsis.value.length + ' / 1,000 caracteres';
    campoSinopsis.addEventListener('input', function () {
        contadorSinopsis.textContent = campoSinopsis.value.length + ' / 1,000 caracteres';
    });
}