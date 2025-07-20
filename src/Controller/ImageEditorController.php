<?php

namespace App\Controller;

use DateTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use ZipArchive;

final class ImageEditorController extends AbstractController
{
    #[Route('/' , name: 'app_home')]
    public function index(): Response{
        return $this->render('base.html.twig');
    }

    #[Route('/goruntu-sikistirma', name: 'app_image_compress', methods: [ 'POST'])]
    public function compressDownload(Request $request): Response
    {
        $files = $request->files->get('image');

        if (!$files) {
            return new Response('Hiç dosya yüklenmedi!', 400);
        }

        if (!is_array($files)) {
            $files = [$files];
        }

        if (count($files) > 10) {
            return new Response('En fazla 10 görsel yükleyebilirsiniz!', 400);
        }

        $compressionRatio = (int) $request->request->get('compression_ratio', 0);
        $baseTargetKb = (int) $request->request->get('target_kb', 100);

        if ($baseTargetKb <= 0 && ($compressionRatio <= 0 || $compressionRatio > 100)) {
            return new Response('Geçersiz hedef boyut veya sıkıştırma oranı!', 400);
        }

        $zip = new \ZipArchive();
        $zipPath = sys_get_temp_dir() . '/compressed_' . uniqid() . '.zip';

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            return new Response('Zip dosyası oluşturulamadı!', 500);
        }

        foreach ($files as $file) {
            if (!$file->isValid()) continue;

            $mimeType = $file->getMimeType();
            $imageData = file_get_contents($file->getPathname());

            switch ($mimeType) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($file->getPathname());
                    $ext = 'jpg';
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($file->getPathname());
                    $ext = 'png';
                    break;
                case 'image/webp':
                    $image = imagecreatefromwebp($file->getPathname());
                    $ext = 'webp';
                    break;
                default:
                    continue 2;
            }

            if (!$image) continue;

            $maxWidth = 1024;
            $maxHeight = 1024;
            $width = imagesx($image);
            $height = imagesy($image);

            if ($width > $maxWidth || $height > $maxHeight) {
                $ratio = min($maxWidth / $width, $maxHeight / $height);
                $newWidth = (int)($width * $ratio);
                $newHeight = (int)($height * $ratio);

                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $resized;
            }

            $originalSizeKb = filesize($file->getPathname()) / 1024;
            $targetKb = $compressionRatio > 0 ? $originalSizeKb * ($compressionRatio / 100) : $baseTargetKb;

            $quality = 95;
            $minQuality = 10;
            $step = 3;

            do {
                ob_start();
                if ($ext === 'jpg') {
                    imagejpeg($image, null, $quality);
                } elseif ($ext === 'png') {
                    $pngCompression = 9 - floor(($quality / 100) * 9);
                    imagepng($image, null, $pngCompression);
                } elseif ($ext === 'webp') {
                    imagewebp($image, null, $quality);
                }
                $compressedData = ob_get_clean();

                $sizeKb = strlen($compressedData) / 1024;

                if ($sizeKb <= $targetKb || $quality <= $minQuality) {
                    break;
                }

                $quality -= $step;
            } while (true);

            imagedestroy($image);

            $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $zip->addFromString($filename . '.' . $ext, $compressedData);
        }

        $zip->close();

        return new Response(file_get_contents($zipPath), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT .
                '; filename="converted_images_' . (new \DateTime())->format('Y-m-d_H.i.s') . '.zip"',

        ]);
    }


    #[Route('/goruntu-sikistirma', name: 'app_image_compress_form', methods: ['GET'])]
    public function showCompressForm(): Response
    {
        return $this->render('image/compress.html.twig');
    }

    #[Route('/goruntu-donusturme', name: 'app_image_convert', methods: ['GET', 'POST'])]
    public function convert(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->render('image/convert.html.twig');
        }

        $files = $request->files->get('images');
        $targetFormat = strtolower($request->request->get('format') ?? '');

        if (!$files || !is_array($files) || !in_array($targetFormat, ['webp', 'jpeg', 'jpg', 'png'])) {
            return new Response('Geçersiz dosya(lar) veya format!', 400);
        }

        $zipPath = sys_get_temp_dir() . '/crop-kalitehost_converted'. uniqid() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            return new Response('ZIP dosyası oluşturulamadı!', 500);
        }

        foreach ($files as $file) {
            $imageData = file_get_contents($file->getPathname());
            $image = @imagecreatefromstring($imageData);
            if (!$image) {
                continue;
            }

            ob_start();
            switch ($targetFormat) {
                case 'webp':
                    imagewebp($image, null, 85);
                    $extension = 'webp';
                    break;
                case 'jpeg':
                case 'jpg':
                    imagejpeg($image, null, 90);
                    $extension = 'jpg';
                    break;
                case 'png':
                    imagepng($image, null, 6);
                    $extension = 'png';
                    break;
                default:
                    continue 2;
            }

            $convertedData = ob_get_clean();
            imagedestroy($image);

            if ($convertedData) {
                $filenameInZip = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.' . $extension;
                $zip->addFromString($filenameInZip, $convertedData);
            }
        }

        $zip->close();

        return new BinaryFileResponse($zipPath, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT .
                '; filename="converted_images_' . (new \DateTime())->format('Y-m-d_H.i.s') . '.zip"',
        ]);
    }



}
