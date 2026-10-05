<?php
declare(strict_types=1);

/**
 * Wspólny wygląd ekranów logowania (przypomnienie i ustawienie hasła).
 * Użycie: $cardTitle = '…'; require '_auth_card.php'; … treść …; authCardEnd();
 */

/** @var string $cardTitle */
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Pase\Support\I18n::locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= htmlspecialchars($cardTitle) ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <style>
        :root { --accent:#9c6b2e; --accent-soft:#f2e6d0; --ink:#22252b; --ink-2:#6d7076; --line:#e6e3da; --nav-bg:#1c1f24; }
        * { box-sizing:border-box; }
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:16px; background:var(--nav-bg); font-family:'Plus Jakarta Sans',system-ui,sans-serif; }
        .login-card { width:380px; max-width:100%; background:#fff; padding:40px; border-radius:14px; box-shadow:0 1px 2px rgba(0,0,0,.06), 0 24px 48px -20px rgba(0,0,0,.4); }
        .login-card h1 { margin:0 0 12px; color:var(--ink); font-size:22px; font-weight:800; letter-spacing:-.01em; line-height:1.25; }
        .login-card .lead { color:var(--ink-2); font-size:14px; line-height:1.5; margin:0 0 22px; }
        .error-msg { background:#fbe6e1; color:#a3341f; padding:10px; border-radius:8px; margin-bottom:20px; font-size:14px; }
        .ok-msg { background:#e6f4ea; color:#1e6b34; padding:12px; border-radius:8px; margin-bottom:20px; font-size:14px; line-height:1.5; }
        label { font-weight:600; font-size:.9em; color:var(--ink-2); display:block; margin-bottom:5px; }
        input { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; font-size:14px; font-family:inherit; margin-bottom:18px; }
        input:focus { border-color:var(--accent); outline:none; box-shadow:0 0 0 3px var(--accent-soft); }
        .btn-login { width:100%; padding:12px; background:var(--accent); color:#fff; border:0; border-radius:8px; font-size:15px; font-weight:700; font-family:inherit; cursor:pointer; transition:background .15s; }
        .btn-login:hover { background:#835a24; }
        .back { display:block; text-align:center; margin-top:18px; font-size:13px; color:var(--ink-2); }
        .back:hover { color:var(--ink); }
    </style>
</head>
<body>
    <div class="login-card">
<?php
function authCardEnd(): void
{
    echo "    </div>\n</body>\n</html>\n";
}
