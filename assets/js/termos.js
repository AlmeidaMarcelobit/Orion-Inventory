const uploadToggle = document.querySelector('[data-upload-toggle]');
const uploadPanel = document.getElementById('terms-upload');
if (uploadToggle && uploadPanel) {
    uploadToggle.addEventListener('click', () => {
        uploadPanel.hidden = !uploadPanel.hidden;
        uploadToggle.setAttribute('aria-expanded', String(!uploadPanel.hidden));
        if (!uploadPanel.hidden) uploadPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
}
const uploadForm = document.querySelector('[data-terms-upload]');
if (uploadForm) {
    const dropzone = uploadForm.querySelector('[data-terms-drop]');
    const input = dropzone.querySelector('input[type="file"]');
    const status = dropzone.querySelector('[data-file-name]');
    const validate = () => {
        const file = input.files[0];
        let error = '';
        if (file && !/\.(pdf|jpe?g|png)$/i.test(file.name)) error = 'Selecione um PDF, JPG ou PNG.';
        else if (file && (file.size === 0 || file.size > 10 * 1024 * 1024)) error = 'O arquivo deve ter até 10 MB e não pode estar vazio.';
        input.setCustomValidity(error);
        status.textContent = error || (file ? `${file.name} · ${(file.size / 1024 / 1024).toFixed(2)} MB` : 'Nenhum arquivo selecionado.');
    };
    input.addEventListener('change', validate);
    ['dragenter', 'dragover'].forEach((name) => dropzone.addEventListener(name, (event) => {
        event.preventDefault();
        dropzone.classList.add('is-dragging');
    }));
    dropzone.addEventListener('dragleave', (event) => {
        if (!dropzone.contains(event.relatedTarget)) dropzone.classList.remove('is-dragging');
    });
    dropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
        if (event.dataTransfer.files.length !== 1) { status.textContent = 'Envie um arquivo por vez.'; return; }
        input.files = event.dataTransfer.files;
        validate();
    });
    uploadForm.addEventListener('reset', () => {
        input.setCustomValidity('');
        status.textContent = 'Nenhum arquivo selecionado.';
        dropzone.classList.remove('is-dragging');
    });
}
