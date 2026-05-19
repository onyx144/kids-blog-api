<?php

 
function unsplashQueryToLatin($query, $enforceLatin)
{
    $query = trim((string) $query);
    if ($query === '') {
        return '';
    }
    if (!$enforceLatin) {
        return $query;
    }
    $q = preg_replace('/[^\x20-\x7E]/u', ' ', $query);
    $q = preg_replace('/\s+/u', ' ', trim($q));

    return $q;
}

function fetchUnsplashImage($query, $enforceLatin = null)
{
    $key = $_ENV['UNSPLASH_ACCESS_KEY'] ?? '';

    if ($key === '') {
        return null;
    }

    if ($enforceLatin === null) {
        $raw = strtolower(trim((string) ($_ENV['UNSPLASH_REQUIRE_LATIN_QUERY'] ?? '1')));
        $enforceLatin = in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    $query = unsplashQueryToLatin($query, (bool) $enforceLatin);
    if ($query === '') {
        return null;
    }

    $contentFilter = $_ENV['UNSPLASH_CONTENT_FILTER'] ?? 'high';

    $base = 'https://api.unsplash.com/search/photos'
        . '?query=' . urlencode($query)
        . '&per_page=12'
        . '&orientation=landscape'
        . '&order_by=relevant'
        . '&content_filter=' . urlencode($contentFilter);

    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Client-ID {$key}"]
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $results = $response['results'] ?? [];
    if ($results === []) {
        return null;
    }

    return pickUnsplashImageUrl($results, $query);
}

 
function pickUnsplashImageUrl(array $results, $searchQuery)
{
    $tokens = preg_split('/\s+/u', mb_strtolower((string) $searchQuery));
    $tokens = array_values(array_filter($tokens, static function ($t) {
        return $t !== '' && mb_strlen($t) > 2;
    }));
    if ($tokens === []) {
        $first = $results[0];

        return $first['urls']['regular'] ?? null;
    }

    $bestScore = -1;
    $bestUrl = null;

    foreach ($results as $r) {
        $tagText = '';
        if (!empty($r['tags']) && is_array($r['tags'])) {
            foreach ($r['tags'] as $tag) {
                if (is_string($tag)) {
                    $tagText .= ' ' . $tag;
                } elseif (is_array($tag) && isset($tag['title'])) {
                    $tagText .= ' ' . $tag['title'];
                }
            }
        }

        $hay = mb_strtolower(
            ($r['description'] ?? '')
            . ' '
            . ($r['alt_description'] ?? '')
            . ' '
            . $tagText
        );

        $score = 0;
        foreach ($tokens as $t) {
            if (mb_strpos($hay, $t) !== false) {
                ++$score;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestUrl = $r['urls']['regular'] ?? $bestUrl;
        }
    }

    if ($bestUrl !== null) {
        return $bestUrl;
    }

    $first = $results[0];

    return $first['urls']['regular'] ?? null;
}

/**
 * Перебір кандидатів запиту, поки Unsplash не поверне URL.
 *
 * @param string[] $queries
 */
function fetchUnsplashImageFirstMatch(array $queries, $enforceLatin = null)
{
    foreach ($queries as $q) {
        $q = trim((string) $q);
        if ($q === '') {
            continue;
        }
        $url = fetchUnsplashImage($q, $enforceLatin);
        if ($url) {
            return $url;
        }
    }

    return null;
}
