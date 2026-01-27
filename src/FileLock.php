<?php
/**
 * FileLock — простой файловый lock на базе flock
 * Использование:
 *  $lock = FileLock::acquire('incoming_webhook', 5.0);
 *  if (!$lock) { // занято }
 *  try { ... } finally { $lock->release(); }
 */
class FileLock
{
    private $fp;
    private $path;

    private function __construct($fp, string $path)
    {
        $this->fp = $fp;
        $this->path = $path;
    }

    public static function acquire(string $key, float $timeout = 10.0)
    {
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bitrix_hook_' . md5($key) . '.lock';
        $fp = fopen($lockFile, 'c');
        if (!$fp) {
            return false;
        }
        $start = microtime(true);
        do {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                return new self($fp, $lockFile);
            }
            if ((microtime(true) - $start) >= $timeout) {
                fclose($fp);
                return false;
            }
            usleep(100000); // 100ms
        } while (true);
    }

    public function release()
    {
        if (is_resource($this->fp)) {
            flock($this->fp, LOCK_UN);
            fclose($this->fp);
            $this->fp = null;
        }
        // Не удаляем файл явно — это необязательно
    }

    public function __destruct()
    {
        $this->release();
    }
}
