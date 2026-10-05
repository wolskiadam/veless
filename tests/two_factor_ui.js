'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const nav = read('public/admin/nav.php');
const ordersPage = read('public/admin/index.php');
const titleActions = ordersPage.slice(ordersPage.indexOf('$PAGE_TITLE_ACTIONS ='), ordersPage.indexOf("require __DIR__ . '/header.php';"));
if (!titleActions.includes('ordersFilterToggle') || titleActions.includes('order_history.php')) {
    throw Error('Order heading must retain the filter toggle without a history button');
}
// Tylko blok menu - kolejny <script> w nav.php (dzwonek powiadomień) zawiera wstawki PHP.
const navStart = nav.indexOf('function toggleNavGroup');
new vm.Script(nav.slice(navStart, nav.indexOf('</script>', navStart)));
let rectangles = 0;
const canvas = {
    // Public RFC test vector, never a real enrollment secret.
    dataset: {uri: 'otpauth://totp/CRM:test?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=CRM&algorithm=SHA1&digits=6&period=30'},
    getContext: () => ({fillRect: () => { rectangles++; }})
};
const context = vm.createContext({document: {getElementById: () => canvas}});
vm.runInContext(read('public/admin/assets/qrcodegen.js'), context);
vm.runInContext(read('public/admin/assets/totp-setup.js'), context);
if (rectangles < 100 || !canvas.width || canvas.hidden) throw Error('QR rendering failed');
console.log('PASS: navigation syntax and local QR canvas rendering');
