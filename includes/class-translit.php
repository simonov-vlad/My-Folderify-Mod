<?php
namespace Folderify;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Translit
 *
 * Turns Russian (and Ukrainian/Belarusian) names into safe Latin file and directory names.
 * Only the names ON DISK change. Attachment titles and Folderify folder names keep the
 * original text, so nothing looks different in the Media Library.
 */
class Translit
{
    /** Lower-case map. Upper-case letters are derived from it. */
    private static $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        // Ukrainian / Belarusian / Serbian extras.
        'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g', 'ў' => 'u', 'ј' => 'j', 'љ' => 'lj',
        'њ' => 'nj', 'ћ' => 'c', 'ђ' => 'dj', 'џ' => 'dz',
    ];

    /**
     * Does the string contain anything outside plain ASCII?
     */
    public static function needs($string)
    {
        return (bool) preg_match('/[^\x00-\x7F]/', (string) $string);
    }

    /**
     * Raw transliteration: replaces letters, leaves everything else as it is.
     */
    public static function to_latin($string)
    {
        $string = (string) $string;
        if (!self::needs($string)) {
            return $string;
        }

        $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
        $out = '';
        $count = count($chars);

        for ($i = 0; $i < $count; $i++) {
            $ch = $chars[$i];
            $lower = mb_strtolower($ch, 'UTF-8');

            if (!isset(self::$map[$lower])) {
                $out .= $ch;
                continue;
            }

            $latin = self::$map[$lower];
            if ($ch !== $lower && $latin !== '') {
                // Upper-case letter. "Ж" -> "Zh", but inside an ALL-CAPS word "Ж" -> "ZH".
                $next = ($i + 1 < $count) ? $chars[$i + 1] : '';
                $next_is_upper = $next !== '' && $next === mb_strtoupper($next, 'UTF-8') && $next !== mb_strtolower($next, 'UTF-8');
                $latin = ($next_is_upper || strlen($latin) === 1) ? strtoupper($latin) : ucfirst($latin);
            }
            $out .= $latin;
        }

        // Anything left (accented Latin, other scripts): best effort.
        if (self::needs($out)) {
            if (function_exists('remove_accents')) {
                $out = remove_accents($out);
            }
            if (self::needs($out) && function_exists('transliterator_transliterate')) {
                $t = @transliterator_transliterate('Any-Latin; Latin-ASCII', $out); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                if (is_string($t)) {
                    $out = $t;
                }
            }
            $out = preg_replace('/[^\x00-\x7F]+/', '', $out);
        }

        return $out;
    }

    /**
     * Clean up a transliterated piece so it is safe as a directory / file name.
     */
    private static function clean($text, $fallback_source)
    {
        $text = preg_replace('/[^A-Za-z0-9._-]+/', '-', $text);
        $text = preg_replace('/-{2,}/', '-', $text);
        $text = preg_replace('/\.{2,}/', '.', $text);
        $text = trim($text, '-._');
        if ($text === '') {
            $text = substr(md5((string) $fallback_source), 0, 8);
        }
        if (strlen($text) > 100) {
            $text = rtrim(substr($text, 0, 100), '-._');
        }
        return $text;
    }

    /**
     * Directory name. "Ворота - х.Пятихатки" -> "Vorota-kh.Pyatihatki".
     */
    public static function segment($name)
    {
        return self::clean(self::to_latin($name), $name);
    }

    /**
     * File name with extension. "Ворота (1).webp" -> "Vorota-1.webp".
     */
    public static function filename($basename)
    {
        $basename = (string) $basename;
        $dot = strrpos($basename, '.');
        if ($dot === false || $dot === 0) {
            return self::clean(self::to_latin($basename), $basename);
        }
        $stem = substr($basename, 0, $dot);
        $ext = substr($basename, $dot + 1);

        $ext = preg_replace('/[^A-Za-z0-9]+/', '', self::to_latin($ext));
        $stem = self::clean(self::to_latin($stem), $stem);

        return $ext !== '' ? $stem . '.' . $ext : $stem;
    }
}
