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

    #[Route('/image-compression', name: 'app_image_compress', methods: ['POST'])]
    public function compressDownload(Request $request): Response
    {
        $files = $request->files->get('image');

        // 1. Basic Validations (English)
        if (!$files) {
            return new Response('No file uploaded!', 400);
        }

        if (!is_array($files)) {
            $files = [$files];
        }

        if (count($files) > 10) {
            return new Response('You can upload a maximum of 10 images!', 400);
        }

        // 2. Get Target Size (KB) - Percentage logic removed
        $targetKb = (int)$request->request->get('target_kb', 100);

        if ($targetKb <= 0) {
            return new Response('Invalid target size! Please enter a value greater than 0.', 400);
        }

        // 3. Get Target Format
        $targetFormat = strtolower($request->request->get('target_format', 'webp'));
        $allowedFormats = ['jpeg', 'jpg', 'png', 'webp'];

        if (!in_array($targetFormat, $allowedFormats)) {
            return new Response('Invalid target format!', 400);
        }

        $convertedImages = [];

        // 4. Processing Loop
        foreach ($files as $file) {
            if (!$file->isValid()) {
                continue;
            }

            $mimeType = $file->getMimeType();
            if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'])) {
                continue;
            }

            $imageData = file_get_contents($file->getPathname());
            $image = @imagecreatefromstring($imageData);

            if (!$image) {
                continue;
            }

            // Fix orientation/truecolor issues
            if (!imageistruecolor($image)) {
                $width = imagesx($image);
                $height = imagesy($image);
                $tempImage = imagecreatetruecolor($width, $height);
                imagecopy($tempImage, $image, 0, 0, 0, 0, $width, $height);
                imagedestroy($image);
                $image = $tempImage;
            }

            // Compression Loop Variables
            $quality = 95;
            $minQuality = 10;
            $step = 5;
            $compressedData = null;

            // Loop until file size is <= targetKb OR quality reaches minimum
            do {
                ob_start();
                switch ($targetFormat) {
                    case 'jpeg':
                    case 'jpg':
                        imagejpeg($image, null, $quality);
                        $ext = 'jpg';
                        break;

                    case 'png':
                        // Map 0-100 quality to PNG's 0-9 compression level
                        // (PNG: 0=No compression, 9=Max compression)
                        $pngQuality = (int)round((9 - ($quality / 100 * 9)));
                        $pngQuality = max(0, min(9, $pngQuality));

                        // Preserve transparency
                        imagealphablending($image, false);
                        imagesavealpha($image, true);

                        imagepng($image, null, $pngQuality);
                        $ext = 'png';
                        break;

                    case 'webp':
                        imagealphablending($image, false);
                        imagesavealpha($image, true);
                        imagewebp($image, null, $quality);
                        $ext = 'webp';
                        break;

                    default:
                        ob_end_clean();
                        continue 2;
                }

                $compressedData = ob_get_clean();
                $sizeKb = strlen($compressedData) / 1024;

                if ($sizeKb <= $targetKb || $quality <= $minQuality) {
                    break;
                }

                $quality -= $step;
            } while (true);

            imagedestroy($image);

            if (!$compressedData) {
                continue;
            }

            $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $convertedImages[] = [
                'filename' => $filename . '.' . $ext,
                'data' => $compressedData,
            ];
        }

        // 5. Download Logic (English Filenames)

        if (count($convertedImages) === 0) {
            return new Response('No valid images found to compress!', 400);
        }

        // Single file download
        if (count($convertedImages) === 1) {
            $img = $convertedImages[0];

            return new StreamedResponse(function () use ($img) {
                echo $img['data'];
            }, 200, [
                'Content-Type' => 'image/' . $targetFormat,
                'Content-Disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT .
                    '; filename="' . $img['filename'] . '"',
            ]);
        }

        // Zip file download
        $zipPath = sys_get_temp_dir() . '/compressed_' . uniqid() . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            return new Response('Could not create Zip file!', 500);
        }

        foreach ($convertedImages as $img) {
            $zip->addFromString($img['filename'], $img['data']);
        }

        $zip->close();

        return new BinaryFileResponse($zipPath, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT .
                '; filename="compressed_images_' . (new \DateTime())->format('Y-m-d_H-i-s') . '.zip"',
        ]);
    }

    #[Route('/image-compression', name: 'app_image_compress_form', methods: ['GET'])]
    public function showCompressForm(): Response
    {
        return $this->render('image/compress.html.twig');
    }


    #[Route('/image-conversion', name: 'app_image_convert', methods: ['GET', 'POST'])]
    public function convert(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->render('image/convert.html.twig');
        }

        $files = $request->files->get('images');
        $targetFormat = strtolower($request->request->get('format') ?? '');

        if (!$files || !is_array($files) || !in_array($targetFormat, ['webp', 'jpeg', 'jpg', 'png'])) {
            return new Response('Invalid file(s) or format!', 400);
        }

        $convertedImages = [];

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
                    ob_end_clean();
                    continue 2;
            }

            $convertedData = ob_get_clean();
            imagedestroy($image);

            if ($convertedData) {
                $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.' . $extension;
                $tempPath = sys_get_temp_dir() . '/' . uniqid('converted_', true) . '.' . $extension;
                file_put_contents($tempPath, $convertedData);
                $convertedImages[] = [
                    'path' => $tempPath,
                    'name' => $filename
                ];
            }
        }

        if (count($convertedImages) === 0) {
            return new Response('No files could be converted!', 400);
        }

        if (count($convertedImages) === 1) {
            $image = $convertedImages[0];
            return $this->file($image['path'], $image['name']);
        }

        $zipPath = sys_get_temp_dir() . '/converted_images_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            return new Response('Could not create ZIP file!', 500);
        }

        foreach ($convertedImages as $image) {
            $zip->addFile($image['path'], $image['name']);
        }

        $zip->close();

        return new BinaryFileResponse($zipPath, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT .
                '; filename="converted_images_' . (new \DateTime())->format('Y-m-d_H.i.s') . '.zip"',
        ]);
    }

    #[Route('/image-crop', name: 'app_image_crop', methods: ['GET', 'POST'])]
    public function crop(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->render('image/crop.html.twig');
        }

        $base64Data = $request->request->get('croppedImage');

        if (!$base64Data || !preg_match('#^data:image/(\w+);base64,#i', $base64Data, $matches)) {
            return new Response('Invalid image data!', 400);
        }

        $extension = strtolower($matches[1]);
        $validExtensions = ['jpeg', 'jpg', 'png', 'webp'];

        if (!in_array($extension, $validExtensions)) {
            return new Response("Unsupported format: .$extension", 400);
        }

        $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Data));

        if (!$imageData) {
            return new Response('Base64 decode error!', 400);
        }

        $image = \imagecreatefromstring($imageData);
        if (!$image) {
            return new Response('Image could not be created!', 400);
        }

        $imageName = 'cropped_' . uniqid() . '.' . $extension;

        $response = new StreamedResponse(function () use ($image, $extension) {
            switch ($extension) {
                case 'png':
                    imagepng($image, null, 0);
                    break;
                case 'jpeg':
                case 'jpg':
                    imagejpeg($image, null, 100);
                    break;
                case 'webp':
                    imagewebp($image, null, 100);
                    break;
            }

            imagedestroy($image);
        });

        $contentType = match ($extension) {
            'png' => 'image/png',
            'jpeg', 'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        $response->headers->set('Content-Type', $contentType);
        $response->headers->set(
            'Content-Disposition',
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $imageName
        );

        return $response;
    }

    #[Route('/character-counter', name: 'app_character_counter')]
    public function counter(): Response
    {
        return $this->render('image/counter.html.twig');
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
}
