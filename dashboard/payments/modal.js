document.addEventListener('DOMContentLoaded', () => {
    const featureLinks = document.querySelectorAll('.feature-link');
    featureLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const modalId = link.getAttribute('data-modal');
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('is-active');
            }
        });
    });

    const modalCloses = document.querySelectorAll('.modal-close, .modal-background');
    modalCloses.forEach(close => {
        close.addEventListener('click', () => {
            close.closest('.modal').classList.remove('is-active');
        });
    });
});
