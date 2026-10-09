document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});

const equipmentType = document.querySelector('[data-equipment-type]');
const equipmentBrand = document.querySelector('[data-equipment-brand]');
if (equipmentType && equipmentBrand) {
    const brands = JSON.parse(equipmentBrand.dataset.brands);
    const defaultBrands = JSON.parse(equipmentBrand.dataset.defaultBrands);
    const model = equipmentBrand.closest('form').querySelector('[name="modelo"]');
    let savedModel = model.value;
    let wasSupport = equipmentType.value === 'suporte';
    const updateBrands = () => {
        const previous = equipmentBrand.value;
        const allowed = brands[equipmentType.value] || defaultBrands;
        equipmentBrand.replaceChildren(new Option('Selecione a marca', ''));
        allowed.forEach((brand) => equipmentBrand.add(new Option(brand, brand)));
        equipmentBrand.value = allowed.find((brand) => brand.toLowerCase() === previous.toLowerCase()) || '';
        const support = equipmentType.value === 'suporte';
        if (support) {
            if (!wasSupport) savedModel = model.value;
            equipmentBrand.value = 'Fussem';
            model.value = 'Alumínio';
        } else if (wasSupport) model.value = savedModel === 'Alumínio' ? '' : savedModel;
        model.readOnly = support;
        wasSupport = support;
    };
    equipmentType.addEventListener('change', updateBrands);
    updateBrands();
}
const equipmentStatus = document.querySelector('[data-equipment-status]');
const statusCollaborator = document.querySelector('[data-status-collaborator]');
if (equipmentStatus && statusCollaborator) {
    const updateCollaborator = () => {
        const allocated = !equipmentStatus.disabled && ['alocado', 'emprestado'].includes(equipmentStatus.value);
        statusCollaborator.hidden = !allocated;
        const select = statusCollaborator.querySelector('select');
        select.disabled = !allocated;
        const search = statusCollaborator.querySelector('[role="combobox"]');
        select.required = allocated && !search;
        if (search) {
            search.disabled = !allocated;
            search.required = allocated;
            statusCollaborator.querySelector('.equipment-hostname-dropdown').hidden = true;
            search.setAttribute('aria-expanded', 'false');
        }
    };
    equipmentStatus.addEventListener('change', updateCollaborator);
    updateCollaborator();
}

document.querySelectorAll('[data-collaborator-combobox], [data-equipment-combobox]').forEach((field, fieldIndex) => {
    const isEquipment = field.hasAttribute('data-equipment-combobox');
    const select = field.querySelector('select');
    const entries = [...select.options].filter((option) => option.value !== '');
    const control = document.createElement('span');
    control.className = 'equipment-hostname-control';
    const input = document.createElement('input');
    input.type = 'text';
    input.placeholder = isEquipment ? 'Digite o patrimônio, hostname ou modelo' : (field.hasAttribute('data-collaborator-filter') ? 'Todos · buscar colaborador' : 'Digite o nome do colaborador');
    input.autocomplete = 'off';
    input.required = select.required;
    input.disabled = select.disabled;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-label', isEquipment ? 'Equipamento / patrimônio' : 'Colaborador');
    input.setAttribute('aria-controls', `collaborator-options-${fieldIndex}`);
    input.value = select.value ? select.selectedOptions[0].textContent : '';
    control.append(input);
    const dropdown = document.createElement('span');
    dropdown.className = 'equipment-hostname-dropdown';
    dropdown.hidden = true;
    const list = document.createElement('span');
    list.id = `collaborator-options-${fieldIndex}`;
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', isEquipment ? 'Equipamentos disponíveis' : 'Colaboradores disponíveis');
    dropdown.append(list);
    const empty = document.createElement('span');
    empty.className = 'equipment-hostname-empty';
    empty.textContent = isEquipment ? 'Nenhum equipamento encontrado.' : 'Nenhum colaborador encontrado.';
    dropdown.append(empty);
    select.hidden = true;
    select.required = false;
    field.append(control, dropdown);
    let filtered = [];
    let active = -1;
    const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    const close = () => {
        dropdown.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };
    const choose = (entry) => {
        select.value = entry.value;
        input.value = entry.textContent;
        input.setCustomValidity('');
        select.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    };
    const setActive = (index) => {
        active = index;
        [...list.children].forEach((option, i) => option.setAttribute('aria-selected', String(i === active)));
        const option = list.children[active];
        if (option) {
            input.setAttribute('aria-activedescendant', option.id);
            option.scrollIntoView({ block: 'nearest' });
        }
    };
    const show = () => {
        filtered = entries.filter((entry) => normalize(entry.textContent).includes(normalize(input.value)));
        list.replaceChildren();
        filtered.forEach((entry, index) => {
            const option = document.createElement('span');
            option.className = 'equipment-hostname-option';
            option.id = `collaborator-${fieldIndex}-${index}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.textContent = entry.textContent;
            option.addEventListener('click', () => choose(entry));
            list.append(option);
        });
        empty.hidden = filtered.length > 0;
        dropdown.hidden = false;
        dropdown.scrollTop = 0;
        input.setAttribute('aria-expanded', 'true');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };
    input.addEventListener('focus', show);
    input.addEventListener('input', () => {
        const matches = entries.filter((entry) => normalize(entry.textContent) === normalize(input.value) || (isEquipment && input.value.trim() !== '' && normalize(entry.dataset.patrimonio || '') === normalize(input.value)));
        const exact = matches.length === 1 ? matches[0] : null;
        select.value = exact ? exact.value : '';
        input.setCustomValidity(input.value && !exact ? (isEquipment ? 'Selecione um equipamento da lista ou digite o patrimônio completo.' : 'Selecione um colaborador da lista de sugestões.') : '');
        show();
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (dropdown.hidden) show();
            if (filtered.length) setActive(event.key === 'ArrowDown' ? (active + 1) % filtered.length : (active <= 0 ? filtered.length - 1 : active - 1));
        } else if (event.key === 'Enter' && !dropdown.hidden && filtered[active]) {
            event.preventDefault();
            choose(filtered[active]);
        } else if (event.key === 'Escape' || event.key === 'Tab') close();
    });
    dropdown.addEventListener('mousedown', (event) => event.preventDefault());
    input.addEventListener('blur', close);
    if (equipmentStatus) equipmentStatus.addEventListener('change', close);
});
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
