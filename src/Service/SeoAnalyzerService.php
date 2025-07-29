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
            CURLOPT_TIMEOUT => 30,
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

        $report['status_ok'] = count($report['issues']) === 0;

        return ['success' => true, 'data' => $report];
    }
}
