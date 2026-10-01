<?php
declare(strict_types=1);

/**
 * Draws the link preview images (Open Graph), one per language:
 * public/assets/og-en.png and public/assets/og-fr.png, 1200 by 630.
 *
 *   php bin/og-image.php
 *
 * Run it again after changing the greeting, the tagline, the first example or
 * the banner in src/i18n.php. The card is laid out in HTML with the site's own
 * fonts and photographed by Chrome, which is only needed here, not on the
 * server. Set CHROME to the browser's path if it is not in the usual place.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/i18n.php';

const WIDTH = 1200;
const HEIGHT = 630;
const LEAF_PATH = 'm4890 4430-45-863a95 95 0 0 1 111-98l859 151-116-320a65 65 0 0 1 20-73l941-762-212-99a65 65 0 0 1-34-79l186-572-542 115a65 65 0 0 1-73-38l-105-247-423 454a65 65 0 0 1-111-57l204-1052-327 189a65 65 0 0 1-91-27l-332-652-332 652a65 65 0 0 1-91 27l-327-189 204 1052a65 65 0 0 1-111 57l-423-454-105 247a65 65 0 0 1-73 38l-542-115 186 572a65 65 0 0 1-34 79l-212 99 941 762a65 65 0 0 1 20 73l-116 320 859-151a95 95 0 0 1 111 98l-45 863z';

function find_chrome(): string
{
    $candidates = [
        env('CHROME'),
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];
    foreach ($candidates as $path) {
        if ($path !== '' && is_executable($path)) {
            return $path;
        }
    }
    fwrite(STDERR, "Chrome was not found. Set CHROME to its path.\n");
    exit(1);
}

function card(string $lang): string
{
    $t = STRINGS[$lang];
    $fonts = 'file://' . realpath(ROOT . '/public/assets/fonts');
    $try = sprintf($t['try'], $t['examples'][0][0]);

    return '<!doctype html><html lang="' . $lang . '"><meta charset="utf-8"><style>
@font-face { font-family: "Castoro"; src: url("' . $fonts . '/castoro-400.woff2") format("woff2"); }
@font-face { font-family: "Radio Canada"; src: url("' . $fonts . '/radio-canada-var.woff2") format("woff2"); font-weight: 300 700; }
* { box-sizing: border-box; margin: 0; }
html, body { width: ' . WIDTH . 'px; height: ' . HEIGHT . 'px; overflow: hidden; }
body {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 40px 80px 0;
  background: linear-gradient(180deg, #faf8f8 0%, #f1ecec 100%);
  color: #170d10; text-align: center;
  font: 400 16px/1.45 "Radio Canada", sans-serif;
  -webkit-font-smoothing: antialiased;
}
.brand {
  position: absolute; top: 44px; left: 56px;
  display: flex; align-items: center; gap: 14px;
  font: 400 36px/1 "Castoro", serif; letter-spacing: -0.02em;
}
.brand svg { width: 52px; height: 26px; border-radius: 3px; box-shadow: 0 0 0 1px rgb(0 0 0 / 0.08); }
h1 { font: 400 124px/1 "Castoro", serif; letter-spacing: -0.054em; white-space: nowrap; }
.tagline { margin-top: 20px; font-size: 32px; line-height: 1.3; letter-spacing: -0.01em; color: rgb(23 13 16 / 0.65); text-wrap: balance; }
.ask {
  display: flex; align-items: center; gap: 16px;
  width: 820px; height: 104px; margin-top: 44px; padding: 0 24px 0 44px;
  background: #fff; border: 1px solid #9a9496; border-radius: 52px;
  box-shadow: 0 30px 60px -20px rgb(23 13 16 / 0.22), 0 4px 14px rgb(23 13 16 / 0.06);
  font-size: 30px; letter-spacing: -0.03em; color: #5e5659; text-align: left;
}
.ask span { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ask i { display: grid; place-items: center; flex: none; width: 60px; height: 60px; border-radius: 50%; background: #d80621; color: #fff; }
.ask i svg { width: 24px; height: 24px; }
.banner { position: absolute; left: 0; right: 0; bottom: 30px; font-size: 20px; letter-spacing: -0.01em; color: rgb(23 13 16 / 0.65); }
</style>
<p class="brand"><svg viewBox="0 0 9600 4800"><path fill="#d80621" d="M0 0h2400v4800H0zm7200 0h2400v4800H7200z"/><path fill="#fff" d="M2400 0h4800v4800H2400z"/><path fill="#d80621" d="' . LEAF_PATH . '"/></svg>Canada.gov</p>
<h1>' . e($t['hello']) . '</h1>
<p class="tagline">' . e($t['tagline']) . '</p>
<p class="ask"><span>' . e($try) . '</span><i><svg viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></i></p>
<p class="banner">' . e($t['banner']) . '</p>
</html>';
}

$chrome = find_chrome();
$work = sys_get_temp_dir() . '/og-image-' . getmypid();
mkdir($work);

foreach (array_keys(STRINGS) as $lang) {
    $html = "$work/$lang.html";
    $png = ROOT . "/public/assets/og-$lang.png";
    file_put_contents($html, card($lang));
    @unlink($png);

    $log = "$work/$lang.log";
    $chromeProcess = proc_open([
        $chrome, '--headless', '--disable-gpu', '--hide-scrollbars', '--force-device-scale-factor=1',
        '--no-first-run', '--disable-background-networking', '--disable-extensions',
        "--user-data-dir=$work/profile", '--window-size=' . WIDTH . ',' . HEIGHT,
        "--screenshot=$png", "file://$html",
    ], [['file', '/dev/null', 'r'], ['file', $log, 'w'], ['file', $log, 'a']], $pipes);

    // Chrome on macOS can stay open after saving the picture, so it is not
    // waited for: once it reports the file written, it is closed.
    $written = false;
    for ($waited = 0; $waited < 300 && !$written && proc_get_status($chromeProcess)['running']; $waited++) {
        usleep(100_000);
        $written = str_contains((string) file_get_contents($log), 'written to file');
    }
    proc_terminate($chromeProcess);
    proc_close($chromeProcess);

    $size = is_file($png) ? getimagesize($png) : false;
    if (!$size || $size[0] !== WIDTH || $size[1] !== HEIGHT) {
        fwrite(STDERR, "Could not draw og-$lang.png.\n" . file_get_contents($log));
        exit(1);
    }
    echo "public/assets/og-$lang.png\n";
}

exec('rm -rf ' . escapeshellarg($work));
