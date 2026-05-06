function fetchUnsplashImage($query) {
    $key = $_ENV['UNSPLASH_ACCESS_KEY'];
    
    $url = "https://api.unsplash.com/search/photos?query=" 
         . urlencode($query) 
         . "&per_page=1&orientation=landscape";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Client-ID {$key}"]
    ]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $response['results'][0]['urls']['regular'] ?? null;
}