(function () {
    'use strict';

    const removeFlash = (flash) => {
        if (!flash || flash.dataset.uiFlashState === 'leaving') {
            return;
        }

        flash.dataset.uiFlashState = 'leaving';
        window.setTimeout(() => flash.remove(), 220);
    };

    const bindFlashes = (root = document) => {
        root.querySelectorAll('[data-ui-flash]').forEach((flash) => {
            if (flash.dataset.uiFlashBound === 'true') {
                return;
            }

            flash.dataset.uiFlashBound = 'true';
            const timeout = Number(flash.dataset.uiFlashTimeout || 5200);
            const closeButton = flash.querySelector('[data-ui-flash-close]');

            closeButton?.addEventListener('click', () => removeFlash(flash));

            if (timeout > 0) {
                window.setTimeout(() => removeFlash(flash), timeout);
            }
        });
    };

    const iconFor = (type) => ({
        success: 'bi-check-circle',
        error: 'bi-exclamation-octagon',
        warning: 'bi-exclamation-triangle',
        info: 'bi-info-circle',
    }[type] || 'bi-info-circle');

    const showToast = (payload = {}) => {
        const message = payload.message;
        const type = payload.type || 'info';
        const timeout = Number(payload.duration ?? payload.timeout ?? 5200);

        if (!message) {
            return;
        }

        let stack = document.querySelector('[data-ui-flash-stack]');

        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'ui-flash-stack';
            stack.dataset.uiFlashStack = 'true';
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }

        const flash = document.createElement('div');
        flash.className = 'ui-flash ui-flash--' + type;
        flash.dataset.uiFlash = 'true';
        flash.dataset.uiFlashTimeout = String(timeout > 0 ? timeout : 0);
        flash.setAttribute('role', type === 'error' ? 'alert' : 'status');
        flash.style.setProperty('--ui-flash-timeout', String(timeout) + 'ms');

        const icon = document.createElement('i');
        icon.className = 'ui-flash__icon bi ' + iconFor(type);
        icon.setAttribute('aria-hidden', 'true');

        const text = document.createElement('span');
        text.textContent = String(message);

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'ui-flash__close';
        closeButton.dataset.uiFlashClose = 'true';
        closeButton.setAttribute('aria-label', 'Dismiss notification');

        const closeIcon = document.createElement('i');
        closeIcon.className = 'bi bi-x-lg';
        closeIcon.setAttribute('aria-hidden', 'true');
        closeButton.appendChild(closeIcon);

        const progress = document.createElement('span');
        progress.className = 'ui-flash__progress';
        progress.setAttribute('aria-hidden', 'true');

        flash.append(icon, text, closeButton, progress);

        stack.appendChild(flash);
        bindFlashes(stack);
    };

    window.uiToast = showToast;

    document.addEventListener('DOMContentLoaded', () => bindFlashes());
    document.addEventListener('livewire:navigated', () => bindFlashes());

    document.addEventListener('input', (event) => {
        const form = event.target.closest?.('[data-ui-form]');

        if (form) {
            form.dataset.uiState = 'dirty';
        }
    });

    document.addEventListener('change', (event) => {
        const form = event.target.closest?.('[data-ui-form]');

        if (form) {
            form.dataset.uiState = 'dirty';
        }
    });

    document.addEventListener('livewire-upload-error', () => {
        showToast({
            type: 'error',
            message: 'The file could not be uploaded. Check that it is a PDF, JPG, or PNG under 10 MB and try again.',
            duration: 8000,
        });
    });

    document.addEventListener('submit', (event) => {
        const form = event.target.closest?.('[data-ui-form]');

        if (form) {
            form.dataset.uiState = 'submitting';
        }
    });
    document.addEventListener('ui:toast', (event) => showToast(event.detail || {}));

    document.addEventListener('livewire:init', () => {
        if (!window.Livewire?.on) {
            return;
        }

        window.Livewire.on('ui-toast', (payload) => {
            showToast(Array.isArray(payload) ? payload[0] : payload);
        });
    });
})();
