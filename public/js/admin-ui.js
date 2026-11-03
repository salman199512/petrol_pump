(() => {
    const icons = {
        edit: '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/>',
        delete: '<path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6"/>',
        view: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        save: '<path d="m5 12 4 4L19 6"/>',
        receipt: '<path d="M5 4h14v16l-3-2-4 2-4-2-3 2V4Zm3 4h8m-8 4h8"/>',
    };
    const actionNames = {
        edit: 'edit',
        delete: 'delete',
        deactivate: 'delete',
        void: 'delete',
        'void open shift': 'delete',
        view: 'view',
        save: 'save',
        'add receipt': 'receipt',
    };

    function iconify(root = document) {
        root.querySelectorAll?.('table a, table button').forEach((control) => {
            if (control.dataset.iconified) return;
            const label = control.textContent.trim().toLowerCase();
            const icon = actionNames[label];
            if (!icon) return;
            control.dataset.iconified = 'true';
            control.dataset.iconAction = label;
            control.title = label.replace(/\b\w/g, (letter) => letter.toUpperCase());
            control.setAttribute('aria-label', control.title);
            control.classList.add('icon-action');
            control.innerHTML = `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[icon]}</svg>`;
            const reason = control.closest('form')?.querySelector('input[name="void_reason"],input[name="reason"]');
            if (reason) {
                reason.type = 'hidden';
                reason.required = false;
                reason.classList.add('datatable-action-reason');
            }
        });
    }

    function reasonDialog(form, button, field) {
        const backdrop = document.createElement('div');
        backdrop.className = 'action-dialog-backdrop';
        backdrop.innerHTML = `<section class="action-dialog" role="dialog" aria-modal="true" aria-labelledby="action-dialog-title"><h2 id="action-dialog-title">Confirm ${button.title.toLowerCase()}</h2><p>Enter a reason to continue.</p><label class="visually-hidden" for="action-dialog-reason">Reason</label><input id="action-dialog-reason" class="form-control" maxlength="500" autocomplete="off" placeholder="Reason" required><div class="dialog-actions"><button type="button" class="btn btn-light" data-cancel>Cancel</button><button type="button" class="btn btn-primary" data-confirm>Continue</button></div></section>`;
        document.body.append(backdrop);
        const input = backdrop.querySelector('input');
        input.focus();
        const close = () => backdrop.remove();
        backdrop.querySelector('[data-cancel]').addEventListener('click', close);
        backdrop.addEventListener('click', (event) => { if (event.target === backdrop) close(); });
        backdrop.querySelector('[data-confirm]').addEventListener('click', () => {
            const reason = input.value.trim();
            if (!reason) { input.focus(); return; }
            field.value = reason;
            field.required = false;
            close();
            form.requestSubmit(button);
        });
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('table button[data-iconified]');
        const form = button?.closest('form');
        const field = form?.querySelector('input[name="void_reason"],input[name="reason"]');
        if (!button || !form || !field || button.dataset.reasonDialog === 'done') return;
        event.preventDefault();
        reasonDialog(form, button, field);
    });

    iconify();
    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (node.nodeType === Node.ELEMENT_NODE) iconify(node);
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
