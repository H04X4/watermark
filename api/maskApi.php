<?php
// Настройки и параметры
$baseUrl = 'http://94.247.210.28:5558';  // URL вашего Flask-приложения для загрузки и обработки изображений
$inpaintUrl = 'http://94.247.210.28:5557/inpaint'; // URL API для inpaint

$inputDir = 'maskphoto';        // Директория с изображениями для обработки
$outputDir = 'downloaded_images'; // Директория для сохранения обработанных изображений
$finalOutputDir = 'processed_images'; // Директория для финальных изображений после inpaint

// Создание папок, если не существуют
foreach ([$outputDir, $finalOutputDir] as $folder) {
    if (!file_exists($folder)) {
        mkdir($folder, 0777, true);
    }
}

// Функция для записи сообщений в лог
function logMessage($message, $isError = false) {
    $logFile = 'app.log';
    $messageType = $isError ? 'ERROR' : 'INFO';
    $formattedMessage = date('Y-m-d H:i:s') . " [$messageType] $message\n";
    file_put_contents($logFile, $formattedMessage, FILE_APPEND);
}

// Функция для загрузки изображения
function uploadImage($imagePath, $baseUrl) {
    $url = $baseUrl . '/upload_manual';
    $file = new CURLFile($imagePath, 'image/png', basename($imagePath));
    $postData = ['image' => $file];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode == 200) {
        logMessage("Изображение $imagePath успешно загружено");
    } else {
        logMessage("Ошибка загрузки изображения $imagePath: $response", true);
    }
}

// Функция для обработки изображений
function processImages($baseUrl) {
    $url = $baseUrl . '/process_manual';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode == 200) {
        logMessage("Обработка изображений успешно завершена");
    } else {
        logMessage("Ошибка обработки изображений: $response", true);
    }
}

// Функция для получения и сохранения обработанного изображения
function downloadProcessedImage($filename, $baseUrl, $outputDir) {
    $url = $baseUrl . '/get_processed_image/' . urlencode($filename);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode == 200) {
        $data = json_decode($response, true);
        if (isset($data['image'])) {
            $imgData = base64_decode($data['image']);
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;
            file_put_contents($outputPath, $imgData);
            logMessage("Обработанное изображение $filename успешно загружено");
        } else {
            logMessage("Ошибка: обработанное изображение не найдено в ответе", true);
        }
    } else {
        logMessage("Ошибка получения обработанного изображения: $response", true);
    }
}

// Функция для отправки запроса на API и сохранения результата
function sendInpaintRequest($url, $imagePath, $maskPath, $outputFolder) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $fileImage = new CURLFile($imagePath, 'image/png', basename($imagePath));
    $fileMask = new CURLFile($maskPath, 'image/png', basename($maskPath));

    $postData = [
        'image' => $fileImage,
        'mask' => $fileMask,
        'ldmSteps' => '25',
        'ldmSampler' => 'plms',
        'zitsWireframe' => 'true',
        'hdStrategy' => 'Original',
        'hdStrategyCropMargin' => '196',
        'hdStrategyCropTrigerSize' => '800',
        'hdStrategyResizeLimit' => '2048',
        'prompt' => '',
        'negativePrompt' => '',
        'croperX' => '256',
        'croperY' => '128',
        'croperHeight' => '512',
        'croperWidth' => '512',
        'useCroper' => 'false',
        'sdMaskBlur' => '5',
        'sdStrength' => '0.75',
        'sdSteps' => '50',
        'sdGuidanceScale' => '7.5',
        'sdSampler' => 'ddim',
        'sdSeed' => '-1',
        'sdMatchHistograms' => 'false',
        'sdScale' => '1',
        'cv2Radius' => '5',
        'cv2Flag' => 'INPAINT_NS',
        'paintByExampleSteps' => '50',
        'paintByExampleGuidanceScale' => '7.5',
        'paintByExampleSeed' => '-1',
        'paintByExampleMaskBlur' => '5',
        'paintByExampleMatchHistograms' => 'false',
        'p2pSteps' => '50',
        'p2pImageGuidanceScale' => '1.5',
        'p2pGuidanceScale' => '7.5',
        'controlnet_conditioning_scale' => '0.4',
        'controlnet_method' => 'control_v11p_sd15_canny'
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode == 200) {
        $outputPath = $outputFolder . DIRECTORY_SEPARATOR . basename($imagePath);
        if (file_put_contents($outputPath, $response) !== false) {
            logMessage("Изображение успешно сохранено как '$outputPath'");
        } else {
            logMessage("Ошибка записи файла в '$outputPath'", true);
        }
    } else {
        logMessage("Ошибка загрузки изображения: " . curl_error($ch), true);
    }

    curl_close($ch);
}

// Загрузка изображений на сервер
$pngFiles = array_filter(scandir($inputDir), function($file) {
    return pathinfo($file, PATHINFO_EXTENSION) === 'png';
});

if (empty($pngFiles)) {
    logMessage("В директории $inputDir нет файлов с расширением .png.", true);
    exit(1);
}

foreach ($pngFiles as $pngFile) {
    $imagePath = $inputDir . DIRECTORY_SEPARATOR . $pngFile;
    uploadImage($imagePath, $baseUrl);
}

// После загрузки всех изображений выполняем их обработку
processImages($baseUrl);

// После обработки всех изображений загружаем их
foreach ($pngFiles as $pngFile) {
    downloadProcessedImage($pngFile, $baseUrl, $outputDir);
}

// Обработка всех изображений с использованием масок
foreach (scandir($outputDir) as $filename) {
    if (preg_match('/\.(png|jpg)$/', $filename)) {
        $imagePath = $outputDir . DIRECTORY_SEPARATOR . $filename;
        $maskPath = $outputDir . DIRECTORY_SEPARATOR . $filename; // Здесь предполагается, что маска сохранена с тем же именем

        if (file_exists($maskPath)) {
            sendInpaintRequest($inpaintUrl, $imagePath, $maskPath, $finalOutputDir);
        } else {
            logMessage("Файл маски не найден для изображения: $imagePath", true);
        }
    }
}
?>
