<?php

/**
 * Compile les traductions du module : languages/*.po → .mo et .l10n.php
 * (format PHP natif de WordPress ≥ 6.5, chargé en priorité).
 *
 *   php scripts/build-i18n.php
 *
 * Aucun outil externe (gettext, wp i18n) nécessaire.
 */
$dir = dirname(__DIR__) . '/languages';
foreach (glob($dir . '/*.po') as $po) {
    $entries = parse_po(file_get_contents($po));
    $base = substr($po, 0, -3);
    file_put_contents($base . '.mo', build_mo($entries));
    file_put_contents($base . '.l10n.php', build_l10n($entries));
    printf("%s : %d chaînes\n", basename($po), count($entries['messages']));
}

function parse_po($content)
{
    $headers = array();
    $messages = array();
    $entry = array();
    $field = null;
    $flush = function () use (&$entry, &$messages, &$headers) {
        if (!isset($entry['msgid'])) {
            $entry = array();
            return;
        }
        if ($entry['msgid'] === '') {
            $headers = $entry['msgstr'][0];
        } elseif (isset($entry['msgstr']) && implode('', $entry['msgstr']) !== '') {
            $messages[] = $entry;
        }
        $entry = array();
    };
    foreach (preg_split('/\r\n|\n/', $content) as $line) {
        $line = trim($line);
        if ($line === '') {
            $flush();
            continue;
        }
        if ($line[0] === '#') {
            continue;
        }
        if (preg_match('/^(msgid|msgid_plural|msgstr(?:\[(\d+)\])?)\s+"(.*)"$/', $line, $m)) {
            $value = stripcslashes($m[3]);
            if ($m[1] === 'msgid' || $m[1] === 'msgid_plural') {
                $entry[$m[1]] = $value;
                $field = array($m[1], null);
            } else {
                $index = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
                $entry['msgstr'][$index] = $value;
                $field = array('msgstr', $index);
            }
        } elseif (preg_match('/^"(.*)"$/', $line, $m) && $field) {
            $value = stripcslashes($m[1]);
            if ($field[0] === 'msgstr') {
                $entry['msgstr'][$field[1]] .= $value;
            } else {
                $entry[$field[0]] .= $value;
            }
        }
    }
    $flush();
    return array('headers' => $headers, 'messages' => $messages);
}

function build_mo(array $entries)
{
    $pairs = array('' => $entries['headers']);
    foreach ($entries['messages'] as $e) {
        $key = isset($e['msgid_plural']) ? $e['msgid'] . "\0" . $e['msgid_plural'] : $e['msgid'];
        $pairs[$key] = implode("\0", $e['msgstr']);
    }
    ksort($pairs, SORT_STRING);
    $n = count($pairs);
    $originals = '';
    $translations = '';
    $o_table = array();
    $t_table = array();
    $o_offset = 28 + 16 * $n;
    foreach ($pairs as $orig => $trans) {
        $o_table[] = array(strlen($orig), strlen($originals));
        $originals .= $orig . "\0";
    }
    $t_offset = $o_offset + strlen($originals);
    foreach ($pairs as $orig => $trans) {
        $t_table[] = array(strlen($trans), strlen($translations));
        $translations .= $trans . "\0";
    }
    $mo = pack('V7', 0x950412de, 0, $n, 28, 28 + 8 * $n, 0, 0);
    foreach ($o_table as $o) {
        $mo .= pack('V2', $o[0], $o_offset + $o[1]);
    }
    foreach ($t_table as $t) {
        $mo .= pack('V2', $t[0], $t_offset + $t[1]);
    }
    return $mo . $originals . $translations;
}

function build_l10n(array $entries)
{
    $headers = array();
    foreach (explode("\n", $entries['headers']) as $line) {
        if (strpos($line, ':') !== false) {
            list($k, $v) = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    $messages = array();
    foreach ($entries['messages'] as $e) {
        $key = isset($e['msgid_plural']) ? $e['msgid'] . "\0" . $e['msgid_plural'] : $e['msgid'];
        $messages[$key] = count($e['msgstr']) > 1 ? implode("\0", $e['msgstr']) : $e['msgstr'][0];
    }
    $data = array(
        'domain' => 'faaaster-manager-plugin',
        'plural-forms' => isset($headers['plural-forms']) ? $headers['plural-forms'] : 'nplurals=2; plural=(n > 1);',
        'language' => isset($headers['language']) ? $headers['language'] : '',
        'messages' => $messages,
    );
    return "<?php\n// GÉNÉRÉ par scripts/build-i18n.php depuis le .po — NE PAS ÉDITER.\nreturn " . var_export($data, true) . ";\n";
}
