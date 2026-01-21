<?php
/**
 * Общий конфиг сервиса
 * -------------------
 * Здесь НЕ должно быть логики, только настройки.
 */

return [

    // Настройки подключения к Bitrix24
    'bitrix' => [
        // Исходящий вебхук Bitrix24
        // Пример:
        // https://example.bitrix24.ru/rest/1/xxxxxxxxx/
        'webhook_url' => "your webhook address",
    ],

    // Настройки логирования
    'log' => [
        // Тип логирования: 'file' или 'syslog'
        'type'  => 'file',

        // Путь к файлу логов (если type = 'file')
        'file'  => __DIR__ . '/../logs/email-router.log',

        // Уровень логирования:
        // DEBUG — всё
        // INFO  — бизнес-события
        // ERROR — только ошибки
        'level' => 'DEBUG',
    ],
];
