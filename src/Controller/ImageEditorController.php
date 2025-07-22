<?php

namespace App\Controller;

use DateTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use ZipArchive;

final class ImageEditorController extends AbstractController
{
    #[Route('/' , name: 'app_home')]
    public function index(): Response{
        return $this->render('base.html.twig');
    }

    #[Route('/goruntu-sikistirma', name: 'app_image_compress', methods: [ 'POST'])]
    public function compressDownload
    (
        Request $request
    ): Response
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

        $resizeType = $request->request->get('resize_type', 'pixels');

        if ($resizeType === 'pixels') {
            $targetKb = (int) $request->request->get('target_kb', 100);
            if ($targetKb <= 0) {
                return new Response('Geçersiz hedef boyut!', 400);
            }
        } else {
            $compressionRatio = (int) $request->request->get('compression_ratio', 90);
            if ($compressionRatio <= 0 || $compressionRatio > 100) {
                return new Response('Geçersiz sıkıştırma oranı!', 400);
            }
            $targetKb = null;
        }

        if ($resizeType === 'pixels' && $targetKb <= 0) {
            return new Response('Geçersiz hedef boyut!', 400);
        }
        if ($resizeType === 'percentage' && ($compressionRatio <= 0 || $compressionRatio > 100)) {
            return new Response('Geçersiz sıkıştırma oranı!', 400);
        }

        $firstMime = $files[0]->getMimeType();
        $formatMap = [
            'image/jpeg' => 'jpeg',
            'image/jpg'  => 'jpeg',
            'image/webp' => 'webp',
        ];
        $targetFormat = $formatMap[$firstMime] ?? null;

        if (!$targetFormat) {
            return new Response('İlk dosya desteklenmeyen formatta!', 400);
        }

        $zip = new \ZipArchive();
        $zipPath = sys_get_temp_dir() . '/compressed_' . uniqid() . '.zip';
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            return new Response('Zip dosyası oluşturulamadı!', 500);
        }

        foreach ($files as $file) {
            if (!$file->isValid()) continue;

            $imageData = file_get_contents($file->getPathname());
            $image = @imagecreatefromstring($imageData);
            if (!$image) continue;

            $width = imagesx($image);
            $height = imagesy($image);

            $tempImage = imagecreatetruecolor($width, $height);


            imagecopy($tempImage, $image, 0, 0, 0, 0, $width, $height);
            imagedestroy($image);
            $image = $tempImage;

            $originalSizeKb = filesize($file->getPathname()) / 1024;
            $targetSizeKb = $targetKb ?? ($originalSizeKb * ($compressionRatio / 100));

            $quality = 95;
            $minQuality = 10;
            $step = 3;

            do {
                ob_start();
                switch ($targetFormat) {
                    case 'jpeg':
                    case 'jpg':
                        imagejpeg($image, null, $quality);
                        $ext = 'jpg';
                        break;
                    case 'webp':
                        imagewebp($image, null, $quality);
                        $ext = 'webp';
                        break;
                    default:
                        continue 3;
                }
                $compressedData = ob_get_clean();

                $sizeKb = strlen($compressedData) / 1024;

                if ($sizeKb <= $targetSizeKb || $quality <= $minQuality) {
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
                '; filename="converted_compressed_' . (new \DateTime())->format('Y-m-d_H.i.s') . '.zip"',
        ]);
    }


    #[Route('/goruntu-sikistirma', name: 'app_image_compress_form', methods: ['GET'])]
    public function showCompressForm(): Response
    {
        return $this->render('image/compress.html.twig');
    }

    #[Route('/goruntu-donusturme', name: 'app_image_convert', methods: ['GET', 'POST'])]
    public function convert
    (
        Request $request
    ): Response
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
                    $width = imagesx($image);
                    $height = imagesy($image);

                    $tempImage = imagecreatetruecolor($width, $height);
                    imagecopy($tempImage, $image, 0, 0, 0, 0, $width, $height);
                    imagedestroy($image);
                    $image = $tempImage;

                    imagewebp($image, null, 85);
                    $extension = 'webp';
                    break;

                case 'jpeg':
                case 'jpg':
                    $width = imagesx($image);
                    $height = imagesy($image);

                    $tempImage = imagecreatetruecolor($width, $height);
                    $white = imagecolorallocate($tempImage, 255, 255, 255);
                    imagefill($tempImage, 0, 0, $white);
                    imagecopy($tempImage, $image, 0, 0, 0, 0, $width, $height);
                    imagedestroy($image);
                    $image = $tempImage;

                    imagejpeg($image, null, 90);
                    $extension = 'jpg';
                    break;

                case 'png':
                    $width = imagesx($image);
                    $height = imagesy($image);

                    $tempImage = imagecreatetruecolor($width, $height);
                    $white = imagecolorallocate($tempImage, 255, 255, 255);
                    imagefill($tempImage, 0, 0, $white);
                    imagecopy($tempImage, $image, 0, 0, 0, 0, $width, $height);
                    imagedestroy($image);
                    $image = $tempImage;

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

//    #[Route('/arkaplan-kaldırma' , name:'app_remove_background', methods: ['GET' , 'POST'])]
//    public function removeBackground(Request $request): Response{
//        if ($request->isMethod('GET')) {
//            return $this->render('image/remove_background.html.twig');
//        }
//        $files = $request->files->get('images');
//        $targetFormat = strtolower($request->request->get('format') ?? '');
//
//        if (!$files || !is_array($files) || !in_array($targetFormat, ['webp', 'jpeg', 'jpg', 'png'])) {
//            return new Response('Geçersiz dosya(lar) veya format!', 400);
//        }
//
//        $zipPath = sys_get_temp_dir() . '/crop-kalitehost_converted'. uniqid() . '.zip';
//        $zip = new ZipArchive();
//        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
//            return new Response('ZIP dosyası oluşturulamadı!', 500);
//        }
//        foreach ($files as $file) {
//            $imageData = file_get_contents($file->getPathname());
//
//
//        }
//        return $this->render('image/remove_background.html.twig');
//    }

    #[Route('/goruntu-kirpma', name: 'app_image_crop', methods: ['GET', 'POST'])]
    public function crop(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->render('image/crop.html.twig');
        }

        $base64Data = $request->request->get('croppedImage');

        if (!$base64Data || strpos($base64Data, 'data:image') !== 0) {
            return new Response('Geçersiz veri!', 400);
        }

        $base64Data = preg_replace('#^data:image/\w+;base64,#i', '', $base64Data);
        $imageData = base64_decode($base64Data);

        if (!$imageData) {
            return new Response('Base64 decode hatası!', 400);
        }

        $imageName = 'cropped_' . uniqid() . '.jpg';

        $response = new StreamedResponse(function() use ($imageData) {
            echo $imageData;
        });

        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('Content-Disposition', ResponseHeaderBag::DISPOSITION_ATTACHMENT, $imageName);

        return $response;
    }
}
