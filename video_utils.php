<?php
// Helper functions for detecting and embedding video links

function detect_video_platform(string $url): ?string {
    $u = trim($url);
    if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL)) return null;
    $host = strtolower(parse_url($u, PHP_URL_HOST) ?? '');

    // Normalize host (strip www.)
    if (str_starts_with($host, 'www.')) $host = substr($host, 4);

    if ($host === 'youtube.com' || $host === 'm.youtube.com' || $host === 'youtu.be' || $host === 'music.youtube.com') {
        return 'youtube';
    }
    if (str_ends_with($host, 'tiktok.com')) {
        return 'tiktok';
    }
    if (str_ends_with($host, 'instagram.com')) {
        return 'instagram';
    }
    if (str_ends_with($host, 'facebook.com') || str_ends_with($host, 'fb.watch')) {
        return 'facebook';
    }
    return null;
}

function extract_youtube_id(string $url): ?string {
    // Support watch?v=, embed/, youtu.be/
    $id = null;
    if (preg_match('~(?:v=|/embed/|youtu\\.be/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
        $id = $m[1];
    }
    return $id;
}

function extract_tiktok_id(string $url): ?string {
    if (preg_match('~/(?:video|v)/(\d+)~', $url, $m)) {
        return $m[1];
    }
    return null;
}

function extract_instagram_path(string $url): ?array {
    // Return [type, code] where type in p|reel|tv
    if (preg_match('~instagram\\.com/(p|reel|tv)/([A-Za-z0-9_-]+)/?~', $url, $m)) {
        return [$m[1], $m[2]];
    }
    return null;
}

function build_embed_url(string $platform, string $originalUrl): ?string {
    switch ($platform) {
        case 'youtube':
            $id = extract_youtube_id($originalUrl);
            return $id ? ('https://www.youtube.com/embed/' . $id) : null;
        case 'tiktok':
            $id = extract_tiktok_id($originalUrl);
            return $id ? ('https://www.tiktok.com/embed/v2/video/' . $id) : null;
        case 'instagram':
            $p = extract_instagram_path($originalUrl);
            if ($p) {
                [$type, $code] = $p;
                return "https://www.instagram.com/{$type}/{$code}/embed";
            }
            return null;
        case 'facebook':
            // Use FB plugin with original href
            $href = rawurlencode($originalUrl);
            return 'https://www.facebook.com/plugins/video.php?href=' . $href . '&show_text=0';
        default:
            return null;
    }
}

?>

