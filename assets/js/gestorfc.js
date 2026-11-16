function confirmAction(event, message) {
    if (!window.confirm(message)) event.preventDefault();
}

document.addEventListener('submit', event => {
    const message = event.target.dataset.confirmSubmit;
    if (message) confirmAction(event, message);
});

document.addEventListener('click', event => {
    const button = event.target.closest('[data-confirm-click]');
    if (button) confirmAction(event, button.dataset.confirmClick);
});

document.addEventListener('change', event => {
    const field = event.target;
    if (field.hasAttribute('data-submit-on-change')) field.form.submit();
    if (field.hasAttribute('data-jogo-turma')) {
        window.location.href = 'jogos.php?novo=1&nova_turma=' + encodeURIComponent(field.value) + '#formularioJogo';
    }
});

document.querySelectorAll('[data-chart-height], [data-chart-width], [data-chart-bottom]').forEach(bar => {
    for (const property of ['height', 'width', 'bottom']) {
        const percentage = bar.getAttribute('data-chart-' + property);
        if (percentage !== null && Number.isFinite(Number(percentage))) {
            bar.style[property] = percentage + '%';
        }
    }
});
