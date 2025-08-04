<?php

// src/Controller/SeoAnalyzerController.php
namespace App\Controller;

use App\Service\SeoAnalyzerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;



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

    #[Route('/kirik-link-denetimi', name: 'api_find_broken_links', methods: ['POST'])]
    public function findBrokenLinksApi(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $url = $request->request->get('url');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Geçersiz URL.']);
        }

        return $this->json($seoAnalyzer->getBrokenLinksForUrl($url));
    }
    private const SHEETS_CONFIG_PATH = '/config/google/sheets_config.json';

    #[Route('/api/seo/get-saved-sheets', name: 'api_get_saved_sheets', methods: ['GET'])]
    public function getSavedSheets(): JsonResponse
    {
        $configFile = $this->getParameter('kernel.project_dir') . self::SHEETS_CONFIG_PATH;
        if (!file_exists($configFile)) {
            return $this->json(['success' => true, 'sheets' => []]);
        }

        $config = json_decode(file_get_contents($configFile), true);
        return $this->json(['success' => true, 'sheets' => $config ?? []]);
    }

    #[Route('/api/seo/save-sheet-config', name: 'api_save_sheet_config', methods: ['POST'])]
    public function saveSheet(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $name = $data['name'] ?? null;
        $id = $data['id'] ?? null;

        if (empty($name) || empty($id)) {
            return $this->json(['success' => false, 'message' => 'E-Tablo Adı ve IDsi gereklidir.'], 400);
        }

        $configFile = $this->getParameter('kernel.project_dir') . self::SHEETS_CONFIG_PATH;
        $config = [];
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true) ?? [];
        }

        $config[$name] = $id;

        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));

        return $this->json(['success' => true, 'message' => 'E-Tablo başarıyla kaydedildi.']);
    }

    #[Route('/api/seo/get-sheet-names', name: 'api_get_sheet_names', methods: ['POST'])]
    public function getSheetNames(Request $request, SeoAnalyzerService $seoAnalyzerService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $spreadsheetId = $data['spreadsheetId'] ?? null;

        if (empty($spreadsheetId)) {
            return $this->json(['success' => false, 'message' => 'Spreadsheet ID bulunamadı.'], 400);
        }

        $result = $seoAnalyzerService->getGoogleSheetNames($spreadsheetId);

        if ($result['success']) {
            return $this->json(['success' => true, 'sheets' => $result['sheets']]);
        } else {
            return $this->json(['success' => false, 'message' => $result['message']], 500);
        }
    }

    #[Route('/api/seo/export-to-sheets', name: 'api_export_to_sheets', methods: ['POST'])]
    public function exportToSheets(Request $request, SeoAnalyzerService $seoAnalyzerService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $rows = $data['rows'] ?? [];
        $spreadsheetId = $data['spreadsheetId'] ?? null;
        $tabName = $data['tabName'] ?? null;

        if (empty($rows) || empty($spreadsheetId) || empty($tabName)) {
            return $this->json(['success' => false, 'message' => 'Eksik parametre: Aktarılacak veri, Spreadsheet ID ve Sekme Adı gereklidir.'], 400);
        }

        $result = $seoAnalyzerService->exportToGoogleSheets($rows, $spreadsheetId, $tabName);

        if ($result['success']) {
            return $this->json(['success' => true, 'spreadsheet_url' => $result['spreadsheet_url']]);
        } else {
            return $this->json(['success' => false, 'message' => $result['message']], 500);
        }
    }
}

