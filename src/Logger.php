<?php
/**
 * Logger
 * ------
 * Простой файловый логгер без зависимостей.
 * Используется во всём проекте.
 */

class Logger
{
    /** @var string тип логирования: 'file' или 'syslog' */
    private string $type;

    /** @var string путь к файлу лога */
    private string $file;

    /** @var int минимальный уровень логирования */
    private int $level;

    /** @var int|null текущий activityId для включения в контекст логов */
    private ?int $activityId = null;
    /**
     * Соответствие текстового уровня числовому
     * Чем больше число — тем выше важность
     */
    private const LEVELS = [
        'DEBUG' => 0,
        'INFO'  => 1,
        'WARNING' => 1,
        'ERROR' => 2,
    ];

    /**
     * Соответствие уровней для syslog
     */
    private const SYSLOG_LEVELS = [
        'DEBUG' => LOG_DEBUG,
        'INFO'  => LOG_INFO,
        'WARNING' => LOG_WARNING,
        'ERROR' => LOG_ERR,
    ];

    /**
     * @param array $config ['type' => string, 'file' => string, 'level' => string]
     */
    public function __construct(array $config)
    {
        $this->type  = $config['type'] ?? 'file';
        $this->file  = $config['file'] ?? __DIR__ . '/../logs/default.log';
        $this->level = self::LEVELS[$config['level']] ?? 0;

        if ($this->type === 'syslog') {
            openlog('email-router', LOG_PID, LOG_LOCAL0);
        }
    }

    public function __destruct()
    {
        if ($this->type === 'syslog') {
            closelog();
        }
    }

    /** Лог уровня DEBUG */
    public function debug(string $message, array $context = [])
    {
        $this->log('DEBUG', $message, $context);
    }

    /** Лог уровня WARNING */
    public function warning(string $message, array $context = [])
    {
        $this->log('WARNING', $message, $context);
    }

    /** Лог уровня INFO */
    public function info(string $message, array $context = [])
    {
        $this->log('INFO', $message, $context);
    }

    /** Лог уровня ERROR */
    public function error(string $message, array $context = [])
    {
        $this->log('ERROR', $message, $context);
    }

    /** Установить activityId — будет автоматически добавляться во все логи */
    public function setActivityId(?int $id): void
    {
        $this->activityId = $id;
    }

    /**
     * Основной метод логирования
     */
    private function log(string $level, string $message, array $context)
    {
        // Если уровень сообщения ниже минимального — пропускаем
        if (self::LEVELS[$level] < $this->level) {
            return;
        }

        if (!array_key_exists('activityId', $context)) {
            $context['activityId'] = $this->activityId;
        }

        $line = sprintf(
            "[%s][%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );

        if ($this->type === 'syslog') {
            syslog(self::SYSLOG_LEVELS[$level], trim($line));
        } else {
            file_put_contents($this->file, $line, FILE_APPEND);
        }
    }
}
