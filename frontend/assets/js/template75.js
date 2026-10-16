(function () {
    'use strict';

    const header = document.querySelector('header');
    if (!header) {
        return;
    }

    const updateHeader = function () {
        header.classList.toggle('background-header', window.scrollY > 100);
    };

    updateHeader();
    window.addEventListener('scroll', updateHeader, { passive: true });
})();
