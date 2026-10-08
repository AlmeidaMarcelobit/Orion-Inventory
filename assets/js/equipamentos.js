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

const hostnameCombobox = document.querySelector('[data-hostname-combobox]');
if (hostnameCombobox) {
    const input = hostnameCombobox.querySelector('input');
    const toggle = hostnameCombobox.querySelector('button');
    const dropdown = hostnameCombobox.querySelector('.equipment-hostname-dropdown');
    const empty = hostnameCombobox.querySelector('.equipment-hostname-empty');
    const options = [...hostnameCombobox.querySelectorAll('[role="option"]')];
    let visible = options;
    let active = -1;

    const setActive = (index) => {
        active = index;
        options.forEach((option) => option.setAttribute('aria-selected', 'false'));
        input.removeAttribute('aria-activedescendant');
        if (visible[active]) {
            visible[active].setAttribute('aria-selected', 'true');
            input.setAttribute('aria-activedescendant', visible[active].id);
            visible[active].scrollIntoView({ block: 'nearest' });
        }
    };
    const close = () => {
        dropdown.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-expanded', 'false');
        setActive(-1);
    };
    const open = (filter = '') => {
        visible = options.filter((option) => {
            const matches = option.dataset.value.toLowerCase().includes(filter.toLowerCase().trim());
            option.hidden = !matches;
            return matches;
        });
        empty.hidden = visible.length > 0;
        dropdown.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-expanded', 'true');
        setActive(-1);
        dropdown.scrollTop = 0;
    };
    const choose = (option) => {
        input.value = option.dataset.value;
        close();
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };
    input.addEventListener('focus', () => open());
    input.addEventListener('input', () => open(input.value));
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (dropdown.hidden) open(input.value);
            if (visible.length) setActive(event.key === 'ArrowDown' ? (active + 1) % visible.length : (active <= 0 ? visible.length - 1 : active - 1));
        } else if (event.key === 'Enter' && !dropdown.hidden && visible[active]) {
            event.preventDefault();
            choose(visible[active]);
        } else if (event.key === 'Escape' || event.key === 'Tab') close();
    });
    toggle.addEventListener('mousedown', (event) => event.preventDefault());
    toggle.addEventListener('click', () => {
        if (dropdown.hidden) { input.focus(); open(); } else close();
    });
    dropdown.addEventListener('mousedown', (event) => event.preventDefault());
    dropdown.addEventListener('click', (event) => {
        const option = event.target.closest('[role="option"]');
        if (option) choose(option);
    });
    hostnameCombobox.addEventListener('focusout', (event) => {
        if (!hostnameCombobox.contains(event.relatedTarget)) close();
    });
    document.addEventListener('click', (event) => {
        if (!hostnameCombobox.contains(event.target)) close();
    });
    if (equipmentType) equipmentType.addEventListener('change', close);
}
