(function(){
    const path = window.location.pathname.split('/').pop() || 'index.php';

    document.querySelectorAll('.nav-actions a').forEach(function(link){
        const href = link.getAttribute('href') || '';
        if (href.endsWith(path)) {
            link.classList.add('is-active');
        }
    });

    document.querySelectorAll('input[type="password"]').forEach(function(input){
        if (input.dataset.passwordToggleReady === 'true') {
            return;
        }

        const form = input.closest('form');
        const checkbox = form ? form.querySelector('.show-password-toggle') : null;

        if (checkbox) {
            input.dataset.passwordToggleReady = 'true';
            checkbox.addEventListener('change', function(){
                input.type = checkbox.checked ? 'text' : 'password';
            });
            return;
        }

        if (input.closest('.password-wrap')) {
            return;
        }

        input.dataset.passwordToggleReady = 'true';

        const toggleId = 'show-password-' + Math.random().toString(36).slice(2, 9);
        const toggleLabel = document.createElement('label');
        toggleLabel.className = 'password-check';
        toggleLabel.setAttribute('for', toggleId);

        const generatedCheckbox = document.createElement('input');
        generatedCheckbox.type = 'checkbox';
        generatedCheckbox.id = toggleId;

        const text = document.createElement('span');
        text.textContent = 'Show password';

        toggleLabel.appendChild(generatedCheckbox);
        toggleLabel.appendChild(text);

        input.insertAdjacentElement('afterend', toggleLabel);

        generatedCheckbox.addEventListener('change', function(){
            input.type = generatedCheckbox.checked ? 'text' : 'password';
        });
    });

    function passwordChecks(value) {
        return {
            length: value.length >= 8,
            uppercase: /[A-Z]/.test(value),
            lowercase: /[a-z]/.test(value),
            number: /[0-9]/.test(value),
            special: /[^A-Za-z0-9]/.test(value)
        };
    }

    function passwordErrorMessage(value) {
        const checks = passwordChecks(value);
        const missing = [];

        if (!checks.length) {
            missing.push('at least 8 characters');
        }
        if (!checks.uppercase) {
            missing.push('one capital letter');
        }
        if (!checks.lowercase) {
            missing.push('one small letter');
        }
        if (!checks.number) {
            missing.push('one number');
        }
        if (!checks.special) {
            missing.push('one special character');
        }

        return missing.length ? 'Password must include ' + missing.join(', ') + '.' : '';
    }

    document.querySelectorAll('[data-strong-password="true"]').forEach(function(input){
        const form = input.closest('form');
        const rules = form ? form.querySelector('[data-password-rules]') : null;

        function updateRules() {
            const checks = passwordChecks(input.value);
            const message = passwordErrorMessage(input.value);

            input.setCustomValidity(message);

            if (!rules) {
                return;
            }

            Object.keys(checks).forEach(function(rule){
                const item = rules.querySelector('[data-rule="' + rule + '"]');
                if (item) {
                    item.classList.toggle('is-valid', checks[rule]);
                }
            });
        }

        input.addEventListener('input', updateRules);
        updateRules();
    });

    document.querySelectorAll('form').forEach(function(form){
        form.addEventListener('submit', function(event){
            form.querySelectorAll('[data-strong-password="true"]').forEach(function(input){
                input.setCustomValidity(passwordErrorMessage(input.value));
            });

            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
                return;
            }

            const button = form.querySelector('button[type="submit"]');
            if (!button || button.dataset.keepText === 'true') {
                return;
            }
            button.dataset.originalText = button.textContent.trim();
            button.textContent = 'Please wait...';
            button.classList.add('is-loading');
        });
    });

    document.querySelectorAll('.notice').forEach(function(notice){
        notice.setAttribute('role', 'status');
    });
})();
