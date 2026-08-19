<?php
/** Inline monochrome icon set — stroke based, inherits currentColor. */
function icon(string $name, int $size = 20): string
{
    static $p = [
        'home'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-5h4v5"/>',
        'horse'     => '<path d="M6.8 20.6C4.5 18.7 3 15.9 3 12.6a9 9 0 0 1 18 0c0 3.3-1.5 6.1-3.8 8"/><path d="M4.6 20.6h4M15.4 20.6h4"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
        'receipt'   => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'users'     => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><path d="M16 5.2a3.2 3.2 0 0 1 0 6M17.5 14.8c2.1.7 3.5 2.5 3.5 5.2"/>',
        'calendar'  => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'card'      => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h4"/>',
        'trend'     => '<path d="M3 17.5 9.5 11l4 4L21 7"/><path d="M15.5 7H21v5.5"/>',
        'whistle'   => '<circle cx="8" cy="14" r="5"/><path d="M13 11h7a1.5 1.5 0 0 0 0-3h-8.5"/><path d="M8 9V6"/>',
        'trophy'    => '<path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M7 6H4v1.5A3.5 3.5 0 0 0 7.5 11M17 6h3v1.5a3.5 3.5 0 0 1-3.5 3.5"/><path d="M10 14h4l.5 3h-5z"/><path d="M7.5 20h9"/>',
        'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'qr'        => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14h1M14 20h1M18 18h3v3h-3z"/>',
        'user'      => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5c0-3.9 3.4-6.5 7.5-6.5s7.5 2.6 7.5 6.5"/>',
        'gear'      => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.8v2.6M12 18.6v2.6M21.2 12h-2.6M5.4 12H2.8M18.5 5.5l-1.8 1.8M7.3 16.7l-1.8 1.8M18.5 18.5l-1.8-1.8M7.3 7.3 5.5 5.5"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'lock'      => '<rect x="4.5" y="10" width="15" height="10.5" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><circle cx="12" cy="15" r="1.3" fill="currentColor" stroke="none"/>',
        'print'     => '<path d="M7 9V3h10v6"/><rect x="3.5" y="9" width="17" height="7" rx="2"/><path d="M7 14h10v7H7z"/>',
    ];
    $d = $p[$name] ?? $p['home'];
    return '<svg class="ic-svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true" focusable="false">' . $d . '</svg>';
}
