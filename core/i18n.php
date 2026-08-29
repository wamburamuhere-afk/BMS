<?php
/**
 * Translation (i18n) system
 *
 * Language catalogs live in lang/{code}.php and each return a flat
 * ['key' => 'Translated string'] array. English (lang/en.php) is the
 * source of truth and always defines every key; other locales only need
 * to provide the keys they've actually translated - anything missing
 * falls back to English, then to the raw key itself, so a page never
 * renders blank or broken text for an untranslated string.
 */

define('LANG_DIR', ROOT_DIR . '/lang');

/**
 * The languages BMS actually offers.
 *
 * Listing a language here is a promise that the whole application speaks
 * it. An unsupported code (including a preference saved before this list
 * changes) falls back to English in loadLanguage() below rather than
 * loading a catalog that doesn't exist or isn't maintained.
 */
define('SUPPORTED_LANGUAGES', ['en', 'sw']);

$GLOBALS['__translations'] = [];
$GLOBALS['__current_language'] = 'en';

/**
 * Load a language into the current request. Always merges on top of the
 * English catalog so every key resolves to *something* even if the
 * requested locale's file is missing a translation for it.
 *
 * @param string $code Language code ('en' or 'sw'; anything else becomes 'en')
 */
function loadLanguage($code)
{
    $code = is_string($code) ? strtolower(trim($code)) : 'en';
    if (!in_array($code, SUPPORTED_LANGUAGES, true)) {
        $code = 'en';
    }

    $english = [];
    $english_file = LANG_DIR . '/en.php';
    if (is_file($english_file)) {
        $english = include $english_file;
        if (!is_array($english)) {
            $english = [];
        }
    }

    $translations = $english;

    if ($code !== 'en') {
        $lang_file = LANG_DIR . '/' . $code . '.php';
        if (is_file($lang_file)) {
            $overrides = include $lang_file;
            if (is_array($overrides)) {
                // English keys stay as a fallback for anything not yet
                // translated in this locale's file.
                $translations = array_merge($english, $overrides);
            }
        }
    }

    $GLOBALS['__translations'] = $translations;
    $GLOBALS['__current_language'] = $code;
}

/**
 * Translate a key into the currently loaded language.
 *
 * @param string $key Translation key (also the English fallback text convention used in the catalogs)
 * @param string|null $default Explicit fallback if the key isn't found anywhere; defaults to the key itself
 * @return string
 */
function t($key, $default = null)
{
    if (array_key_exists($key, $GLOBALS['__translations']) && $GLOBALS['__translations'][$key] !== '') {
        return $GLOBALS['__translations'][$key];
    }
    return $default !== null ? $default : $key;
}

/**
 * Translate a key and escape it for safe HTML output in one call - the
 * common case for interpolating into markup.
 */
function te($key, $default = null)
{
    echo htmlspecialchars(t($key, $default));
}

function currentLanguage()
{
    return $GLOBALS['__current_language'];
}

function supportedLanguages()
{
    return SUPPORTED_LANGUAGES;
}

// Default to English until header.php resolves the logged-in user's saved
// preference and calls loadLanguage() explicitly.
loadLanguage('en');
