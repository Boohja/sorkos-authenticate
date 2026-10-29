(function () {
    'use strict';

    var pendingForms = new WeakSet();

    function initializeAdminLogin(form) {
        var otp = form.querySelector('input[data-admin-login-otp]');
        var otpLabel = form.querySelector('label[data-admin-login-otp]');
        var button = form.querySelector('[data-admin-login-submit]');

        if (!otp || !otpLabel || !button) {
            return;
        }

        form.dataset.adminLoginStage = 'credentials';
        otp.type = 'hidden';
        otp.disabled = true;
        otp.hidden = true;
        otpLabel.hidden = true;
        button.textContent = button.dataset.continueLabel || 'Continue';
    }

    function showAdminLoginOtp(form) {
        var otp = form.querySelector('input[data-admin-login-otp]');
        var otpLabel = form.querySelector('label[data-admin-login-otp]');
        var button = form.querySelector('[data-admin-login-submit]');

        if (!otp || !otpLabel || !button) {
            return false;
        }

        Array.prototype.forEach.call(form.querySelectorAll('[data-admin-login-credentials]'), function (control) {
            control.hidden = true;
        });

        form.dataset.adminLoginStage = 'otp';
        otp.type = 'text';
        otp.disabled = false;
        otp.hidden = false;
        otpLabel.hidden = false;
        button.textContent = button.dataset.submitLabel || 'Sign In';

        window.setTimeout(function () {
            otp.focus();
        }, 0);

        return true;
    }

    function beginPending(form, submitter) {
        if (pendingForms.has(form)) {
            return false;
        }

        pendingForms.add(form);
        form.classList.add('is-submitting');
        form.setAttribute('aria-busy', 'true');

        var button = submitter || form.querySelector('button[type="submit"], input[type="submit"]');
        if (button) {
            button.classList.add('is-loading');
        }

        var status = form.querySelector('[data-form-pending-status]');
        if (status) {
            status.hidden = false;
        }

        Array.prototype.forEach.call(form.elements, function (control) {
            if (!control.disabled) {
                control.setAttribute('aria-disabled', 'true');
            }
        });

        window.requestAnimationFrame(function () {
            Array.prototype.forEach.call(form.elements, function (control) {
                if (control.disabled) {
                    return;
                }

                if (control.matches('button, select, input[type="checkbox"], input[type="radio"]')) {
                    control.dataset.pendingDisabled = 'true';
                    control.disabled = true;
                    return;
                }

                if ('readOnly' in control && !control.readOnly) {
                    control.dataset.pendingReadonly = 'true';
                    control.readOnly = true;
                }
            });
        });

        return true;
    }

    function restorePending(form) {
        pendingForms.delete(form);
        form.classList.remove('is-submitting');
        form.removeAttribute('aria-busy');

        Array.prototype.forEach.call(form.elements, function (control) {
            control.removeAttribute('aria-disabled');

            if (control.dataset.pendingDisabled === 'true') {
                control.disabled = false;
                delete control.dataset.pendingDisabled;
            }

            if (control.dataset.pendingReadonly === 'true') {
                control.readOnly = false;
                delete control.dataset.pendingReadonly;
            }

            control.classList.remove('is-loading');
        });

        var status = form.querySelector('[data-form-pending-status]');
        if (status) {
            status.hidden = true;
        }
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.matches('[data-admin-login-form]') && form.dataset.adminLoginStage === 'credentials') {
            event.preventDefault();
            showAdminLoginOtp(form);
            return;
        }

        if (!beginPending(form, event.submitter)) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-transition-to-standard]');

        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        var shell = document.querySelector('.split-shell');
        var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var singleColumn = window.matchMedia('(max-width: 900px)').matches;

        if (!shell || prefersReducedMotion || singleColumn) {
            return;
        }

        event.preventDefault();
        document.body.setAttribute('aria-busy', 'true');
        shell.classList.add('is-leaving');

        window.setTimeout(function () {
            window.location.assign(link.href);
        }, 220);
    });

    function syncConfigurationInput(select) {
        var form = select.closest('form');
        var input = form ? form.querySelector('[data-configuration-value]') : null;
        var option = select.options[select.selectedIndex];

        if (!input || !option) {
            return;
        }

        input.type = option.dataset.inputType || 'text';
        input.placeholder = option.dataset.placeholder || '';
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-admin-login-form]'), initializeAdminLogin);
    Array.prototype.forEach.call(document.querySelectorAll('[data-configuration-select]'), syncConfigurationInput);

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-configuration-select]')) {
            syncConfigurationInput(event.target);
        }
    });

    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) {
            return;
        }

        Array.prototype.forEach.call(document.querySelectorAll('form.is-submitting'), restorePending);
        document.body.removeAttribute('aria-busy');
    });
}());
