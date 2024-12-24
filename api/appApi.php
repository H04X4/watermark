<?php
// Путь к папкам
$inputFolder = 'maskphoto';
$maskFolder = 'downloaded_images';
$outputFolder = 'в';

// Создание папки для сохранения обработанных изображений, если она не существует
if (!file_exists($outputFolder)) {
    mkdir($outputFolder, 0777, true);
}

// Функция для записи сообщений в лог
function logMessage($message, $isError = false) {
    $logFile = 'app.log';
    $messageType = $isError ? 'ERROR' : 'INFO';
    $formattedMessage = date('Y-m-d H:i:s') . " [$messageType] $message\n";
    file_put_contents($logFile, $formattedMessage, FILE_APPEND);
}

// Функция для отправки запроса на API и сохранения результата
function sendInpaintRequest($url, $imagePath, $maskPath, $outputFolder) {
    $ch = curl_init();

    // Настройки CURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Создаем дескрипторы файлов для изображения и маски
    $fileImage = new CURLFile($imagePath, 'image/png', basename($imagePath));
    $fileMask = new CURLFile($maskPath, 'image/png', basename($maskPath));

    $postData = [
        'image' => $fileImage,
        'mask' => $fileMask,
        // Другие параметры
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

    // Выполнение запроса
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode == 200) {
        // Сохранение результата
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

// Обработка всех изображений
foreach (scandir($inputFolder) as $filename) {
    if (preg_match('/\.(png|jpg)$/', $filename)) {
        $imagePath = $inputFolder . DIRECTORY_SEPARATOR . $filename;
        $maskPath = $maskFolder . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($maskPath)) {
            sendInpaintRequest('http://94.247.210.28:5557/inpaint', $imagePath, $maskPath, $outputFolder);
        } else {
            logMessage("Файл маски не найден для изображения: $imagePath", true);
        }
    }
}
