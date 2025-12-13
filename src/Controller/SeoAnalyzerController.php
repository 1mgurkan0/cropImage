<?php


namespace App\Controller;

use App\Service\SeoAnalyzerService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

class SeoAnalyzerController extends AbstractController
{
    #[Route('/seo-analysis', name: 'seo_analyzer', methods: ['GET'])]
    public function index()
    {
        return $this->render('seo_analyzer/index.html.twig');
    }

    #[Route('/api/seo/get-sitemaps', name: 'api_get_sitemaps', methods: ['POST'])]
    public function getSitemaps(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $domain = $request->request->get('domain');
        if (!filter_var($domain, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Invalid Domain.']);
        }

        $sitemapUrl = $seoAnalyzer->getSitemapFromDomain($domain);

        if (!$sitemapUrl) {
            return $this->json(['success' => false, 'message' => 'robots.txt not found or sitemap URL missing.']);
        }

        $sitemapData = $seoAnalyzer->parseSitemap($sitemapUrl);

        if ($sitemapData === false) {
            return $this->json(['success' => false, 'message' => 'Sitemap could not be read or invalid format.']);
        }

        return $this->json(['success' => true, 'data' => $sitemapData]);
    }

    #[Route('/api/seo/get-urls-from-sitemap', name: 'api_get_urls_from_sitemap', methods: ['POST'])]
    public function getUrlsFromSitemap(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $sitemapUrl = $request->request->get('sitemap_url');
        if (!filter_var($sitemapUrl, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Invalid Sitemap URL.']);
        }

        $sitemapData = $seoAnalyzer->parseSitemap($sitemapUrl);

        if ($sitemapData === false || $sitemapData['type'] !== 'urls') {
            return $this->json(['success' => false, 'message' => 'Could not retrieve URL list or invalid sitemap.']);
        }

        return $this->json(['success' => true, 'urls' => $sitemapData['urls']]);
    }

    #[Route('/api/seo/analyze-url', name: 'api_analyze_url', methods: ['POST'])]
    public function analyzeUrl(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $url = $request->request->get('url');

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Invalid URL.']);
        }

        return $this->json($seoAnalyzer->analyzeUrl($url));
    }
    #[Route('/api/seo/export-excel', name: 'app_export_excel', methods: ['POST'])]
    public function exportExcel(Request $request, SeoAnalyzerService $seoAnalyzerService): StreamedResponse|JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $rows = $data['data'] ?? [];
            $rawDomain = $data['domain'] ?? 'seo-report';

            if (empty($rows)) {
                return $this->json(['error' => 'No data found to export'], 400);
            }

            $cleanDomain = str_replace(['https://', 'http://', 'www.', '/'], '', $rawDomain);
            $cleanDomain = preg_replace('/[^a-zA-Z0-9\-\.]/', '-', $cleanDomain);

            if (empty($cleanDomain)) { $cleanDomain = 'seo-report'; }

            $spreadsheet = $seoAnalyzerService->generateExcelSpreadsheet($rows);

            $writer = new Xlsx($spreadsheet);

            $response = new StreamedResponse(function() use ($writer) {
                $writer->save('php://output');
            });

            $fileName = $cleanDomain . '-analysis-' . date('Y-m-d') . '.xlsx';

            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', 'attachment;filename="' . $fileName . '"');
            $response->headers->set('Cache-Control', 'max-age=0');

            return $response;

        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/broken-link-checker', name: 'broken_link_checker', methods: ['GET'])]
    public function brokenLinkChecker()
    {
        return $this->render('seo_analyzer/broken_links.html.twig');
    }

    #[Route('/api/seo/find-broken-links', name: 'api_find_broken_links', methods: ['POST'])]
    public function findBrokenLinksApi(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $url = $request->request->get('url');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Invalid URL.']);
        }

        return $this->json($seoAnalyzer->getBrokenLinksForUrl($url));
    }
}
