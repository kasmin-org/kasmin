<?php

/**
 * Kasmin setup – one-file bootstrap installer.
 *
 * Upload this file to your web space and open it in the browser, or run it on the command line.
 * It lists the current releases of Kasmin live from GitHub, downloads the chosen package, verifies its SHA-256
 * checksum, unpacks it, writes the configuration (.env) and then hands over to the Kasmin web installer.
 *
 * CLI (also used by CI):
 *   php kasmin-setup.php --target=/path/to/kasmin [--release=v0.1.0 | --branch=develop | --source=file-or-url.zip]
 *       --db-host=127.0.0.1 --db-port=3306 --db-name=kasmin --db-user=kasmin --db-password=secret
 *       [--db-driver=mariadb|mysql] [--app-url=https://example.org]
 *
 * Licence: AGPL-3.0 – https://github.com/kasmin-org/kasmin
 */

declare(strict_types=1);

const KASMIN_REPO = 'kasmin-org/kasmin';
const KASMIN_MIN_PHP = '8.3.0';
const KASMIN_MAX_RELEASES = 10;
const KASMIN_CACHE_SECONDS = 600;

// ---------------------------------------------------------------------------------------------------------------
// Shared functions
// ---------------------------------------------------------------------------------------------------------------

function ks_http_get(string $url): string
{
    $headers = ['User-Agent: kasmin-setup', 'Accept: application/vnd.github+json'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (! is_string($body) || $status >= 400) {
            throw new RuntimeException("Download failed ({$status}): {$url}");
        }

        return $body;
    }
    $context = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 300], 'ssl' => ['verify_peer' => true]]);
    $body = @file_get_contents($url, false, $context);
    if (! is_string($body)) {
        throw new RuntimeException("Download failed: {$url}");
    }

    return $body;
}

/**
 * @return list<array<string, mixed>>
 */
function ks_github_releases(): array
{
    $cache = sys_get_temp_dir().'/kasmin-setup-releases.json';
    if (is_file($cache) && filemtime($cache) > time() - KASMIN_CACHE_SECONDS) {
        $data = json_decode((string) file_get_contents($cache), true);
    } else {
        $data = json_decode(ks_http_get('https://api.github.com/repos/'.KASMIN_REPO.'/releases?per_page=50'), true);
        if (is_array($data)) {
            @file_put_contents($cache, json_encode($data));
        }
    }
    if (! is_array($data)) {
        throw new RuntimeException('GitHub did not return a release list. Please try again later.');
    }

    return array_values(array_filter($data, 'is_array'));
}

/**
 * Official releases (newest first, at most 10) or development builds (one per branch).
 *
 * @return list<array{tag: string, name: string, date: string, url: string, zip: ?string, sha: ?string}>
 */
function ks_release_list(bool $development): array
{
    $list = [];
    foreach (ks_github_releases() as $release) {
        $tag = (string) ($release['tag_name'] ?? '');
        $isDev = str_starts_with($tag, 'dev-');
        if (($release['draft'] ?? false) || $isDev !== $development || (! $development && ($release['prerelease'] ?? false))) {
            continue;
        }
        $zip = $sha = null;
        foreach ((array) ($release['assets'] ?? []) as $asset) {
            $name = (string) ($asset['name'] ?? '');
            if (str_ends_with($name, '.zip')) {
                $zip = (string) $asset['browser_download_url'];
            } elseif (str_ends_with($name, '.zip.sha256')) {
                $sha = (string) $asset['browser_download_url'];
            }
        }
        $list[] = [
            'tag' => $tag,
            'name' => $development ? substr($tag, 4) : $tag,
            'date' => substr((string) ($release['published_at'] ?? ''), 0, 10),
            'url' => (string) ($release['html_url'] ?? ''),
            'zip' => $zip,
            'sha' => $sha,
        ];
    }

    return $development ? $list : array_slice($list, 0, KASMIN_MAX_RELEASES);
}

/** @return list<array{label: string, ok: bool}> */
function ks_requirements(string $target): array
{
    $checks = [['label' => 'PHP >= '.KASMIN_MIN_PHP.' ('.PHP_VERSION.')', 'ok' => version_compare(PHP_VERSION, KASMIN_MIN_PHP, '>=')]];
    foreach (['pdo_mysql', 'intl', 'mbstring', 'openssl', 'zip', 'gd', 'bcmath', 'fileinfo', 'json'] as $ext) {
        $checks[] = ['label' => 'PHP extension '.$ext, 'ok' => extension_loaded($ext)];
    }
    $checks[] = ['label' => 'HTTPS downloads (curl or allow_url_fopen)', 'ok' => function_exists('curl_init') || (bool) ini_get('allow_url_fopen')];
    $parent = is_dir($target) ? $target : dirname($target);
    $checks[] = ['label' => 'writable: '.$parent, 'ok' => is_writable($parent)];

    return $checks;
}

function ks_download_and_verify(string $zipUrlOrFile, ?string $shaUrl, string $tmpDir): string
{
    $file = $tmpDir.'/kasmin.zip';
    if (is_file($zipUrlOrFile)) {
        copy($zipUrlOrFile, $file);
    } else {
        file_put_contents($file, ks_http_get($zipUrlOrFile));
    }
    if ($shaUrl !== null) {
        $expected = strtolower(strtok(trim(is_file($shaUrl) ? (string) file_get_contents($shaUrl) : ks_http_get($shaUrl)), " \t") ?: '');
        $actual = hash_file('sha256', $file);
        if (! hash_equals($expected, (string) $actual)) {
            throw new RuntimeException('Checksum mismatch – the download is damaged or was manipulated. Nothing was installed.');
        }
    }

    return $file;
}

function ks_extract(string $zipFile, string $target): void
{
    if (is_dir($target) && count(array_diff((array) scandir($target), ['.', '..'])) > 0) {
        throw new RuntimeException("The target directory is not empty: {$target}");
    }
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('The package cannot be opened.');
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_contains($name, '..') || str_starts_with($name, '/')) {
            throw new RuntimeException('The package contains an unsafe path.');
        }
    }
    @mkdir($target, 0755, true);
    $zip->extractTo($target);
    $zip->close();
}

/** @param array<string, string> $db */
function ks_test_database(array $db): void
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['name']);
    new PDO($dsn, $db['user'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
}

/** @param array<string, string> $db */
function ks_write_env(string $target, array $db, string $appUrl): void
{
    $example = $target.'/.env.example';
    $env = is_file($example) ? (string) file_get_contents($example) : '';
    $values = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_URL' => $appUrl,
        'DB_CONNECTION' => $db['driver'],
        'DB_HOST' => $db['host'],
        'DB_PORT' => $db['port'],
        'DB_DATABASE' => $db['name'],
        'DB_USERNAME' => $db['user'],
        'DB_PASSWORD' => $db['password'],
    ];
    foreach ($values as $key => $value) {
        $line = $key.'='.(preg_match('/[\s#"\'\\\\$]/', $value) ? '"'.addcslashes($value, '"\\$').'"' : $value);
        $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';
        $env = preg_match($pattern, $env) ? (string) preg_replace($pattern, $line, $env, 1) : $env."\n".$line;
    }
    file_put_contents($target.'/.env', $env);
    @chmod($target.'/.env', 0600);
}

/** @param array<string, string> $db */
function ks_install(string $source, ?string $sha, string $target, array $db, string $appUrl): void
{
    $tmp = sys_get_temp_dir().'/kasmin-setup-'.bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    try {
        ks_test_database($db);
        $zip = ks_download_and_verify($source, $sha, $tmp);
        ks_extract($zip, $target);
        ks_write_env($target, $db, $appUrl);
    } finally {
        @unlink($tmp.'/kasmin.zip');
        @rmdir($tmp);
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Command line
// ---------------------------------------------------------------------------------------------------------------

if (PHP_SAPI === 'cli') {
    $o = getopt('', ['target:', 'release:', 'branch:', 'source:', 'sha256:', 'db-driver:', 'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-password:', 'app-url:', 'list', 'list-branches']);
    try {
        if (isset($o['list']) || isset($o['list-branches'])) {
            foreach (ks_release_list(isset($o['list-branches'])) as $r) {
                echo "{$r['name']}\t{$r['date']}\t{$r['url']}\n";
            }
            exit(0);
        }
        $target = (string) ($o['target'] ?? '');
        if ($target === '') {
            throw new RuntimeException('--target is required.');
        }
        foreach (ks_requirements($target) as $check) {
            echo ($check['ok'] ? '[ok] ' : '[missing] ').$check['label']."\n";
            if (! $check['ok']) {
                exit(1);
            }
        }
        $sha = isset($o['sha256']) ? (string) $o['sha256'] : null;
        if (isset($o['source'])) {
            $source = (string) $o['source'];
            $sha ??= is_file($source.'.sha256') ? $source.'.sha256' : null;
        } else {
            $wanted = isset($o['branch']) ? 'dev-'.preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $o['branch']) : (string) ($o['release'] ?? '');
            $list = ks_release_list(isset($o['branch']));
            $release = $wanted === '' ? ($list[0] ?? null) : (array_values(array_filter($list, fn ($r) => $r['tag'] === $wanted))[0] ?? null);
            if ($release === null || $release['zip'] === null) {
                throw new RuntimeException('Release not found: '.($wanted ?: 'latest'));
            }
            [$source, $sha] = [$release['zip'], $release['sha']];
        }
        $db = [
            'driver' => (string) ($o['db-driver'] ?? 'mariadb'), 'host' => (string) ($o['db-host'] ?? '127.0.0.1'),
            'port' => (string) ($o['db-port'] ?? '3306'), 'name' => (string) ($o['db-name'] ?? 'kasmin'),
            'user' => (string) ($o['db-user'] ?? 'kasmin'), 'password' => (string) ($o['db-password'] ?? ''),
        ];
        ks_install($source, $sha, $target, $db, (string) ($o['app-url'] ?? 'http://localhost'));
        echo "Kasmin unpacked to {$target}. Point the web server to {$target}/public and open /install.\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: '.$e->getMessage()."\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------------------------------------------------
// Web
// ---------------------------------------------------------------------------------------------------------------

session_start();
$lang = ($_GET['lang'] ?? $_SESSION['lang'] ?? 'de') === 'en' ? 'en' : 'de';
$_SESSION['lang'] = $lang;
$t = static fn (string $de, string $en): string => htmlspecialchars($lang === 'en' ? $en : $de, ENT_QUOTES);
$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

// Access code: only someone with access to the web space (FTP) can read it.
$codeFile = __DIR__.'/kasmin-setup-code.txt';
if (! is_file($codeFile)) {
    file_put_contents($codeFile, strtoupper(bin2hex(random_bytes(4)))."\n");
}
$code = trim((string) file_get_contents($codeFile));
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

$error = $message = null;
$target = dirname(__DIR__).'/kasmin';
$development = ! empty($_REQUEST['development']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (! hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Session expired, please reload the page.');
        }
        if (! hash_equals($code, strtoupper(trim((string) ($_POST['code'] ?? ''))))) {
            throw new RuntimeException($lang === 'en' ? 'Wrong access code.' : 'Falscher Zugangscode.');
        }
        $target = rtrim((string) ($_POST['target'] ?? $target), '/');
        $tag = (string) ($_POST['release'] ?? '');
        $release = array_values(array_filter(ks_release_list($development), fn ($r) => $r['tag'] === $tag))[0] ?? null;
        if ($release === null || $release['zip'] === null) {
            throw new RuntimeException('Release not found.');
        }
        $db = [
            'driver' => ($_POST['db_driver'] ?? '') === 'mysql' ? 'mysql' : 'mariadb', 'host' => (string) $_POST['db_host'],
            'port' => (string) ($_POST['db_port'] ?: '3306'), 'name' => (string) $_POST['db_name'],
            'user' => (string) $_POST['db_user'], 'password' => (string) $_POST['db_password'],
        ];
        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        ks_install($release['zip'], $release['sha'], $target, $db, (string) ($_POST['app_url'] ?: $scheme.'://'.$_SERVER['HTTP_HOST']));
        if (! empty($_POST['delete_self'])) {
            @unlink($codeFile);
            @unlink(__FILE__);
        }
        $message = $target;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $releases = ks_release_list($development);
} catch (Throwable $e) {
    $releases = [];
    $error ??= ($lang === 'en' ? 'GitHub is not reachable: ' : 'GitHub ist nicht erreichbar: ').$e->getMessage();
}
$checks = ks_requirements($target);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Kasmin Setup</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; padding: 1.5rem 1rem; background: #f3faf3; color: #14501C; }
        main { max-width: 40rem; margin: 0 auto; background: #fff; border-radius: 1rem; padding: 1.25rem; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        label { display: block; margin: .75rem 0 .25rem; font-weight: 600; }
        input, select { width: 100%; box-sizing: border-box; padding: .6rem; border: 1px solid #b6e3b9; border-radius: .5rem; font-size: 1rem; }
        input[type=checkbox] { width: auto; }
        button { width: 100%; margin-top: 1.25rem; padding: .8rem; border: 0; border-radius: .5rem; background: #1DA029; color: #fff; font-size: 1rem; font-weight: 600; }
        .error { background: #fdecec; color: #8a1c1c; padding: .75rem; border-radius: .5rem; }
        .ok { background: #dcf1dd; padding: .75rem; border-radius: .5rem; }
        .row { display: flex; gap: .5rem; align-items: center; }
        small { color: #187f22; }
    </style>
</head>
<body>
<main>
    <p style="text-align:right"><a href="?lang=de">Deutsch</a> · <a href="?lang=en">English</a></p>
    <h1>Kasmin Setup</h1>
    <?php if ($error): ?><p class="error"><?= $h($error) ?></p><?php endif; ?>
    <?php if ($message): ?>
        <div class="ok">
            <p><?= $t('Kasmin wurde entpackt nach:', 'Kasmin was unpacked to:') ?> <code><?= $h($message) ?></code></p>
            <p><?= $t('Stellen Sie jetzt das Verzeichnis der Domain auf', 'Now point the document root of your domain to') ?> <code><?= $h($message) ?>/public</code> <?= $t('und öffnen Sie danach', 'and then open') ?> <code>/install</code>.</p>
        </div>
    <?php else: ?>
        <h2><?= $t('Voraussetzungen', 'Requirements') ?></h2>
        <ul><?php foreach ($checks as $c): ?><li><?= $c['ok'] ? '✓' : '✗' ?> <?= $h($c['label']) ?></li><?php endforeach; ?></ul>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
            <label><?= $t('Zugangscode', 'Access code') ?></label>
            <input name="code" required autocomplete="off">
            <small><?= $t('Steht in der Datei kasmin-setup-code.txt neben diesem Skript (per FTP lesen).', 'Found in the file kasmin-setup-code.txt next to this script (read it via FTP).') ?></small>

            <div class="row" style="margin-top:1rem">
                <input type="checkbox" id="dev" name="development" value="1" <?= $development ? 'checked' : '' ?> onchange="location.search='?development='+(this.checked?1:'')">
                <label for="dev" style="margin:0"><?= $t('Entwicklungsmodus (Branch statt Release – nicht für den Echtbetrieb)', 'Development mode (branch instead of release – not for production)') ?></label>
            </div>

            <label><?= $development ? $t('Branch', 'Branch') : $t('Version', 'Version') ?></label>
            <select name="release" required>
                <?php foreach ($releases as $r): ?>
                    <option value="<?= $h($r['tag']) ?>"><?= $h($r['name']) ?> (<?= $h($r['date']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <small><?php foreach (array_slice($releases, 0, KASMIN_MAX_RELEASES) as $r): ?><a href="<?= $h($r['url']) ?>" target="_blank" rel="noopener"><?= $h($r['name']) ?></a> · <?php endforeach; ?><?= $t('Änderungen ansehen', 'view changes') ?></small>

            <label><?= $t('Zielverzeichnis', 'Target directory') ?></label>
            <input name="target" value="<?= $h($target) ?>" required>
            <small><?= $t('Am besten außerhalb des öffentlichen Webverzeichnisses; die Domain zeigt danach auf den Unterordner public.', 'Preferably outside the public web folder; the domain then points to its public subfolder.') ?></small>

            <h2><?= $t('Datenbank (MariaDB oder MySQL)', 'Database (MariaDB or MySQL)') ?></h2>
            <label>Driver</label>
            <select name="db_driver"><option value="mariadb">MariaDB</option><option value="mysql">MySQL</option></select>
            <label>Host</label><input name="db_host" value="localhost" required>
            <label>Port</label><input name="db_port" value="3306">
            <label><?= $t('Datenbankname', 'Database name') ?></label><input name="db_name" required>
            <label><?= $t('Benutzer', 'User') ?></label><input name="db_user" required>
            <label><?= $t('Passwort', 'Password') ?></label><input name="db_password" type="password">
            <label><?= $t('Adresse der Installation (URL)', 'Address of the installation (URL)') ?></label>
            <input name="app_url" placeholder="https://kasmin.example.org">

            <div class="row" style="margin-top:1rem">
                <input type="checkbox" id="del" name="delete_self" value="1" checked>
                <label for="del" style="margin:0"><?= $t('Dieses Setup-Skript danach löschen', 'Delete this setup script afterwards') ?></label>
            </div>
            <button type="submit"><?= $t('Herunterladen, prüfen und entpacken', 'Download, verify and unpack') ?></button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
