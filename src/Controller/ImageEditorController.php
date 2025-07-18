<?php

namespace App\Controller;

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
    #[Route('/compress', name: 'app_image_compress', methods: ['POST'])]
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

        $targetKb = (int) $request->request->get('target_kb', 100);

        if (!$files || !is_array($files) || $targetKb <= 0) {
            return new Response('Geçersiz dosyalar veya hedef boyut!', 400);
        }

        $zip = new \ZipArchive();
        $zipPath = sys_get_temp_dir() . '/compressed_images_' . uniqid() . '.zip';

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            return new Response('Zip dosyası oluşturulamadı!', 500);
        }

        foreach ($files as $index => $file) {
            if (!$file->isValid()) continue;

            $imageData = file_get_contents($file->getPathname());
            $image = @imagecreatefromstring($imageData);
            if (!$image) continue;

            $maxWidth = 1024;
            $maxHeight = 1024;

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width > $maxWidth || $height > $maxHeight) {
                $ratio = min($maxWidth / $width, $maxHeight / $height);
                $newWidth = (int)($width * $ratio);
                $newHeight = (int)($height * $ratio);

                $newImage = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $newImage;
            }

            $quality = 95;
            $minQuality = 10;
            $step = 1;
            do {
                ob_start();
                imagejpeg($image, null, $quality);
                $compressedData = ob_get_clean();

                $sizeKb = strlen($compressedData) / 1024;

                if ($sizeKb <= $targetKb || $quality <= $minQuality) {
                    break;
                }

                $quality -= $step;
            } while (true);

            imagedestroy($image);

            $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $zip->addFromString($filename . '.jpg', $compressedData);
        }

        $zip->close();

        $zipContents = file_get_contents($zipPath);
        unlink($zipPath); // geçici dosyayı sil

        $response = new Response($zipContents);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                'compressed_images.zip'
            )
        );
        $response->headers->set('Content-Length', strlen($zipContents));

        return $response;
    }


    #[Route('/compress', name: 'app_image_compress_form', methods: ['GET'])]
    public function showCompressForm(): Response
    {
        return $this->render('image/compress.html.twig');
    }

    #[Route('/download/{filename}', name: 'app_image_download')]
    public function download(string $filename): BinaryFileResponse
    {
        $filePath = $this->getParameter('kernel.project_dir') . '/public/uploads/tmp/' . $filename;

        return (new BinaryFileResponse($filePath))
            ->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename
            );
    }


    #[Route('/convert', name: 'app_image_convert', methods: ['GET', 'POST'])]
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

        $zipPath = sys_get_temp_dir() . '/converted_' . uniqid() . '.zip';
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
            'Content-Disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT . '; filename="converted_images.zip"',
        ]);
    }



}
