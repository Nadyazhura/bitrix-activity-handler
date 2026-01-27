<?php
/**
 * index.php
 * ---------
 * Точка входа для webhook Bitrix24
 */
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach(file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

// --- SECURITY CHECK ---
$secret = $_GET['secret'] ?? '';
if(!hash_equals(getenv('WEBHOOK_SECRET') ?: '', $secret)) {
    http_response_code(403);
    echo 'Forbidden WEBHOOK_SECRET';
    exit;
}

$appToken = $_REQUEST['auth']['application_token'] ?? '';
if (!hash_equals(getenv('BITRIX_APP_TOKEN') ?: '', $appToken)) {
    http_response_code(403);
    echo 'Forbidden BITRIX_APP_TOKEN';
    exit;
}


$config = require __DIR__ . '/config/config.php';

// Подключаем классы
require __DIR__ . '/src/Logger.php';
require __DIR__ . '/src/BitrixClient.php';
require __DIR__ . '/src/ActivityService.php';
require __DIR__ . '/src/DealService.php';
require __DIR__ . '/src/LeadService.php';
require __DIR__ . '/src/EntityMatcher.php';
require __DIR__ . '/src/EmailRouter.php';
require __DIR__ . '/src/ContactService.php';

// Инициализация логгера
$logger = new Logger($config['log']);  
$logger->info('=====> Новая активность',[]);


try {
    // Инициализация остальных компонентов
    $bx     = new BitrixClient($config['bitrix']['webhook_url'], $logger);

    $router = new EmailRouter(
        $logger,
        new ActivityService($bx, $logger),
        new DealService($bx, $logger),
        new LeadService($bx, $logger),
        new ContactService($bx)
    );

    // ID активности приходит от Bitrix
    $activityId = $_POST['data']['FIELDS']['ID'] ?? null;

    if (!$activityId) {
        $logger->error('Нет ID активити в вебхуке');
        http_response_code(400);
        echo 'Bad Request';
        exit;
    }

    // Запуск обработки
    $router->handle((int)$activityId);

    http_response_code(200);
    echo 'OK';

} catch (Exception $e) {
    $logger->error('Unhandled exception in index.php', [
        'exception' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    echo 'Internal Server Error';
}
