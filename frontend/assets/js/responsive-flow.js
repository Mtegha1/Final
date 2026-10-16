(function () {
    'use strict';

    const path = window.location.pathname;
    const isAdmin = /\/admin\//i.test(path);
    const isAgent = /\/agent\//i.test(path);
    const isClient = /\/client\//i.test(path);
    const isAuth = /\/auth\.html$/i.test(path);
    const isPublic = !isAdmin && !isAgent && !isClient;
    const root = isAdmin || isAgent || isClient ? '../' : '';

    function safeText(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);
        return span.innerHTML;
    }

    function pageRole() {
        return isAdmin ? 'admin' : isAgent ? 'agent' : isClient ? 'client' : '';
    }

    function roleHome(role) {
        const prefix = isPublic ? '' : '../';
        if (role === 'admin') return prefix + 'admin/dashboard.html';
        if (role === 'agent') return prefix + 'agent/dashboard.html';
        if (role === 'client') return prefix + 'client/dashboard.html';
        return 'index.html';
    }

    function navItems(role) {
        if (role === 'admin') {
            return [
                ['Dashboard', 'dashboard.html'],
                ['Listings', 'listings.html'],
                ['Agents', 'agents.html'],
                ['Users', 'users.html'],
                ['Sign Out', '#', 'logout']
            ];
        }
        if (role === 'agent') {
            return [
                ['Dashboard', 'dashboard.html'],
                ['Verification', 'verify.html'],
                ['My Listings', 'listings.html'],
                ['Sign Out', '#', 'logout']
            ];
        }
        if (role === 'client') {
            return [
                ['Dashboard', 'dashboard.html'],
                ['Browse Properties', '../property.html'],
                ['Sign Out', '#', 'logout']
            ];
        }
        return [
            ['Browse Properties', 'property.html'],
            ['Sign In', 'auth.html?mode=login'],
            ['Join As Agent', 'auth.html?mode=register']
        ];
    }

    function installNavigation() {
        if (document.querySelector('.flow-topbar')) return;
        const role = pageRole();
        const nav = document.createElement('header');
        nav.className = 'flow-topbar';
        const items = navItems(role);
        nav.innerHTML = '<a class="flow-brand" href="' + roleHome(role || '') + '">IREA</a>' +
            '<button class="flow-menu-button" type="button" aria-expanded="false" aria-controls="flow-navigation">Menu</button>' +
            '<nav class="flow-nav" id="flow-navigation" aria-label="Main navigation">' +
            items.map(function (item) {
                const href = item[2] === 'logout' ? '#' : item[1];
                const active = !item[2] && window.location.pathname.endsWith('/' + item[1].split('?')[0]);
                return '<a href="' + href + '"' +
                    (active ? ' aria-current="page"' : '') +
                    (item[2] === 'logout' ? ' class="flow-logout" data-flow-logout="true"' : '') +
                    '>' + safeText(item[0]) + '</a>';
            }).join('') +
            '</nav>';
        document.body.insertBefore(nav, document.body.firstChild);

        const toggle = nav.querySelector('.flow-menu-button');
        toggle.addEventListener('click', function () {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            nav.querySelector('.flow-nav').classList.toggle('is-open', !expanded);
        });
        nav.querySelectorAll('.flow-nav a').forEach(function (link) {
            link.addEventListener('click', function () {
                toggle.setAttribute('aria-expanded', 'false');
                nav.querySelector('.flow-nav').classList.remove('is-open');
            });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                toggle.setAttribute('aria-expanded', 'false');
                nav.querySelector('.flow-nav').classList.remove('is-open');
            }
        });
        nav.querySelectorAll('[data-flow-logout]').forEach(function (link) {
            link.addEventListener('click', async function (event) {
                event.preventDefault();
                if (typeof Iconics !== 'undefined') await Iconics.call('auth/logout', 'POST');
                window.location.href = root + 'auth.html?mode=login';
            });
        });

        document.querySelectorAll('.sidebar').forEach(function (sidebar) {
            sidebar.setAttribute('aria-hidden', 'true');
        });
    }

    function labelTables() {
        document.querySelectorAll('table').forEach(function (table) {
            table.classList.add('flow-table-cards');
            const labels = Array.from(table.querySelectorAll('thead th')).map(function (th) {
                return th.textContent.trim();
            });
            table.querySelectorAll('tbody tr').forEach(function (row) {
                Array.from(row.cells).forEach(function (cell, index) {
                    if (!cell.hasAttribute('colspan') && labels[index]) {
                        cell.setAttribute('data-label', labels[index]);
                    }
                });
            });
        });
    }

    function setupAuthMode() {
        if (!isAuth) return;
        const params = new URLSearchParams(window.location.search);
        const registerMode = params.get('mode') === 'register';
        const login = document.getElementById('login-section');
        const register = document.getElementById('register-section');
        if (!login || !register) return;
        login.hidden = registerMode;
        register.hidden = !registerMode;
        login.style.display = registerMode ? 'none' : 'block';
        register.style.display = registerMode ? 'block' : 'none';
        const toggleLinks = document.querySelectorAll('.toggle-link');
        toggleLinks.forEach(function (link) {
            link.removeAttribute('onclick');
            link.addEventListener('click', function () {
                const nextMode = login.hidden ? 'login' : 'register';
                window.location.href = 'auth.html?mode=' + nextMode;
            });
        });
        document.querySelectorAll('.auth-divider').forEach(function (divider) {
            divider.hidden = true;
        });
    }

    function setupTableAccessibility() {
        const observer = new MutationObserver(labelTables);
        observer.observe(document.body, { childList: true, subtree: true });
        labelTables();
    }

    function setupVerificationWizard() {
        if (!/\/agent\/verify\.html$/i.test(path)) return;
        const form = document.querySelector('form');
        if (!form) return;
        const panels = Array.from(form.querySelectorAll('.form-card'));
        if (panels.length < 2) return;
        const statusPanel = document.createElement('section');
        statusPanel.className = 'flow-panel flow-verification-status';
        statusPanel.hidden = true;
        statusPanel.setAttribute('role', 'status');
        statusPanel.setAttribute('aria-live', 'polite');
        statusPanel.innerHTML = '<h2 data-flow-verification-title></h2><p data-flow-verification-copy></p>' +
            '<a class="flow-card-link" data-flow-verification-link></a>';
        form.parentNode.insertBefore(statusPanel, form);

        if (typeof Iconics !== 'undefined' && typeof Iconics.call === 'function') {
            Iconics.call('agent/dashboard', 'GET').then(function (response) {
                if (response.status !== 'success' || !response.data || !response.data.profile) return;
                const currentStatus = String(response.data.profile.verification_status || '').toLowerCase();
                if (!['verified', 'manual_review', 'pending_review'].includes(currentStatus)) return;

                const isVerified = currentStatus === 'verified';
                statusPanel.querySelector('[data-flow-verification-title]').textContent =
                    isVerified ? 'Identity verified' : 'Verification is being reviewed';
                statusPanel.querySelector('[data-flow-verification-copy]').textContent =
                    isVerified
                        ? 'Your identity check is complete. Continue to create a property listing.'
                        : 'Your ID and selfie are with an administrator for review. Incomplete or uncalibrated checks are reviewed manually; you do not need to upload them again while the review is pending.';
                const link = statusPanel.querySelector('[data-flow-verification-link]');
                link.textContent = isVerified ? 'Create a listing' : 'Return to dashboard';
                link.href = isVerified ? 'create-listing.html' : 'dashboard.html';
                statusPanel.hidden = false;
                form.hidden = true;
            }).catch(function (error) {
                console.error('Unable to load identity verification status:', error);
            });
        }

        const captureButton = form.querySelector('#capture-btn');
        if (captureButton) {
            captureButton.classList.remove('btn-success');
            captureButton.classList.add('btn-outline-success');
        }

        const progress = document.createElement('ol');
        progress.className = 'flow-step-indicator';
        progress.setAttribute('aria-label', 'Identity verification steps');
        progress.innerHTML = '<li>1. Upload ID</li><li>2. Take selfie</li><li>3. Result</li>';
        form.prepend(progress);
        const resultPanel = document.createElement('section');
        resultPanel.className = 'flow-panel';
        resultPanel.hidden = true;
        resultPanel.setAttribute('aria-live', 'polite');
        resultPanel.innerHTML = '<h2>Verification result</h2><p data-flow-result></p><a class="flow-card-link" data-flow-result-link href="dashboard.html">Continue to dashboard</a>';
        form.appendChild(resultPanel);
        const actions = document.createElement('div');
        actions.className = 'flow-wizard-actions';
        actions.innerHTML = '<button type="button" data-flow-back>Back</button><button type="button" class="flow-primary" data-flow-next>Continue</button>';
        form.appendChild(actions);
        const submit = form.querySelector('[type="submit"]');
        const status = form.querySelector('#status-message');
        let step = 0;
        function showStep(nextStep) {
            step = Math.max(0, Math.min(2, nextStep));
            panels.forEach(function (panel, index) { panel.hidden = step === 2 || index !== step; });
            resultPanel.hidden = step !== 2;
            if (status) status.hidden = step === 2;
            Array.from(progress.children).forEach(function (item, index) {
                item.setAttribute('aria-current', index === step ? 'step' : 'false');
            });
            actions.hidden = step === 2;
            actions.querySelector('[data-flow-back]').hidden = step === 0;
            actions.querySelector('[data-flow-next]').hidden = step !== 0;
            if (submit) submit.hidden = step !== 1;
        }
        actions.querySelector('[data-flow-back]').addEventListener('click', function () { showStep(0); });
        actions.querySelector('[data-flow-next]').addEventListener('click', function () {
            const invalid = Array.from(panels[0].querySelectorAll('input[required]')).find(function (input) {
                return !input.disabled && !input.checkValidity();
            });
            if (invalid) {
                invalid.reportValidity();
                return;
            }
            showStep(1);
            panels[1].scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        if (status) {
            const resultObserver = new MutationObserver(function () {
                const message = status.textContent.trim();
                if (!message || !/^(Result|Error):/i.test(message)) return;
                resultPanel.querySelector('[data-flow-result]').textContent = message;
                const link = resultPanel.querySelector('[data-flow-result-link]');
                if (/\brejected\b|\berror\b/i.test(message)) {
                    link.textContent = 'Retake verification';
                    link.href = '#';
                    link.onclick = function (event) {
                        event.preventDefault();
                        window.location.reload();
                    };
                } else {
                    link.textContent = 'Continue to dashboard';
                    link.href = 'dashboard.html';
                    link.onclick = null;
                }
                showStep(2);
            });
            resultObserver.observe(status, { childList: true, subtree: true, characterData: true });
        }
        showStep(0);
    }

    function setupListingWizard() {
        if (!/\/agent\/create-listing\.html$/i.test(path)) return;
        const form = document.getElementById('listing-form');
        if (!form) return;
        const cards = Array.from(form.querySelectorAll('.form-card'));
        if (cards.length < 3) return;
        const legacyProgress = document.querySelector('.steps-row');
        if (legacyProgress) legacyProgress.hidden = true;
        const photoCard = cards.find(function (card) { return /property images/i.test(card.textContent); });
        const locationCard = cards.find(function (card) { return /location/i.test(card.textContent); });
        const detailCard = cards.find(function (card) { return card !== photoCard && card !== locationCard; });
        const submitArea = form.querySelector('.submit-area');
        const ordered = [detailCard, photoCard, locationCard].filter(Boolean);
        if (ordered.length !== 3 || !submitArea) return;
        ordered.forEach(function (card) { form.insertBefore(card, submitArea); });

        const progress = document.createElement('ol');
        progress.className = 'flow-step-indicator';
        progress.setAttribute('aria-label', 'Listing steps');
        progress.innerHTML = '<li>1. Details</li><li>2. Photos</li><li>3. Location</li><li>4. Submit</li>';
        form.prepend(progress);
        const actions = document.createElement('div');
        actions.className = 'flow-wizard-actions';
        actions.innerHTML = '<button type="button" data-flow-back>Back</button><button type="button" class="flow-primary" data-flow-next>Continue</button>';
        form.insertBefore(actions, submitArea);
        const submitButton = form.querySelector('#submit-btn');
        const cancel = submitArea.querySelector('.btn-cancel');
        const status = form.querySelector('#status-message');
        const confirmation = document.createElement('section');
        confirmation.className = 'flow-confirmation';
        confirmation.hidden = true;
        confirmation.setAttribute('role', 'status');
        confirmation.setAttribute('aria-live', 'polite');
        confirmation.innerHTML = '<div class="flow-confirmation__mark" aria-hidden="true">✓</div>' +
            '<h2 data-flow-confirmation-title>Listing submitted</h2>' +
            '<p data-flow-confirmation-copy></p>' +
            '<a class="flow-card-link" href="listings.html">View my listings</a>';
        form.parentNode.insertBefore(confirmation, form.nextSibling);
        let step = 0;

        function showStep(next) {
            step = Math.max(0, Math.min(3, next));
            ordered.forEach(function (card, index) { card.hidden = index !== Math.min(step, 2); });
            submitArea.hidden = step !== 3;
            actions.hidden = step === 3;
            Array.from(progress.children).forEach(function (item, index) {
                item.setAttribute('aria-current', index === step ? 'step' : 'false');
            });
            actions.querySelector('[data-flow-back]').hidden = step === 0;
            actions.querySelector('[data-flow-next]').textContent = step === 2 ? 'Review and submit' : 'Continue';
            if (submitButton) submitButton.textContent = 'Submit listing';
            if (cancel) cancel.hidden = step !== 3;
        }
        actions.querySelector('[data-flow-back]').addEventListener('click', function () {
            showStep(step === 3 ? 2 : step - 1);
        });
        actions.querySelector('[data-flow-next]').addEventListener('click', function () {
            const invalid = Array.from(ordered[step].querySelectorAll('input[required], select[required], textarea[required]')).find(function (field) {
                return !field.disabled && !field.checkValidity();
            });
            if (invalid) {
                invalid.reportValidity();
                return;
            }
            showStep(step + 1);
            if (step === 3) submitArea.scrollIntoView({ behavior: 'smooth', block: 'center' });
            else ordered[step].scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        showStep(0);
        if (status) {
            const resultObserver = new MutationObserver(function () {
                if (!status.textContent.trim()) return;
                status.classList.add('flow-result-message');
                status.setAttribute('role', 'status');
                status.setAttribute('aria-live', 'polite');
                if (!status.classList.contains('success') || !confirmation.hidden) return;

                const autoApproved = /approved automatically/i.test(status.textContent);
                confirmation.querySelector('[data-flow-confirmation-title]').textContent =
                    autoApproved ? 'Listing approved' : 'Listing submitted for review';
                confirmation.querySelector('[data-flow-confirmation-copy]').textContent =
                    autoApproved
                        ? 'All required checks passed and the listing is now approved. You can review its status from your listings.'
                        : 'Your listing is saved and awaiting admin review. Price, image reuse, and location checks inform the review; a flag does not automatically reject your listing.';
                ordered.forEach(function (card) { card.hidden = true; });
                progress.hidden = true;
                actions.hidden = true;
                submitArea.hidden = true;
                confirmation.hidden = false;
                confirmation.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
            resultObserver.observe(status, {
                attributes: true,
                attributeFilter: ['class'],
                childList: true,
                subtree: true,
                characterData: true
            });
        }
    }

    async function guardRole() {
        if (typeof Iconics === 'undefined' || typeof Iconics.check !== 'function') return;
        if (isPublic || isAuth) {
            const auth = await Iconics.check();
            if (auth.status === 'success' && auth.data.user) {
                window.location.href = roleHome(auth.data.user.role);
            }
            return;
        }
        const auth = await Iconics.check();
        if (auth.status !== 'success' || !auth.data.user || auth.data.user.role !== pageRole()) {
            window.location.href = root + 'auth.html?mode=login';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        installNavigation();
        setupAuthMode();
        setupTableAccessibility();
        setupVerificationWizard();
        setupListingWizard();
        guardRole();
    });
}());
