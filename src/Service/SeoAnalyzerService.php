<?php

// src/Service/SeoAnalyzerService.php
namespace App\Service;

use DOMDocument;
use DOMXPath;
use SimpleXMLElement;

class SeoAnalyzerService
{
    public function fetchContent(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SEOBot/1.0; +http://www.example.com/bot.html)',
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 400 ? ['html' => false, 'http_code' => $httpCode] : ['html' => $data, 'http_code' => $httpCode];
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

    public function getSitemapFromDomain(string $domain): string|false
    {
        $robotsUrl = rtrim($domain, '/') . '/robots.txt';
        $content = $this->fetchContent($robotsUrl)['html'];

        if ($content === false) {
            return false;
        }

        preg_match('/^Sitemap:\s*(.*)$/im', $content, $matches);

        return $matches[1] ?? false;
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

            // Check for urlset
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

    public function analyzeUrl(string $url, bool $checkBrokenLinks = false): array
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
            $report['issues'][] = 'Title 50 karakterden kısa (' . mb_strlen($meta['title']) . ' karakter).';
        }
        if (empty($meta['title'])) {
            $report['issues'][] = 'Title etiketi boş veya bulunamadı.';
        }

        if (empty($meta['description'])) {
            $report['issues'][] = 'Meta description bulunamadı.';
        } else {
            $descLen = mb_strlen($meta['description']);
            if ($descLen < 70) {
                $report['issues'][] = 'Description 70 karakterden kısa (' . $descLen . ' karakter).';
            }
            if ($descLen > 160) {
                $report['issues'][] = 'Description 160 karakterden uzun (' . $descLen . ' karakter).';
            }
        }

        if (empty($meta['h1'])) {
            $report['issues'][] = 'H1 etiketi bulunamadı.';
        }

        if ($checkBrokenLinks) {
            $brokenLinks = $this->findBrokenLinks($html, $url);
            if (!empty($brokenLinks)) {
                $report['issues'][] = 'Kırık linkler bulundu:';
                foreach ($brokenLinks as $link) {
                    $report['issues'][] = sprintf('- %s (HTTP %d)', $link['url'], $link['http_code']);
                }
            }
        }

        $report['status_ok'] = count($report['issues']) === 0;

        return ['success' => true, 'data' => $report];
    }

    private function findBrokenLinks(string $html, string $baseUrl): array
    {
        $allLinks = [];
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $links = $dom->getElementsByTagName('a');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $absoluteUrl = $this->resolveUrl($href, $baseUrl);
            if (!empty($absoluteUrl)) {
                $allLinks[] = $absoluteUrl;
            }
        }

        $uniqueLinks = array_unique($allLinks);
        $brokenLinks = [];
        $linkChunks = array_chunk($uniqueLinks, 10); // Process in chunks of 10

        $chunkCount = count($linkChunks);
        foreach ($linkChunks as $index => $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $url) {
                $ch = curl_init($url);
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
                $handles[$url] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            foreach ($handles as $url => $ch) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode >= 400) {
                    $brokenLinks[] = ['url' => $url, 'http_code' => $httpCode];
                }
                curl_multi_remove_handle($mh, $ch);
            }

            curl_multi_close($mh);

            // Wait for a second before processing the next chunk, but not after the last one.
            if ($index < $chunkCount - 1) {
                sleep(1);
            }
        }

        return $brokenLinks;
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
