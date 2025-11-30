<?php

/**
 * Сервіс для блокування ботів
 *
 * Відповідає за логіку виявлення та блокування небажаних ботів,
 * а також за керування налаштуваннями та логами блокувань.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../engine/core/support/helpers/DatabaseHelper.php';
require_once __DIR__ . '/../../../../engine/infrastructure/persistence/DatabaseInterface.php';

// Підключаємо functions.php для доступу до TimezoneManager та helper функцій
$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 4);
$functionsFile = $rootDir . '/engine/core/support/functions.php';
if (file_exists($functionsFile)) {
    require_once $functionsFile;
}

// Підключаємо Logger для логування
if (!class_exists('Logger')) {
    $loggerFile = $rootDir . '/engine/infrastructure/logging/Logger.php';
    if (file_exists($loggerFile)) {
        require_once $loggerFile;
    }
}

class BotBlockerService
{
    private ?DatabaseInterface $db = null;
    private string $pluginSlug;
    private array $botPatterns = [];
    private array $allowedBots = [];
    private bool $blockEnabled = false; // За замовчуванням вимкнено, буде завантажено з БД
    
    // Статична змінна класу для відстеження вже заблокованих запитів в поточному запиті
    private static array $blockedRequests = [];

    public function __construct(string $pluginSlug = 'bot-blocker')
    {
        $this->pluginSlug = $pluginSlug;
        try {
            $this->db = DatabaseHelper::getInstance();
        } catch (\Exception $e) {
            logger()->logError('BotBlockerService: Помилка з\'єднання з базою даних - ' . $e->getMessage(), ['exception' => $e]);
        }

        $this->loadSettings();
        $this->initializeBotPatterns();
    }

    /**
     * Завантаження налаштувань плагіна з бази даних
     */
    public function loadSettings(): void
    {
        if (!$this->db) {
            return;
        }

        try {
            // Очищаємо кеш налаштувань плагіна перед завантаженням
            if (function_exists('cache_forget')) {
                cache_forget('plugin_settings_' . $this->pluginSlug);
            }
            
            // Завантажуємо налаштування з таблиці plugin_settings
            // Використовуємо ORDER BY для гарантії порядку
            $rows = $this->db->getAll(
                'SELECT setting_key, setting_value FROM plugin_settings WHERE plugin_slug = ? ORDER BY setting_key',
                [$this->pluginSlug]
            );
            
            // Перетворюємо результат в асоціативний масив
            $settings = [];
            foreach ($rows as $row) {
                if (isset($row['setting_key']) && isset($row['setting_value'])) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            }

            // Отримуємо значення block_enabled з БД
            $blockEnabledValue = $settings['block_enabled'] ?? '0';
            
            // Нормалізуємо значення в рядок та обрізаємо пробіли
            if (is_string($blockEnabledValue)) {
                $blockEnabledValue = trim($blockEnabledValue);
            } elseif (is_bool($blockEnabledValue)) {
                $blockEnabledValue = $blockEnabledValue ? '1' : '0';
            } elseif (is_int($blockEnabledValue)) {
                $blockEnabledValue = (string)$blockEnabledValue;
            } elseif (is_null($blockEnabledValue)) {
                $blockEnabledValue = '0';
            } else {
                $blockEnabledValue = '0';
            }
            
            // Перетворюємо в boolean для внутрішнього використання
            // Використовуємо строге порівняння з рядком '1'
            $this->blockEnabled = ($blockEnabledValue === '1' || $blockEnabledValue === 1 || $blockEnabledValue === true);
            
            // Завантажуємо дозволених ботів
            $this->allowedBots = [];
            if (!empty($settings['allowed_bots'])) {
                $decoded = json_decode($settings['allowed_bots'], true);
                if (is_array($decoded)) {
                    $this->allowedBots = $decoded;
                }
            }
        } catch (\Exception $e) {
            // Таблиця може не існувати, використовуємо налаштування за замовчуванням
            logger()->logError('BotBlockerService: Помилка завантаження налаштувань - ' . $e->getMessage(), ['exception' => $e]);
            $this->blockEnabled = false;
            $this->allowedBots = [];
        }
    }

    /**
     * Отримання часового поясу з БД через TimezoneManager
     * 
     * Використовує TimezoneManager з ядра, який завантажує timezone з БД
     * 
     * @return string Часовий пояс (наприклад, "Europe/Kyiv")
     */
    private function getTimezoneFromSettings(): string
    {
        // Використовуємо TimezoneManager з ядра, який завантажує timezone з БД
        if (function_exists('getTimezoneFromDatabase')) {
            return getTimezoneFromDatabase();
        }
        
        // Fallback на системний timezone, якщо функція недоступна
        return date_default_timezone_get() ?: 'Europe/Kyiv';
    }

    /**
     * Перевіряє існування таблиці логів блокувань і створює її, якщо вона відсутня.
     *
     * @return bool True, якщо таблиця існує або була успішно створена, false у разі помилки.
     */
    private function ensureTableExists(): bool
    {
        if (!$this->db) {
            return false;
        }

        try {
            // Перевіряємо існування таблиці через DatabaseHelper
            if (DatabaseHelper::tableExists('bot_blocker_logs')) {
                return true; // Таблиця існує
            }

            // Створюємо таблицю, якщо її немає
            $this->db->execute('
                CREATE TABLE IF NOT EXISTS bot_blocker_logs (
                    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    ip_address VARCHAR(45) NOT NULL,
                    user_agent TEXT,
                    url VARCHAR(500),
                    blocked_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    KEY idx_blocked_at (blocked_at),
                    KEY idx_ip_address (ip_address)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            return true;
        } catch (\Exception $e) {
            logger()->logError('BotBlockerService: Не вдалося створити таблицю bot_blocker_logs - ' . $e->getMessage(), ['exception' => $e]);
            return false;
        }
    }

    /**
     * Ініціалізує патерни User-Agent для виявлення ботів.
     */
    private function initializeBotPatterns(): void
    {
        $this->botPatterns = [
            // Пошукові боти (дозволені за замовчуванням, якщо не вказано інше)
            'googlebot' => false, // Google - дозволений
            'bingbot' => false,   // Bing - дозволений
            'yandexbot' => false, // Yandex - дозволений
            'baiduspider' => false, // Baidu - дозволений
            
            // Боти соціальних мереж
            'facebookexternalhit' => true,
            'twitterbot' => true,
            'linkedinbot' => true,
            'whatsapp' => true,
            'telegrambot' => true,
            
            // Загальні патерни ботів
            'bot' => true,
            'crawl' => true,
            'spider' => true,
            'scrape' => true,
            'curl' => true,
            'wget' => true,
            'python-requests' => true,
            'scraper' => true,
            
            // Інші відомі боти
            'slurp' => true,
            'duckduckbot' => true,
            'applebot' => true,
            'ia_archiver' => true,
            'archive' => true,
        ];
    }

    /**
     * Визначає, чи є поточний запит від бота.
     *
     * @param string $userAgent User-Agent рядка запиту.
     * @param string|null $ip IP-адреса запиту.
     * @return bool True, якщо запит від бота, false в іншому випадку.
     */
    public function isBot(string $userAgent, ?string $ip = null): bool
    {
        if (!$this->blockEnabled) {
            return false; // Блокування вимкнено
        }

        // Білий список IP-адрес, які ніколи не блокуються
        $whitelistedIPs = [
            '127.0.0.1',
            '::1',
            'localhost'
        ];
        
        if ($ip && in_array($ip, $whitelistedIPs, true)) {
            return false; // IP у білому списку - не блокуємо
        }

        if (empty($userAgent)) {
            return true; // Порожній User-Agent - ймовірно, бот
        }

        $userAgentLower = strtolower($userAgent);

        // Спочатку перевіряємо дозволених ботів - вони завжди пропускаються
        foreach ($this->allowedBots as $allowedBot) {
            $allowedBotLower = strtolower(trim($allowedBot));
            if (!empty($allowedBotLower) && str_contains($userAgentLower, $allowedBotLower)) {
                return false; // Дозволений бот - не блокуємо
            }
        }

        // Перевіряємо патерни ботів
        foreach ($this->botPatterns as $pattern => $shouldBlock) {
            if (!$shouldBlock) {
                continue; // Цей патерн не блокується
            }
            
            // Для патерну 'bot' робимо більш сувору перевірку, щоб не блокувати звичайні браузери
            if ($pattern === 'bot') {
                // Перевіряємо, що це справді бот (не частина звичайного User-Agent)
                // Звичайні браузери не містять просто 'bot' в назві
                if (str_contains($userAgentLower, 'bot') && 
                    !str_contains($userAgentLower, 'chrome') && 
                    !str_contains($userAgentLower, 'firefox') && 
                    !str_contains($userAgentLower, 'safari') && 
                    !str_contains($userAgentLower, 'edge') && 
                    !str_contains($userAgentLower, 'opera') &&
                    !str_contains($userAgentLower, 'mobile')) {
                    // Перевіряємо, чи не є це дозволеним ботом
                    $isAllowed = false;
                    foreach ($this->allowedBots as $allowedBot) {
                        $allowedBotLower = strtolower(trim($allowedBot));
                        if (!empty($allowedBotLower) && str_contains($userAgentLower, $allowedBotLower)) {
                            $isAllowed = true;
                            break;
                        }
                    }
                    
                    if (!$isAllowed) {
                        return true; // Знайдено патерн, що блокується
                    }
                }
            } elseif (str_contains($userAgentLower, $pattern)) {
                // Перевіряємо, чи не є цей патерн частиною дозволеного бота
                $isAllowed = false;
                foreach ($this->allowedBots as $allowedBot) {
                    $allowedBotLower = strtolower(trim($allowedBot));
                    if (!empty($allowedBotLower) && str_contains($allowedBotLower, $pattern)) {
                        $isAllowed = true;
                        break;
                    }
                }
                
                if (!$isAllowed) {
                    return true; // Знайдено патерн, що блокується
                }
            }
        }

        return false; // Не бот
    }

    /**
     * Блокує запит від бота та логує його.
     *
     * @param string $userAgent User-Agent рядка запиту.
     * @param string|null $ip IP-адреса запиту.
     * @param string|null $url URL запиту.
     */
    public function blockBot(string $userAgent, ?string $ip = null, ?string $url = null): void
    {
        // Формуємо унікальний ключ для запиту (IP + User-Agent + URL)
        // Використовуємо класову статичну змінну, щоб вона працювала для всіх екземплярів класу
        $ipValue = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $urlValue = $url ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $requestKey = md5($ipValue . '|' . $userAgent . '|' . $urlValue);
        
        // Якщо цей запит вже був заблокований в поточному запиті, не робимо нічого
        if (isset(self::$blockedRequests[$requestKey])) {
            return;
        }
        
        // Позначаємо, що цей запит вже обробляється
        self::$blockedRequests[$requestKey] = true;
        
        // Логуємо спробу доступу
        if ($this->db && $this->ensureTableExists()) {
            try {
                // Отримуємо timezone з БД через SettingsManager
                $timezone = $this->getTimezoneFromSettings();
                $now = new \DateTime('now', new \DateTimeZone($timezone));
                $blockedAt = $now->format('Y-m-d H:i:s');
                
                // Встановлюємо created_at явно, щоб використовувати той самий час, що і blocked_at
                $this->db->insert(
                    'INSERT INTO bot_blocker_logs (ip_address, user_agent, url, blocked_at, created_at) VALUES (?, ?, ?, ?, ?)',
                    [
                        $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
                        $userAgent,
                        $url ?? ($_SERVER['REQUEST_URI'] ?? '/'),
                        $blockedAt,
                        $blockedAt, // Використовуємо той самий час для created_at
                    ]
                );
            } catch (\Exception $e) {
                logger()->logError('BotBlockerService: Помилка логування - ' . $e->getMessage(), ['exception' => $e]);
            }
        }

        // Відправляємо 403 Forbidden
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        
        echo '<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ заборонено</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background-color: #f5f5f5;
            color: #333;
        }
        .container {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #dc3545;
            margin-bottom: 20px;
        }
        p {
            color: #666;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>403 - Доступ заборонено</h1>
        <p>Автоматичні запити заборонені.</p>
    </div>
</body>
</html>';
        exit;
    }

    /**
     * Отримує статистику заблокованих запитів.
     *
     * @param string|null $dateFrom Початкова дата для фільтрації.
     * @param string|null $dateTo Кінцева дата для фільтрації.
     * @return array Статистика блокувань, включаючи загальну кількість, за сьогодні та топ IP.
     */
    public function getBlockStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        if (!$this->db) {
            return [];
        }

        if (!$this->ensureTableExists()) {
            return [];
        }

        try {
            $sql = 'SELECT COUNT(*) as total_blocks FROM bot_blocker_logs WHERE 1=1';
            $params = [];

            if ($dateFrom && $dateTo) {
                $sql .= ' AND DATE(blocked_at) BETWEEN ? AND ?';
                $params[] = $dateFrom;
                $params[] = $dateTo;
            }

            $total = (int)($this->db->getValue($sql, $params) ?: 0);

            // Отримуємо timezone з БД через SettingsManager
            $timezone = $this->getTimezoneFromSettings();
            $tz = new \DateTimeZone($timezone);
            $todayDate = new \DateTime('today', $tz);
            $todayDateStr = $todayDate->format('Y-m-d');
            
            // Порівнюємо дати (дані зберігаються в часовому поясі з БД)
            $todayBlocks = (int)($this->db->getValue(
                "SELECT COUNT(*) FROM bot_blocker_logs WHERE DATE(blocked_at) = ?",
                [$todayDateStr]
            ) ?: 0);

            // Топ заблокованих IP
            $topIps = $this->db->getAll('
                SELECT ip_address, COUNT(*) as count 
                FROM bot_blocker_logs 
                GROUP BY ip_address 
                ORDER BY count DESC 
                LIMIT 10
            ');

            return [
                'total_blocks' => $total,
                'today_blocks' => $todayBlocks,
                'top_ips' => $topIps,
            ];
        } catch (\Exception $e) {
            logger()->logError('BotBlockerService: Помилка отримання статистики блокувань - ' . $e->getMessage(), ['exception' => $e]);
            return [];
        }
    }

    /**
     * Отримує поточні налаштування плагіна.
     * Примусово перезавантажує налаштування з бази даних перед поверненням.
     *
     * @return array Асоціативний масив налаштувань.
     */
    public function getSettings(): array
    {
        $blockEnabled = '0';
        $allowedBots = [];
        
        if (!$this->db) {
            return [
                'block_enabled' => $blockEnabled,
                'allowed_bots' => $allowedBots,
            ];
        }

        try {
            // Очищаємо кеш перед завантаженням, щоб отримати найактуальніші дані
            if (function_exists('cache_forget')) {
                cache_forget('plugin_settings_' . $this->pluginSlug);
            }

            $rows = $this->db->getAll(
                'SELECT setting_key, setting_value FROM plugin_settings WHERE plugin_slug = ?',
                [$this->pluginSlug]
            );
            
            foreach ($rows as $row) {
                $key = $row['setting_key'] ?? '';
                $value = $row['setting_value'] ?? '';
                
                if ($key === 'block_enabled') {
                    $value = trim((string)$value);
                    $blockEnabled = ($value === '1') ? '1' : '0';
                } elseif ($key === 'allowed_bots') {
                    if (!empty($value) && $value !== '[]' && $value !== 'null') {
                        $decoded = json_decode($value, true);
                        if (is_array($decoded)) {
                            $allowedBots = $decoded;
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            logger()->logError('BotBlockerService: Не вдалося завантажити налаштування з БД - ' . $e->getMessage(), ['exception' => $e]);
        }
        
        return [
            'block_enabled' => $blockEnabled,
            'allowed_bots' => $allowedBots,
        ];
    }

    /**
     * Зберігає налаштування плагіна в базу даних.
     *
     * @param array $settings Асоціативний масив налаштувань для збереження.
     * @return bool True, якщо налаштування успішно збережено, false у разі помилки.
     */
    public function saveSettings(array $settings): bool
    {
        if (!$this->db) {
            return false;
        }

        try {
            foreach ($settings as $key => $value) {
                if ($key === 'allowed_bots' && is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }

                // Нормалізуємо block_enabled - завжди зберігаємо як '0' або '1'
                if ($key === 'block_enabled') {
                    $value = ($value === '1' || $value === 1 || $value === true || $value === 'true') ? '1' : '0';
                }

                $this->db->execute(
                    'INSERT INTO plugin_settings (plugin_slug, setting_key, setting_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    [$this->pluginSlug, $key, $value]
                );
            }
            
            if (function_exists('cache_forget')) {
                cache_forget('plugin_settings_' . $this->pluginSlug);
            }

            // Перезавантажуємо налаштування після збереження
            $this->loadSettings();

            return true;
        } catch (\Throwable $e) {
            logger()->logException($e, ['method' => 'saveSettings']);
            return false;
        }
    }

    /**
     * Очищає всі логи блокувань з бази даних.
     *
     * @return bool True, якщо логи успішно очищено, false у разі помилки.
     */
    public function clearLogs(): bool
    {
        if (!$this->db) {
            return false;
        }

        if (!$this->ensureTableExists()) {
            return false;
        }

        try {
            $this->db->execute('TRUNCATE TABLE bot_blocker_logs');
            return true;
        } catch (\Exception $e) {
            logger()->logError('BotBlockerService: Помилка очищення логів - ' . $e->getMessage(), ['exception' => $e]);
            return false;
        }
    }
}

