<?php

namespace App\Service;

use DOMDocument;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use SimpleXMLElement;

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
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Connection: keep-alive'
            ]
        ]);

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($data === false || $httpCode >= 400) {
            return ['html' => false, 'http_code' => $httpCode];
        }

        return ['html' => $data, 'http_code' => $httpCode];
    }

    public function getSitemapFromDomain(string $inputUrl): string|false
    {
        $parsed = parse_url($inputUrl);

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

        $robotsUrl = $baseUrl . '/robots.txt';
        $fetch = $this->fetchContent($robotsUrl);

        if ($fetch['html'] !== false) {
            if (preg_match('/^Sitemap:\s*(.*)$/im', $fetch['html'], $matches)) {
                return trim($matches[1]);
            }
        }

        $commonPaths = [
            '/sitemap_index.xml',
            '/sitemap.xml',
            '/sitemap/sitemap.xml'
        ];

        foreach ($commonPaths as $path) {
            $tryUrl = $baseUrl . $path;
            $check = $this->fetchContent($tryUrl);
            if ($check['http_code'] === 200 && !empty($check['html'])) {
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
            $report['issues'][] = 'Critical: Page could not be retrieved (HTTP ' . $fetch['http_code'] . ' error).';
            return ['success' => true, 'data' => $report];
        }

        $meta = $this->extractMetaData($html);
        $report = array_merge($report, $meta);

        if (empty($meta['title'])) {
            $report['issues'][] = 'Critical: Title tag is missing or empty.';
        } else {
            $titleLen = mb_strlen($meta['title']);

            if ($titleLen < 30) {
                $report['issues'][] = 'Critical: Title is too short (' . $titleLen . ' chars). Minimum 30 required for relevance.';
            } elseif ($titleLen >= 30 && $titleLen < 50) {
                $report['issues'][] = 'Warning: Title is slightly short (' . $titleLen . ' chars). Recommended: 50-60 characters.';
            } elseif ($titleLen >= 50 && $titleLen <= 60) {
            } elseif ($titleLen > 60 && $titleLen <= 75) {
                $report['issues'][] = 'Warning: Title is slightly long (' . $titleLen . ' chars). It may be truncated on some devices.';
            } else {
                $report['issues'][] = 'Critical: Title is too long (' . $titleLen . ' chars). It will be truncated in search results.';
            }
        }

        if (empty($meta['description'])) {
            $report['issues'][] = 'Critical: Meta description is missing.';
        } else {
            $descLen = mb_strlen($meta['description']);

            if ($descLen < 70) {
                $report['issues'][] = 'Warning: Meta description is too short (' . $descLen . ' chars). Recommended: 120-160 characters.';
            } elseif ($descLen >= 70 && $descLen < 120) {
                $report['issues'][] = 'Warning: Meta description is slightly short (' . $descLen . ' chars). Use this space to pitch your content.';
            } elseif ($descLen >= 120 && $descLen <= 160) {
            } else {
                $report['issues'][] = 'Warning: Meta description is too long (' . $descLen . ' chars). It will be truncated (~160 chars max).';
            }
        }

        if (empty($meta['h1'])) {
            $report['issues'][] = 'Critical: H1 tag is missing. Every page should have one main heading.';
        } else {

            $h1Len = mb_strlen($meta['h1']);
            if ($h1Len > 70) {
                $report['issues'][] = 'Warning: H1 tag is quite long (' . $h1Len . ' chars). Ensure it is readable and concise.';
            }
        }

        $report['status_ok'] = count($report['issues']) === 0;

        return ['success' => true, 'data' => $report];
    }

    public function generateExcelSpreadsheet(array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $spreadsheet->getDefaultStyle()->getFont()->setName('Segoe UI');
        $spreadsheet->getDefaultStyle()->getFont()->setSize(10);

        $sheet->setShowGridlines(false);

        $headers = ['URL', 'Title', 'Description', 'H1', 'Status', 'Issues'];
        $sheet->fromArray($headers, NULL, 'A1');

        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
                'size' => 11
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF5D67E6']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['argb' => 'FF3B4396'],
                ],
            ],
        ];

        $sheet->getStyle('A1:F1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);

        $rowNumber = 2;

        $borderStyle = [
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFD9D9D9'],
                ],
            ],
        ];

        foreach ($rows as $row) {
            $sheet->setCellValue('A' . $rowNumber, $row['url'] ?? '');
            $sheet->setCellValue('B' . $rowNumber, $row['title'] ?? '');
            $sheet->setCellValue('C' . $rowNumber, $row['description'] ?? '');
            $sheet->setCellValue('D' . $rowNumber, $row['h1'] ?? '');

            $issues = $row['issues'] ?? [];
            $issuesText = is_array($issues) ? implode("\n", $issues) : (string)$issues;

            if (!empty($issuesText)) {
                $issuesText .= "\n ";
            }

            $sheet->setCellValue('F' . $rowNumber, $issuesText);

            $statusText = 'OK';
            $statusColor = 'FF28A745';
            $rowBgColor = null;

            if (!empty($issues)) {
                if (str_contains($issuesText, 'Critical')) {
                    $statusText = 'CRITICAL';
                    $statusColor = 'FFDC3545';
                    $rowBgColor  = 'FFFFF5F5';
                } elseif (str_contains($issuesText, 'Warning')) {
                    $statusText = 'WARNING';
                    $statusColor = 'F39C12';
                    $rowBgColor  = 'FFFFFBF0';
                }
            }
            $sheet->setCellValue('E' . $rowNumber, $statusText);


            if ($rowBgColor) {
                $sheet->getStyle('A' . $rowNumber . ':F' . $rowNumber)
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()
                    ->setARGB($rowBgColor);
            }

            $sheet->getStyle('A' . $rowNumber . ':F' . $rowNumber)->applyFromArray($borderStyle);

            $sheet->getStyle('A'.$rowNumber.':F'.$rowNumber)
                ->getAlignment()
                ->setVertical(Alignment::VERTICAL_TOP)
                ->setIndent(1);

            $sheet->getStyle('F'.$rowNumber)->getAlignment()->setWrapText(true);
            $sheet->getStyle('E'.$rowNumber)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->getStyle('E' . $rowNumber)->getFont()->getColor()->setARGB($statusColor);
            $sheet->getStyle('E' . $rowNumber)->getFont()->setBold(true);
            $sheet->getStyle('A' . $rowNumber)->getFont()->getColor()->setARGB('FF5D67E6');

            $rowNumber++;
        }

        $sheet->getColumnDimension('A')->setWidth(50);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(45);
        $sheet->getColumnDimension('D')->setWidth(25);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(80);

        return $spreadsheet;
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
