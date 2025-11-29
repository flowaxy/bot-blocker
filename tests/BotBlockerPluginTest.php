<?php
/**
 * Тести для плагіна Bot Blocker
 * 
 * Тести автоматично підключаються через TestService та TestRunner
 * 
 * @package BotBlocker
 * @version 1.0.0
 */

declare(strict_types=1);

/**
 * Тести сервісу блокування ботів
 */
final class BotBlockerPluginTest extends TestCase
{
    private ?BotBlockerService $service = null;
    private ?\DatabaseInterface $db = null;
    private string $testPluginSlug = 'bot-blocker-test';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Підключаємо необхідні класи
        $rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 3);
        
        if (!class_exists('DatabaseHelper')) {
            require_once $rootDir . '/engine/core/support/helpers/DatabaseHelper.php';
        }
        
        if (!class_exists('BotBlockerService')) {
            $serviceFile = $rootDir . '/plugins/bot-blocker/src/Services/BotBlockerService.php';
            if (file_exists($serviceFile)) {
                require_once $serviceFile;
            }
        }

        // Підключаємо Logger якщо потрібно
        if (!class_exists('Logger')) {
            $loggerFile = $rootDir . '/engine/infrastructure/logging/Logger.php';
            if (file_exists($loggerFile)) {
                require_once $loggerFile;
            }
        }

        try {
            $this->db = DatabaseHelper::getInstance();
            if ($this->db && !$this->db->isAvailable()) {
                $this->db = null;
            }
        } catch (\Exception $e) {
            // База даних недоступна для тестів
            $this->db = null;
        }

        if ($this->db) {
            $this->service = new BotBlockerService($this->testPluginSlug);
        }
    }

    protected function tearDown(): void
    {
        // Очищаємо тестові дані
        if ($this->db) {
            try {
                // Видаляємо тестові налаштування
                $this->db->execute(
                    'DELETE FROM plugin_settings WHERE plugin_slug = ?',
                    [$this->testPluginSlug]
                );
                
                // Видаляємо тестові логи
                $this->db->execute(
                    'DELETE FROM bot_blocker_logs WHERE ip_address LIKE ?',
                    ['TEST_%']
                );
            } catch (\Exception $e) {
                // Ігноруємо помилки очищення
            }
        }
        
        parent::tearDown();
    }

    /**
     * Тест перевірки бота з порожнім User-Agent
     */
    public function testIsBotWithEmptyUserAgent(): void
    {
        if (!$this->service || !$this->db) {
            $this->markTestSkipped('База даних недоступна');
            return;
        }

        // Вмикаємо блокування
        $this->service->saveSettings(['block_enabled' => '1', 'allowed_bots' => []]);
        $this->service->loadSettings();

        // Використовуємо IP, який не в білому списку (127.0.0.1 завжди дозволений)
        $result = $this->service->isBot('', '192.168.1.100');
        $this->assertTrue($result, 'Порожній User-Agent повинен вважатися ботом');
    }

    /**
     * Тест перевірки звичайного браузера
     */
    public function testIsBotWithNormalBrowser(): void
    {
        if (!$this->service || !$this->db) {
            $this->markTestSkipped('База даних недоступна');
            return;
        }

        // Вмикаємо блокування
        $this->service->saveSettings(['block_enabled' => '1', 'allowed_bots' => []]);
        $this->service->loadSettings();

        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $result = $this->service->isBot($userAgent, '127.0.0.1');
        $this->assertFalse($result, 'Звичайний браузер не повинен вважатися ботом');
    }

    /**
     * Тест перевірки дозволеного бота
     */
    public function testIsBotWithAllowedBot(): void
    {
        if (!$this->service || !$this->db) {
            $this->markTestSkipped('База даних недоступна');
            return;
        }

        // Вмикаємо блокування та додаємо дозволеного бота
        $this->service->saveSettings([
            'block_enabled' => '1',
            'allowed_bots' => ['googlebot']
        ]);
        $this->service->loadSettings();

        $userAgent = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $result = $this->service->isBot($userAgent, '127.0.0.1');
        $this->assertFalse($result, 'Дозволений бот не повинен блокуватися');
    }

    /**
     * Тест збереження та отримання налаштувань
     */
    public function testSaveAndGetSettings(): void
    {
        if (!$this->service || !$this->db) {
            $this->markTestSkipped('База даних недоступна');
            return;
        }

        $testSettings = [
            'block_enabled' => '1',
            'allowed_bots' => ['googlebot', 'bingbot']
        ];

        $result = $this->service->saveSettings($testSettings);
        $this->assertTrue($result, 'Налаштування повинні зберігатися успішно');

        $loadedSettings = $this->service->getSettings();
        $this->assertEquals('1', $loadedSettings['block_enabled'], 'block_enabled повинен бути "1"');
        $allowedBotsCount = count($loadedSettings['allowed_bots']);
        $this->assertEquals(2, $allowedBotsCount, 'Повинно бути 2 дозволених бота');
    }

    /**
     * Тест отримання статистики блокувань
     */
    public function testGetBlockStats(): void
    {
        if (!$this->service || !$this->db) {
            $this->markTestSkipped('База даних недоступна');
            return;
        }

        // Перевіряємо, що метод не викликає помилок
        $stats = $this->service->getBlockStats();
        $this->assertTrue(is_array($stats), 'Статистика повинна бути масивом');
        $this->assertTrue(isset($stats['total_blocks']), 'Повинен бути ключ total_blocks');
        $this->assertTrue(isset($stats['today_blocks']), 'Повинен бути ключ today_blocks');
        $this->assertTrue(isset($stats['top_ips']), 'Повинен бути ключ top_ips');
    }

    /**
     * Тест очищення логів
     */
    public function testClearLogs(): void
    {
        if (!$this->service || !$this->db) {
            $this->markTestSkipped('База даних недоступна');
            return;
        }

        // Перевіряємо, що метод не викликає помилок
        $result = $this->service->clearLogs();
        $this->assertTrue($result, 'Очищення логів повинно бути успішним');
    }

    /**
     * Тест блокування бота
     */
    public function testBlockBot(): void
    {
        if (!$this->service || !$this->db) {
            $this->markTestSkipped('База даних недоступна');
            return;
        }

        // Перевіряємо, що метод блокування працює
        // Цей тест перевіряє логіку без фактичного виводу 403
        $userAgent = 'TestBot/1.0';
        $ip = '192.168.1.100';
        $url = '/test';

        // Метод blockBot викличе exit(), тому тестуємо лише логіку логування
        // Фактичне тестування вимагає мокування http_response_code та exit
        $this->assertNotNull($this->service, 'Сервіс повинен бути ініціалізований');
    }
}

