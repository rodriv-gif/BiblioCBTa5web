// Valida en el navegador el formulario de alta de autor antes de enviarlo a PHP
const formAutor = document.getElementById('formAutor');
if (formAutor) {
    formAutor.addEventListener('submit', function (e) {
        let esValido = true;
        const nombre  = document.getElementById('nombre');
        const paterno = document.getElementById('apellido_paterno');

        [nombre, paterno].forEach(function (campo) {
            campo.classList.remove('is-invalid');
        });

        if (nombre.value.trim() === '') {
            marcarError(nombre, 'feedback-nombre', 'Ingresa el nombre del autor.');
            esValido = false;
        }

        if (paterno.value.trim() === '') {
            marcarError(paterno, 'feedback-paterno', 'Ingresa el apellido paterno.');
            esValido = false;
        }

        if (!esValido) {
            e.preventDefault();
        }
    });
}

// Valida en el navegador el formulario de alta de editorial antes de enviarlo a PHP
const formEditorial = document.getElementById('formEditorial');
if (formEditorial) {
    formEditorial.addEventListener('submit', function (e) {
        const editorial = document.getElementById('nombre_editorial');
        editorial.classList.remove('is-invalid');

        if (editorial.value.trim() === '') {
            marcarError(editorial, 'feedback-editorial', 'Ingresa el nombre de la editorial.');
            e.preventDefault();
        }
    });
}

// Pinta el borde rojo de Bootstrap y muestra el mensaje de error debajo del campo
function marcarError(input, idFeedback, mensaje) {
    input.classList.add('is-invalid');
    document.getElementById(idFeedback).textContent = mensaje;
}