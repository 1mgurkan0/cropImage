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

    #[Route('/api/seo/get-urls', name: 'api_get_urls', methods: ['POST'])]
    public function getUrls(Request $request, SeoAnalyzerService $seoAnalyzer): JsonResponse
    {
        $sitemapUrl = $request->request->get('sitemap_url');
        if (!filter_var($sitemapUrl, FILTER_VALIDATE_URL)) {
            return $this->json(['success' => false, 'message' => 'Geçersiz Sitemap URL\'si.']);
        }

        $urls = $seoAnalyzer->getUrlsFromSitemap($sitemapUrl);

        if (!$urls) {
            return $this->json(['success' => false, 'message' => 'Sitemap bulunamadı, okunamadı veya içinde URL yok.']);
        }

        return $this->json(['success' => true, 'urls' => $urls]);
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
}

