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

    #[Route('/api/seo/kirik-linkleri-bul', name: 'api_find_broken_links', methods: ['POST'])]
    public function findBrokenLinksApi(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $url = $request->request->get('url');
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Geçersiz URL.']);
        }

        return $this->json($seoAnalyzer->getBrokenLinksForUrl($url));
    }
}

