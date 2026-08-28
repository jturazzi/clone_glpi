<?php

/**
 * Compiles every locales/*.po file into a matching .mo file.
 *
 * GLPI's Plugin::loadLang() reads compiled gettext catalogs (.mo), not the
 * .po sources, so this must be run whenever a .po file changes.
 *
 * Usage: php tools/compile-locales.php
 */

function parsePoFile(string $path): array
{
    $entries = [];
    $msgid = null;
    $msgstr = null;
    $state = null;

    $flush = function () use (&$entries, &$msgid, &$msgstr): void {
        if ($msgid !== null) {
            $entries[$msgid] = $msgstr ?? '';
        }
    };

    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (preg_match('/^msgid\s+"(.*)"$/', $line, $m)) {
            $flush();
            $msgid = stripcslashes($m[1]);
            $msgstr = null;
            $state = 'id';
            continue;
        }

        if (preg_match('/^msgstr\s+"(.*)"$/', $line, $m)) {
            $msgstr = stripcslashes($m[1]);
            $state = 'str';
            continue;
        }

        if (preg_match('/^"(.*)"$/', $line, $m)) {
            $chunk = stripcslashes($m[1]);
            if ($state === 'id') {
                $msgid .= $chunk;
            } elseif ($state === 'str') {
                $msgstr .= $chunk;
            }
            continue;
        }
    }
    $flush();

    return $entries;
}

function writeMoFile(array $entries, string $path): void
{
    ksort($entries, SORT_STRING);
    $keys = array_keys($entries);
    $n = count($keys);

    $originals = '';
    $translations = '';
    $keyTable = [];
    $valTable = [];

    foreach ($keys as $key) {
        $keyTable[] = [strlen($key), strlen($originals)];
        $originals .= $key . "\0";
    }
    foreach ($keys as $key) {
        $value = $entries[$key];
        $valTable[] = [strlen($value), strlen($translations)];
        $translations .= $value . "\0";
    }

    $headerSize = 28;
    $keyTableOffset = $headerSize;
    $valTableOffset = $keyTableOffset + $n * 8;
    $originalsOffset = $valTableOffset + $n * 8;
    $translationsOffset = $originalsOffset + strlen($originals);

    $output = pack('V7', 0x950412de, 0, $n, $keyTableOffset, $valTableOffset, 0, $translationsOffset);

    foreach ($keyTable as [$len, $off]) {
        $output .= pack('VV', $len, $off + $originalsOffset);
    }
    foreach ($valTable as [$len, $off]) {
        $output .= pack('VV', $len, $off + $translationsOffset);
    }

    $output .= $originals . $translations;

    file_put_contents($path, $output);
}

$localesDir = __DIR__ . '/../locales';
$poFiles = glob($localesDir . '/*.po');

if (!$poFiles) {
    fwrite(STDERR, "No .po files found in $localesDir\n");
    exit(1);
}

foreach ($poFiles as $poFile) {
    $moFile = preg_replace('/\.po$/', '.mo', $poFile);
    $entries = parsePoFile($poFile);
    writeMoFile($entries, $moFile);
    echo basename($poFile) . ' -> ' . basename($moFile) . ' (' . count($entries) . " entries)\n";
}
