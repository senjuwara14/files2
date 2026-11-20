// Print just one panel (used by Print buttons)
function printOnly(id) {
    var el = document.getElementById(id);
    if (!el) { return; }
    el.classList.add('print-me');
    document.body.classList.add('print-one');
    window.print();
    setTimeout(function () {
        el.classList.remove('print-me');
        document.body.classList.remove('print-one');
    }, 500);
}
