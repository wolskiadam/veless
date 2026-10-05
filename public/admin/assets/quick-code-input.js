'use strict';
// Pole kodu szybkiego logowania: wystarczy wpisać 8 znaków, myślnik (ABCD-EFGH) wstawia się sam.
(() => {
    const input = document.getElementById('quick-code');
    if (!input) return;
    input.addEventListener('input', () => {
        const raw = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
        const formatted = raw.length > 4 ? raw.slice(0, 4) + '-' + raw.slice(4) : raw;
        if (formatted !== input.value) {
            input.value = formatted;
            input.setSelectionRange(formatted.length, formatted.length);
        }
    });
})();
