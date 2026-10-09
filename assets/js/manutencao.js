const openMaintenance = document.querySelector('[data-maintenance-open]');
if (openMaintenance) {
    const mode = openMaintenance.querySelector('[name="modalidade"]');
    const vendor = openMaintenance.querySelector('[data-maintenance-vendor]');
    const update = () => {
        const external = mode.value === 'externa';
        vendor.hidden = !external;
        vendor.querySelector('input').disabled = !external;
        vendor.querySelector('input').required = external;
    };
    mode.addEventListener('change', update); update();
}
document.querySelectorAll('[data-maintenance-finish]').forEach((form) => {
    const destination = form.querySelector('[name="destino_conclusao"]');
    const field = form.querySelector('[data-maintenance-collaborator]');
    const update = () => {
        const allocated = destination.value === 'alocado';
        field.hidden = !allocated;
        const select = field.querySelector('select');
        const search = field.querySelector('[role="combobox"]');
        select.disabled = !allocated; select.required = allocated && !search;
        if (search) { search.disabled = !allocated; search.required = allocated; search.setAttribute('aria-expanded', 'false'); }
        const dropdown = field.querySelector('.equipment-hostname-dropdown'); if (dropdown) dropdown.hidden = true;
    };
    destination.addEventListener('change', update); update();
});
