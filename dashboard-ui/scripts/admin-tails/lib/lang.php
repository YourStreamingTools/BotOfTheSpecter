// Shared: the page's lang strings by prefix (prefix stripped), dashes normalised.
if (!function_exists('badm_lang')) {
    function badm_lang(string $prefix): array {
        global $translations;
        $out = [];
        foreach (($translations ?? []) as $k => $v) {
            if (is_string($v) && strncmp($k, $prefix, strlen($prefix)) === 0) {
                $out[substr($k, strlen($prefix))] = str_replace(["\u{2014}", "\u{2013}"], '-', $v);
            }
        }
        return $out;
    }
}
