<?php

// src/Controller/SeoAnalyzerController.php
namespace App\Controller;

use App\Service\SeoAnalyzerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

use Google\Service\Sheets;
use Google\Client as GoogleClient;

class SeoAnalyzerController extends AbstractController
{
    #[Route('/seo-analizi', name: 'seo_analyzer', methods: ['GET'])]
    public function index()
    {
        return $this->render('seo_analyzer/index.html.twig');
    }

    #[Route('/kirik-link-denetimi', name: 'broken_link_checker', methods: ['GET'])]
    public function brokenLinkChecker()
    {
        return $this->render('seo_analyzer/broken_links.html.twig');
    }

    #[Route('/api/seo/get-sitemaps', name: 'api_get_sitemaps', methods: ['POST'])]
    public function getSitemaps(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $domain = $request->request->get('domain');
        if (!filter_var($domain, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Geçersiz Domain.']);
        }

        $sitemapUrl = $seoAnalyzer->getSitemapFromDomain($domain);

        if (!$sitemapUrl) {
            return $this->json(['success' => false, 'message' => 'robots.txt bulunamadı veya içinde sitemap URLsi yok.']);
        }

        $sitemapData = $seoAnalyzer->parseSitemap($sitemapUrl);

        if ($sitemapData === false) {
            return $this->json(['success' => false, 'message' => 'Sitemap okunamadı veya geçersiz formatta.']);
        }

        return $this->json(['success' => true, 'data' => $sitemapData]);
    }

    #[Route('/api/seo/get-urls-from-sitemap', name: 'api_get_urls_from_sitemap', methods: ['POST'])]
    public function getUrlsFromSitemap(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $sitemapUrl = $request->request->get('sitemap_url');
        if (!filter_var($sitemapUrl, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Geçersiz Sitemap URLsi.']);
        }

        $sitemapData = $seoAnalyzer->parseSitemap($sitemapUrl);

        if ($sitemapData === false || $sitemapData['type'] !== 'urls') {
            return $this->json(['success' => false, 'message' => 'URL listesi alınamadı veya geçersiz sitemap.']);
        }

        return $this->json(['success' => true, 'urls' => $sitemapData['urls']]);
    }

    #[Route('/api/seo/analyze-url', name: 'api_analyze_url', methods: ['POST'])]
    public function analyzeUrl(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $url = $request->request->get('url');

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Geçersiz URL.']);
        }

        return $this->json($seoAnalyzer->analyzeUrl($url));
    }

    #[Route('/api/seo/kirik-linkleri-bul', name: 'api_find_broken_links', methods: ['POST'])]
    public function findBrokenLinksApi(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $url = $request->request->get('url');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Geçersiz URL.']);
        }

        return $this->json($seoAnalyzer->getBrokenLinksForUrl($url));
    }

    #[Route('/api/seo/export-to-sheets', name: 'api_export_to_sheets', methods: ['POST'])]
    public function exportToSheets(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $rows = $data['rows'] ?? [];

        if (empty($rows)) {
            return $this->json(['success' => false, 'message' => 'Aktarılacak veri bulunamadı.'], 400);
        }

        try {
            $client = new GoogleClient();
            $client->setApplicationName('Image Editor Seo Analyzer');
            $client->setScopes([Sheets::SPREADSHEETS]);
            $credentialsPath = $this->getParameter('kernel.project_dir') . '/config/google/credential.json';
            $client->setAuthConfig($credentialsPath);

            $sheetsService = new Sheets($client);
            $spreadsheetId = '128JJFW3EKg-DQ9-DRT8xWnTDitY7IpTDmX4GHpU4hrY';

            $clearRange = 'Sayfa1';
            $clearBody = new \Google_Service_Sheets_ClearValuesRequest();
            $sheetsService->spreadsheets_values->clear($spreadsheetId, $clearRange, $clearBody);

            $body = new \Google_Service_Sheets_ValueRange([
                'values' => $rows
            ]);
            $params = ['valueInputOption' => 'USER_ENTERED'];
            $sheetsService->spreadsheets_values->update($spreadsheetId, 'A1', $body, $params);

            return $this->json([
                'success' => true,
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/' . $spreadsheetId
            ]);

        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Google Sheets API hatası: ' . $e->getMessage()], 500);
        }
    }
}

