<?php
declare(strict_types=1);

namespace PaseExt\Kontrola;

use Pase\Plugin\Hooks;

/** Strona System → Kontrola stanu. */
final class Page
{
    private const STATE = [
        'ok'   => ['Działa', 'ok'],
        'warn' => ['Do sprawdzenia', 'warn'],
        'bad'  => ['Problem', 'bad'],
        'skip' => ['Nie dotyczy', 'muted'],
        'none' => ['Nie sprawdzono', 'muted'],
    ];

    public static function render(Runner $runner, array $ctx, array $cfg): string
    {
        $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $now = time();
        $results = $runner->results($now);
        $run = $runner->state();
        $count = ['ok' => 0, 'warn' => 0, 'bad' => 0];
        foreach ($results as $r) {
            if (isset($count[$r['state']])) {
                $count[$r['state']]++;
            }
        }
        $overall = $count['bad'] > 0 ? 'bad' : ($count['warn'] > 0 ? 'warn' : 'ok');
        $headline = match ($overall) {
            'bad' => Checks::plural($count['bad'], 'problem wymaga', 'problemy wymagają', 'problemów wymaga') . ' uwagi',
            'warn' => Checks::plural($count['warn'], 'rzecz do sprawdzenia', 'rzeczy do sprawdzenia', 'rzeczy do sprawdzenia'),
            default => 'Wszystko działa',
        };
        if ($run['finished'] === 0 && $run['pending'] === []) {
            $headline = 'Pierwsze sprawdzenie jeszcze się nie odbyło';
            $overall = 'none';
        }
        $every = $runner->every();
        $info = [];
        if ($run['pending'] !== []) {
            $info[] = 'Sprawdzanie w toku (zostało ' . count($run['pending']) . ')';
        }
        if ($run['finished'] > 0) {
            $info[] = 'Ostatnie pełne sprawdzenie ' . Checks::time($run['finished']) . ' (' . Checks::ago($now - $run['finished']) . ')'
                . ($run['by'] !== '' && $run['by'] !== 'cron' ? ', uruchomił ' . $e($run['by']) : '');
        }
        $info[] = $every > 0 ? 'w tle ' . ($every === 60 ? 'co godzinę' : 'co ' . Checks::minutes($every)) : 'w tle wyłączone, tylko ręcznie';
        $late = $every > 0 && $run['finished'] > 0 && $now - $run['finished'] > max(3 * $every * 60, 3 * 3600);

        $h = self::css();
        $h .= '<div class="ks-head ks-' . $overall . '"><div class="ks-head-main"><span class="ks-big" aria-hidden="true"></span><div>'
            . '<div class="ks-headline">' . $e($headline) . '</div>'
            . '<div class="ks-info">' . implode(' · ', $info) . '</div>'
            . '<div class="ks-counts"><span class="pill ok">' . $count['ok'] . ' działa</span> <span class="pill warn">' . $count['warn'] . ' do sprawdzenia</span> <span class="pill bad">' . Checks::plural($count['bad'], 'problem', 'problemy', 'problemów') . '</span></div>'
            . '</div></div>';
        if ($ctx['canEdit']) {
            $h .= '<form method="post" action="' . $e(Hooks::pageUrl('kontrola.sprawdz')) . '" class="ks-run"><input type="hidden" name="csrf" value="' . $e($ctx['csrf']) . '">'
                . '<button class="btn" type="submit" onclick="this.disabled=true;this.textContent=\'Sprawdzam…\';this.form.submit()">Sprawdź teraz</button></form>';
        }
        $h .= '</div>';
        if ($late) {
            $h .= '<div class="flash err">Sprawdzenia w tle nie ruszają od ' . Checks::ago($now - $run['finished'], false) . '. Najpewniej nie działa cron (cli/worker.php) albo praca wtyczek jest wstrzymana w System → Obciążenie serwera.</div>';
        }
        if (($cfg['alerts'] ?? '1') !== '0' && $runner->recipient() === '' && $ctx['isAdmin']) {
            $h .= '<div class="flash" style="background:var(--warn-bg);color:var(--warn-ink)">Mail o problemach nie ma dokąd iść: wpisz adres w Konfiguracja → Wtyczki → Kontrola stanu albo alarmy w Konfiguracja → E-mail.</div>';
        }

        foreach (Checks::GROUPS as $g => [$title, $desc]) {
            $rows = array_filter($results, static fn(array $r): bool => $r['group'] === $g);
            if ($rows === []) {
                continue;
            }
            $worst = 'ok';
            foreach ($rows as $r) {
                $worst = $r['state'] === 'bad' ? 'bad' : ($r['state'] === 'warn' && $worst !== 'bad' ? 'warn' : $worst);
            }
            $h .= '<section class="card ks-group"><div class="ks-group-head"><h2>' . $e($title) . '</h2><span class="ks-group-desc">' . $e($desc) . '</span></div>';
            foreach ($rows as $r) {
                $h .= self::row($r, $ctx, $now);
            }
            $h .= '</section>';
        }
        $h .= '<p class="ks-foot">Kontrola tylko czyta dane i pyta usługi o stan. Niczego nie zmienia sama - naprawy robi dopiero przycisk przy sprawdzeniu.'
            . ($ctx['isAdmin'] ? ' Ustawienia (co ile, adres do alarmów, progi): <a href="plugins.php">Konfiguracja → Wtyczki</a>.' : '') . '</p>';
        return $h;
    }

    private static function row(array $r, array $ctx, int $now): string
    {
        $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        [$label, $pill] = self::STATE[$r['state']] ?? self::STATE['none'];
        $meta = [];
        if (in_array($r['state'], ['warn', 'bad'], true) && ($since = Checks::ts($r['since'] ?? null)) && $now - $since > 300) {
            $meta[] = ($r['state'] === 'bad' ? 'problem' : 'żółte') . ' od ' . Checks::ago($now - $since, false);
        }
        if (empty($r['live']) && ($at = Checks::ts($r['checked_at'] ?? null))) {
            $meta[] = 'sprawdzono ' . Checks::ago($now - $at);
        }
        $h = '<div class="ks-row ks-' . $e($r['state']) . '" id="' . $e($r['id']) . '"><span class="ks-dot" aria-hidden="true"></span><div class="ks-main">'
            . '<div class="ks-title">' . $e((string) $r['title']) . ' <span class="pill ' . $pill . '">' . $label . '</span>'
            . ($meta !== [] ? '<span class="ks-meta">' . $e(implode(' · ', $meta)) . '</span>' : '') . '</div>'
            . '<div class="ks-sum">' . $e((string) ($r['summary'] ?? '')) . '</div>';
        $items = is_array($r['items'] ?? null) ? $r['items'] : [];
        if ($items !== []) {
            $count = (int) ($r['listed'] ?? count($items));
            $open = in_array($r['state'], ['warn', 'bad'], true) && count($items) <= 5 ? ' open' : '';
            $h .= '<details class="ks-items"' . $open . '><summary>' . (in_array($r['state'], ['warn', 'bad'], true) ? 'Pokaż, czego dotyczy' : 'Szczegóły') . ' (' . max($count, count($items)) . ')</summary><ul>';
            foreach ($items as $it) {
                $text = $e((string) ($it['text'] ?? ''));
                $st = (string) ($it['state'] ?? '');
                $h .= '<li' . ($st !== '' ? ' class="ks-i-' . $e($st) . '"' : (!empty($it['muted']) ? ' class="ks-i-muted"' : '')) . '>'
                    . (!empty($it['href']) ? '<a href="' . $e((string) $it['href']) . '">' . $text . '</a>' : '<span>' . $text . '</span>')
                    . (($it['note'] ?? '') !== '' ? ' <span class="ks-note">' . $e((string) $it['note']) . '</span>' : '') . '</li>';
            }
            if ($count > count($items)) {
                $h .= '<li class="ks-i-muted"><span>… i ' . ($count - count($items)) . ' więcej</span></li>';
            }
            $h .= '</ul></details>';
        }
        $h .= '</div><div class="ks-act">';
        if (!empty($r['fix']['id']) && $ctx['canEdit'] && in_array($r['state'], ['warn', 'bad'], true)) {
            $h .= '<form method="post" action="' . $e(Hooks::pageUrl('kontrola.napraw')) . '"><input type="hidden" name="csrf" value="' . $e($ctx['csrf']) . '">'
                . '<input type="hidden" name="fix" value="' . $e((string) $r['fix']['id']) . '"><input type="hidden" name="check" value="' . $e($r['id']) . '">'
                . '<button class="btn" type="submit">' . $e((string) ($r['fix']['label'] ?? 'Napraw')) . '</button></form>';
        }
        if (!empty($r['link']['href'])) {
            $h .= '<a class="btn secondary" href="' . $e((string) $r['link']['href']) . '">' . $e((string) ($r['link']['label'] ?? 'Otwórz')) . '</a>';
        }
        return $h . '</div></div>';
    }

    private static function css(): string
    {
        return '<style>
.ks-head { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; background:var(--surface); border:1px solid var(--line);
  border-radius:var(--radius); box-shadow:var(--shadow); padding:18px 20px; margin-bottom:18px; border-left:6px solid var(--ks-c); }
.ks-head-main { display:flex; gap:16px; align-items:flex-start; min-width:0; }
.ks-big { flex:none; width:22px; height:22px; border-radius:50%; background:var(--ks-c); box-shadow:0 0 0 6px var(--ks-soft); margin-top:4px; }
.ks-headline { font-size:20px; font-weight:800; letter-spacing:-.01em; }
.ks-info { color:var(--ink-2); font-size:13px; margin-top:4px; }
.ks-counts { margin-top:10px; display:flex; gap:6px; flex-wrap:wrap; }
.ks-ok { --ks-c:#2f8f5b; --ks-soft:var(--success-bg); }
.ks-warn { --ks-c:#d39321; --ks-soft:var(--warn-bg); }
.ks-bad { --ks-c:#c8412a; --ks-soft:var(--danger-bg); }
.ks-skip, .ks-none { --ks-c:#b9bbb6; --ks-soft:var(--muted-bg); }
.ks-group { padding:6px 20px 8px; }
.ks-group-head { display:flex; align-items:baseline; gap:12px; flex-wrap:wrap; padding:12px 0 8px; border-bottom:1px solid var(--line); }
.ks-group-head h2 { font-size:16px; margin:0; }
.ks-group-desc { color:var(--ink-2); font-size:13px; }
.ks-row { display:flex; gap:12px; align-items:flex-start; padding:12px 0; border-bottom:1px solid var(--line); }
.ks-row:last-child { border-bottom:0; }
.ks-dot { flex:none; width:12px; height:12px; border-radius:50%; background:var(--ks-c); margin-top:5px; box-shadow:0 0 0 4px var(--ks-soft); }
.ks-main { flex:1; min-width:0; }
.ks-title { font-weight:700; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.ks-title .pill { font-size:11px; padding:2px 8px; }
.ks-meta { color:var(--ink-3); font-size:12px; font-weight:500; }
.ks-sum { color:var(--ink-2); font-size:13.5px; margin-top:2px; overflow-wrap:anywhere; }
.ks-skip .ks-title, .ks-skip .ks-sum { color:var(--ink-3); }
.ks-items { margin-top:6px; font-size:13px; }
.ks-items summary { cursor:pointer; color:var(--accent); font-weight:600; }
.ks-items ul { margin:6px 0 0; padding:0 0 0 2px; list-style:none; max-height:340px; overflow:auto; }
.ks-items li { padding:4px 0 4px 14px; position:relative; overflow-wrap:anywhere; }
.ks-items li::before { content:""; position:absolute; left:0; top:11px; width:6px; height:6px; border-radius:50%; background:var(--ink-3); }
.ks-items li.ks-i-bad::before { background:#c8412a; } .ks-items li.ks-i-warn::before { background:#d39321; } .ks-items li.ks-i-ok::before { background:#2f8f5b; }
.ks-items li.ks-i-muted { color:var(--ink-3); }
.ks-note { color:var(--ink-2); }
.ks-act { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; flex:none; max-width:45%; }
.ks-act .btn { padding:7px 12px; font-size:13px; white-space:nowrap; }
.ks-act form { margin:0; }
.ks-foot { color:var(--ink-2); font-size:13px; }
@media (max-width:700px) {
  .ks-head { padding:14px; } .ks-headline { font-size:17px; } .ks-run, .ks-run .btn { width:100%; }
  .ks-group { padding:4px 14px 6px; }
  .ks-row { flex-wrap:wrap; } .ks-main { flex-basis:calc(100% - 24px); }
  .ks-act { max-width:none; width:100%; justify-content:flex-start; padding-left:24px; }
}
</style>';
    }
}
