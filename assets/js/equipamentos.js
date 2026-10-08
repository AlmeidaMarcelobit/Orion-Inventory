document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});

const equipmentType = document.querySelector('[data-equipment-type]');
const technicalFields = document.querySelector('[data-equipment-technical]');
if (equipmentType && technicalFields) {
    const updateTechnicalFields = () => {
        const isComputer = ['notebook', 'desktop'].includes(equipmentType.value);
        technicalFields.hidden = !isComputer;
        technicalFields.disabled = !isComputer;
    };
    equipmentType.addEventListener('change', updateTechnicalFields);
    updateTechnicalFields();
}
