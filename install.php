<?php
// Gelişmiş Gereksinim Kontrolü
$requirements = [
    'PHP Sürümü (Min 8.2)' => version_compare(phpversion(), '8.2.0', '>='),
    'cURL Eklentisi (Site Taraması İçin)' => extension_loaded('curl'),
    'ZIP Eklentisi (Excel İşlemleri İçin)' => extension_loaded('zip'),
    'GD Eklentisi (Görsel İşlemleri İçin)' => extension_loaded('gd'),
    'XML/DOM Eklentisi (Site Analizi İçin)' => extension_loaded('xml') || extension_loaded('dom'),
];

$allOK = true;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Kurulum Kontrolü</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">Sunucu Gereksinim Kontrolü</h4>
        </div>
        <div class="card-body">
            <ul class="list-group">
                <?php foreach ($requirements as $name => $status): ?>
                    <?php if (!$status) $allOK = false; ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <?php echo $name; ?>
                        <?php if ($status): ?>
                            <span class="badge bg-success rounded-pill">Tamam</span>
                        <?php else: ?>
                            <span class="badge bg-danger rounded-pill">Eksik!</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="mt-4 text-center">
                <?php if ($allOK): ?>
                    <div class="alert alert-success">Sunucunuz bu yazılımı çalıştırmak için uygun!</div>
                    <a href="index.php" class="btn btn-success btn-lg">Uygulamayı Başlat</a>
                <?php else: ?>
                    <div class="alert alert-danger">Lütfen eksik olan eklentileri hosting firmanıza iletiniz.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
