<?php
// Настройки и параметры
$uploadUrl = 'http://94.247.210.28:5556/upload_automatic';
$processUrl = 'http://94.247.210.28:5556/process_automatic';
$getProcessedImageUrl = 'http://94.247.210.28:5556/get_processed_image';
$inpaintUrl = 'http://94.247.210.28:5557/inpaint';

$imageFolder = 'mainphoto';
$downloadFolder = 'downloaded_images';
$outputFolder = 'output';

// Создание папок, если не существуют
foreach ([$downloadFolder, $outputFolder] as $folder) {
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

// Функция для загрузки изображений на сервер
function uploadImage($imagePath, $uploadUrl) {
    $file = new CURLFile($imagePath, 'image/png', basename($imagePath));
    $postData = ['image' => $file];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uploadUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [$httpCode, $response];
}

// Функция для запуска обработки изображений
function processImages($processUrl) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $processUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        return json_decode($response, true);
    } else {
        logMessage("Ошибка при запуске обработки: $httpCode", true);
        return null;
    }
}

// Функция для получения и сохранения обработанных изображений
function getProcessedImage($filename, $getProcessedImageUrl, $downloadFolder) {
    $url = $getProcessedImageUrl . '/' . $filename;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        if (isset($data['image'])) {
            $imageData = base64_decode($data['image']);
            $filePath = $downloadFolder . '/' . $filename;
            file_put_contents($filePath, $imageData);
            logMessage("Изображение {$filename} успешно сохранено в {$filePath}");
        } else {
            logMessage("Ошибка: Изображение не найдено в ответе сервера", true);
        }
    } else {
        logMessage("Ошибка при запросе изображения: $httpCode", true);
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
$imagePaths = glob($imageFolder . '/*.png');
foreach ($imagePaths as $imagePath) {
    if (file_exists($imagePath)) {
        list($httpCode, $response) = uploadImage($imagePath, $uploadUrl);
        if ($httpCode == 200) {
            logMessage("Изображение $imagePath успешно загружено на сервер");
        } else {
            logMessage("Ошибка загрузки изображения $imagePath: $httpCode", true);
        }
    } else {
        logMessage("Файл $imagePath не найден", true);
    }
}

// Запуск обработки изображений
$data = processImages($processUrl);
if ($data && isset($data['processed_images'])) {
    foreach ($data['processed_images'] as $imageData) {
        $filename = $imageData['filename'];

        $rotatedImagePath = $downloadFolder . '/' . 'rotated_' . $filename;
        $maskImagePath = $downloadFolder . '/' . 'mask_' . $filename;

        file_put_contents($rotatedImagePath, base64_decode($imageData['rotated_image']));
        file_put_contents($maskImagePath, base64_decode($imageData['mask_image']));

        logMessage("Изображение {$filename} успешно обработано и сохранено");
    }
} else {
    logMessage("Обработанные изображения не найдены в ответе сервера", true);
}

// Получение и сохранение обработанных изображений
foreach ($imagePaths as $imagePath) {
    $filename = basename($imagePath);
    getProcessedImage($filename, $getProcessedImageUrl, $downloadFolder);
}

// Определяем папку для обработки
$inputFolder = 'mainphoto'; // Добавляем определение переменной

// Обработка всех изображений
foreach (scandir($inputFolder) as $filename) {
    if (preg_match('/\.(png|jpg)$/', $filename)) {
        $imagePath = $inputFolder . DIRECTORY_SEPARATOR . $filename;
        $maskPath = $downloadFolder . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($maskPath)) {
            sendInpaintRequest($inpaintUrl, $imagePath, $maskPath, $outputFolder);
        } else {
            logMessage("Файл маски не найден для изображения: $imagePath", true);
        }
    }
}
?>
