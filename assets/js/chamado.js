const ticketForm = document.querySelector('[data-ticket-form]');
if (ticketForm) {
    const service = ticketForm.querySelector('[name="servico"]');
    const equipment = ticketForm.querySelector('[name="equipamento"]');
    const setField = (selector, visible) => {
        const field = ticketForm.querySelector(selector);
        field.hidden = !visible;
        const control = field.querySelector('select');
        control.disabled = !visible;
        control.required = visible;
    };
    const updateFlow = () => {
        const category = ticketForm.querySelector('[name="categoria"]:checked')?.value;
        const needsEquipment = category === 'hardware' || service.value === 'equipamento';
        const selected = equipment.selectedOptions[0];
        const computer = ['notebook', 'desktop'].includes(selected?.dataset.type);
        setField('[data-ticket-system]', category === 'sistema');
        setField('[data-ticket-equipment]', needsEquipment);
        setField('[data-ticket-symptom]', needsEquipment && Boolean(equipment.value) && computer);
        setField('[data-ticket-referral]', needsEquipment && Boolean(equipment.value) && !computer);
    };
    ticketForm.querySelectorAll('[name="categoria"]').forEach((radio) => radio.addEventListener('change', updateFlow));
    service.addEventListener('change', updateFlow);
    equipment.addEventListener('change', updateFlow);
    updateFlow();
}
