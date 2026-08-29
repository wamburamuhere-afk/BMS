<?php
/**
 * English catalog.
 *
 * English is the source of truth and the fallback: every t($key) call
 * uses the English UI string itself as the key (the standard gettext-style
 * convention), so this file is intentionally empty - t() already returns
 * the key unchanged when no translation is loaded or a key is missing.
 * It exists so English is a real, listed member of SUPPORTED_LANGUAGES
 * and so future maintainers have an obvious place to look.
 */

return [];
