<?php

// src/Service/SeoAnalyzerService.php
namespace App\Service;

use DOMDocument;
use DOMXPath;
use SimpleXMLElement;
use Google\Service\Sheets;
use Google\Client as GoogleClient;

class SeoAnalyzerService
{
    private string $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    public function extractMetaData(?string $html): array
    {
        $result = ['title' => '', 'description' => '', 'h1' => ''];
        if (empty($html)) {
            return $result;
        }
        @$dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);

        $result['title'] = $xpath->query('//title')->item(0)?->nodeValue ?? '';
        $result['description'] = $xpath->query('//meta[@name="description"]/@content')->item(0)?->nodeValue ?? '';
        $result['h1'] = $xpath->query('//h1')->item(0)?->nodeValue ?? '';

        return array_map('trim', $result);
    }

    public function fetchContent(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30, // 120 çok uzun, 30 idealdir
            // Otomatik GZIP çözme (Çok önemli)
            CURLOPT_ENCODING => '',
            // Daha gerçekçi bir tarayıcı taklidi
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Connection: keep-alive'
            ]
        ]);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch); // Hata varsa görelim
        curl_close($ch);

        // Boş veri veya hata durumunu kontrol et
        if ($data === false || $httpCode >= 400) {
            // Debug için error log'a yazabilirsin: error_log("CURL Error: $curlError URL: $url Code: $httpCode");
            return ['html' => false, 'http_code' => $httpCode];
        }

        return ['html' => $data, 'http_code' => $httpCode];
    }

    public function getSitemapFromDomain(string $inputUrl): string|false
    {
        // 1. URL'i parçala ve kök dizini (Root Domain) bul
        $parsed = parse_url($inputUrl);

        // Eğer http/https girilmemişse varsayılan ekle
        if (!isset($parsed['scheme'])) {
            $inputUrl = 'https://' . $inputUrl;
            $parsed = parse_url($inputUrl);
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        if (empty($host)) {
            return false;
        }

        $baseUrl = $scheme . '://' . $host;

        // 2. Robots.txt'yi kontrol et
        $robotsUrl = $baseUrl . '/robots.txt';
        $fetch = $this->fetchContent($robotsUrl);

        if ($fetch['html'] !== false) {
            // Case-insensitive (i) ve multiline (m) arama yap
            if (preg_match('/^Sitemap:\s*(.*)$/im', $fetch['html'], $matches)) {
                return trim($matches[1]);
            }
        }

        // 3. Robots.txt'de yoksa veya robots.txt açılmadıysa standart yolları dene
        $commonPaths = [
            '/sitemap_index.xml',
            '/sitemap.xml',
            '/sitemap/sitemap.xml'
        ];

        foreach ($commonPaths as $path) {
            $tryUrl = $baseUrl . $path;
            // Sadece başlık (HEAD) kontrolü yapıp dosya var mı bakabiliriz ama
            // senin yapında direkt indirip bakmak daha garanti.
            $check = $this->fetchContent($tryUrl);
            if ($check['http_code'] === 200 && !empty($check['html'])) {
                // İçerik gerçekten XML mi diye basitçe bak
                if (str_contains($check['html'], '<?xml') || str_contains($check['html'], '<urlset') || str_contains($check['html'], '<sitemapindex')) {
                    return $tryUrl;
                }
            }
        }

        return false;
    }

    public function parseSitemap(string $sitemapUrl): array|false
    {
        $content = $this->fetchContent($sitemapUrl)['html'];
        if ($content === false) {
            return false;
        }

        if (str_ends_with($sitemapUrl, '.gz')) {
            $content = gzdecode($content);
        }

        if (empty($content)) {
            return false;
        }

        try {
            $xml = new SimpleXMLElement($content, LIBXML_NOCDATA);
            $urls = [];

            // Check for sitemap index
            if (isset($xml->sitemap)) {
                foreach ($xml->sitemap as $sitemapNode) {
                    $urls[] = (string)$sitemapNode->loc;
                }
                return ['type' => 'index', 'sitemaps' => array_unique($urls)];
            }

            if (isset($xml->url)) {
                foreach ($xml->url as $urlNode) {
                    $urls[] = (string)$urlNode->loc;
                }
                return ['type' => 'urls', 'urls' => array_unique($urls)];
            }

            return false;
        } catch (\Exception) {
            return false;
        }
    }

    public function analyzeUrl(string $url): array
    {
        $fetch = $this->fetchContent($url);
        $html = $fetch['html'];
        $report = [
            'url' => $url,
            'title' => 'N/A',
            'description' => 'N/A',
            'h1' => 'N/A',
            'issues' => [],
            'status_ok' => false,
        ];

        if ($html === false) {
            $report['issues'][] = 'Sayfa alınamadı (HTTP ' . $fetch['http_code'] . ' hatası).';
            return ['success' => true, 'data' => $report];
        }

        $meta = $this->extractMetaData($html);
        $report = array_merge($report, $meta);

        if (mb_strlen($meta['title']) > 60) {
            $report['issues'][] = 'Title 60 karakterden uzun (' . mb_strlen($meta['title']) . ' karakter).';
        }
        if (mb_strlen($meta['title']) < 50) {
            $report['issues'][] = 'Title 50 karakterden kisa (' . mb_strlen($meta['title']) . ' karakter).';
        }
        if (empty($meta['title'])) {
            $report['issues'][] = 'Title etiketi bos veya bulunamadi.';
        }

        if (empty($meta['description'])) {
            $report['issues'][] = 'Meta description bulunamadı.';
        } else {
            $descLen = mb_strlen($meta['description']);
            if ($descLen < 70) {
                $report['issues'][] = 'Description 70 karakterden kisa (' . $descLen . ' karakter).';
            }
            if ($descLen > 160) {
                $report['issues'][] = 'Description 160 karakterden uzun (' . $descLen . ' karakter).';
            }
        }

        if (empty($meta['h1'])) {
            $report['issues'][] = 'H1 etiketi bulunamadı.';
        }

        $report['status_ok'] = count($report['issues']) === 0;

        return ['success' => true, 'data' => $report];
    }

    public function getBrokenLinksForUrl(string $url): array
    {
        $fetch = $this->fetchContent($url);
        $html = $fetch['html'];

        if ($html === false) {
            return ['success' => false, 'message' => 'Sayfa alınamadı (HTTP ' . $fetch['http_code'] . ' hatası).'];
        }

        $allLinks = [];
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $links = $dom->getElementsByTagName('a');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $absoluteUrl = $this->resolveUrl($href, $url);
            if (!empty($absoluteUrl)) {
                $allLinks[] = $absoluteUrl;
            }
        }

        $uniqueLinks = array_unique($allLinks);
        $brokenLinks = [];
        $linkChunks = array_chunk($uniqueLinks, 10);

        $chunkCount = count($linkChunks);
        foreach ($linkChunks as $index => $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $chunkUrl) {
                $ch = curl_init($chunkUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_NOBODY => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SEOBot/1.0; +http://www.example.com/bot.html)',
                    CURLOPT_CONNECTTIMEOUT => 5,
                ]);

                curl_multi_add_handle($mh, $ch);
                $handles[$chunkUrl] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            foreach ($handles as $handleUrl => $ch) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode >= 400) {
                    $brokenLinks[] = ['url' => $handleUrl, 'http_code' => $httpCode];
                }
                curl_multi_remove_handle($mh, $ch);
            }

            curl_multi_close($mh);

            if ($index < $chunkCount - 1) {
                sleep(1);
            }
        }

        return ['success' => true, 'data' => $brokenLinks];
    }

    private function resolveUrl(?string $href, string $baseUrl): string
    {
        if (empty($href)) {
            return '';
        }

        $href = trim($href);

        if (filter_var($href, FILTER_VALIDATE_URL)) {
            return $href;
        }

        if (preg_match('/^(#|javascript:|mailto:|tel:|sms:)/i', $href)) {
            return '';
        }

        $base = parse_url($baseUrl);
        if (empty($base['scheme']) || empty($base['host'])) {
            return '';
        }

        $scheme = $base['scheme'];
        $host = $base['host'];

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $href;
        }

        $path = $base['path'] ?? '/';
        $path = dirname($path);
        if ($path === '.' || $path === '/') {
            $path = '';
        }

        return $scheme . '://' . $host . $path . '/' . $href;
    }

}
