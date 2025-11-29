<?php
/**
 * Плагін блокування ботів
 * 
 * Цей плагін блокує автоматичні запити від ботів та сканерів,
 * дозволяючи налаштувати список дозволених ботів.
 * 
 * @package BotBlocker
 * @version 1.0.0
 * @author Flowaxy Team
 */

declare(strict_types=1);

$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 2);
require_once $rootDir . '/engine/core/support/base/BasePlugin.php';
require_once $rootDir . '/engine/core/support/helpers/UrlHelper.php';

// Підключаємо Logger для логування
if (!class_exists('Logger') && file_exists($rootDir . '/engine/infrastructure/logging/Logger.php')) {
    require_once $rootDir . '/engine/infrastructure/logging/Logger.php';
}

if (! function_exists('addHook')) {
    require_once $rootDir . '/engine/includes/functions.php';
}

// Завантажуємо ClassAutoloader для реєстрації класів
if (file_exists($rootDir . '/engine/core/system/ClassAutoloader.php')) {
    require_once $rootDir . '/engine/core/system/ClassAutoloader.php';
}

// Підключаємо SecurityHelper для перевірки авторизації
if (!class_exists('SecurityHelper') && file_exists($rootDir . '/engine/core/support/helpers/SecurityHelper.php')) {
    require_once $rootDir . '/engine/core/support/helpers/SecurityHelper.php';
}

// Підключаємо Session для перевірки сесії
if (!class_exists('Session') && file_exists($rootDir . '/engine/infrastructure/security/Session.php')) {
    require_once $rootDir . '/engine/infrastructure/security/Session.php';
}

// Завантажуємо сервіс для блокування ботів
$botBlockerServiceFile = dirname(__FILE__) . '/src/Services/BotBlockerService.php';
if (file_exists($botBlockerServiceFile)) {
    require_once $botBlockerServiceFile;
}

/**
 * Клас плагіна блокування ботів
 * 
 * Відповідає за ініціалізацію плагіна, реєстрацію маршрутів,
 * меню та обробку запитів для блокування ботів.
 */
class BotBlockerPlugin extends BasePlugin
{
    private string $pluginDir;
    
    /** @var bool Захист від повторної перевірки запиту */
    private static bool $requestChecked = false;

    /**
     * Конструктор плагіна
     */
    public function __construct()
    {
        parent::__construct();
        $reflection = new ReflectionClass($this);
        $this->pluginDir = dirname($reflection->getFileName());
    }

    /**
     * Ініціалізація плагіна
     * 
     * Реєструє маршрути адмін-панелі, пункти меню та хуки для блокування ботів.
     */
    public function init(): void
    {
        // Реєстрація маршруту для налаштувань
        addHook('admin_register_routes', [$this, 'registerAdminRoute'], 10, 1);
        
        // Реєстрація пункту меню
        addFilter('admin_menu', [$this, 'registerAdminMenu'], 20);
        
        // Реєстрація в категоріях налаштувань
        addFilter('settings_categories', [$this, 'registerSettingsCategory'], 10);
        
        // Блокування ботів (ранній хук з високим пріоритетом)
        addHook('handle_early_request', [$this, 'blockBots'], 1);
    }

    /**
     * Реєстрація адмін-маршруту
     * 
     * @param mixed $router Роутер для реєстрації маршрутів
     * @return void
     */
    public function registerAdminRoute($router): void
    {
        $pageFile = $this->pluginDir . '/src/admin/pages/BotBlockerAdminPage.php';
        if (file_exists($pageFile)) {
            // Реєструємо клас в автозавантажувачі
            if (isset($GLOBALS['engineAutoloader'])) {
                $autoloader = $GLOBALS['engineAutoloader'];
                if ($autoloader instanceof ClassAutoloader || 
                    (is_object($autoloader) && method_exists($autoloader, 'addClassMap'))) {
                    $autoloader->addClassMap([
                        'BotBlockerAdminPage' => $pageFile
                    ]);
                }
            }
            
            require_once $pageFile;
            if (class_exists('BotBlockerAdminPage')) {
                $router->add(['GET', 'POST'], 'bot-blocker', 'BotBlockerAdminPage');
            }
        }
    }

    /**
     * Реєстрація пункту меню в адмін-панелі
     * 
     * Додає пункт "Блокування ботів" в меню "Система"
     * 
     * @param array<int, array<string, mixed>> $menu Поточне меню адмін-панелі
     * @return array<int, array<string, mixed>> Оновлене меню
     */
    public function registerAdminMenu(array $menu): array
    {
        // Додаємо пункт в меню "Система"
        $found = false;
        foreach ($menu as &$item) {
            if (isset($item['page']) && $item['page'] === 'system') {
                if (!isset($item['submenu'])) {
                    $item['submenu'] = [];
                }
                $item['submenu'][] = [
                    'text' => 'Блокування ботів',
                    'icon' => 'fas fa-shield-alt',
                    'href' => UrlHelper::admin('bot-blocker'),
                    'page' => 'bot-blocker',
                    'order' => 10,
                    'permission' => null,
                ];
                $found = true;
                break;
            }
        }
        
        // Якщо меню "Система" не знайдено, створюємо його
        if (! $found) {
            $menu[] = [
                'text' => 'Система',
                'icon' => 'fas fa-server',
                'href' => '#',
                'page' => 'system',
                'order' => 60,
                'permission' => null,
                'submenu' => [
                    [
                        'text' => 'Блокування ботів',
                        'icon' => 'fas fa-shield-alt',
                        'href' => UrlHelper::admin('bot-blocker'),
                        'page' => 'bot-blocker',
                        'order' => 10,
                        'permission' => null,
                    ],
                ],
            ];
        }

        return $menu;
    }

    /**
     * Реєстрація в категоріях налаштувань
     * 
     * Додає плагін до категорії "Система" на сторінці /admin/settings
     * 
     * @param array<string, mixed> $categories Поточні категорії
     * @return array<string, mixed> Оновлені категорії
     */
    public function registerSettingsCategory(array $categories): array
    {
        // Перевіряємо, чи плагін активний
        $pluginManager = function_exists('pluginManager') ? pluginManager() : null;
        if (!$pluginManager || !method_exists($pluginManager, 'isPluginActive') || !$pluginManager->isPluginActive('bot-blocker')) {
            return $categories;
        }

        // Додаємо до категорії "Система"
        if (isset($categories['system'])) {
            $categories['system']['items'][] = [
                'title' => 'Блокування ботів',
                'description' => 'Управління блокуванням ботів',
                'url' => UrlHelper::admin('bot-blocker'),
                'icon' => 'fas fa-shield-alt',
                'permission' => null,
            ];
        }

        return $categories;
    }

    /**
     * Блокування ботів
     * 
     * Перевіряє запит на наявність бота та блокує його, якщо необхідно.
     * Ігнорує запити до адмін-панелі, API та від авторизованих користувачів.
     * 
     * Цей метод використовується як фільтр для handle_early_request хука.
     * Приймає початкове значення (зазвичай false) і повертає true, якщо запит заблоковано.
     * 
     * Підтримує два формати виклику:
     * - Через HookManager: blockBots($handled, $context)
     * - Через PluginManager: blockBots([$handled, $context])
     * 
     * @param mixed $handled Початкове значення (false = запит не оброблено) або масив [$handled, $context]
     * @param array<string, mixed>|null $context Контекст хука (якщо передається окремо)
     * @return bool true - якщо бот заблоковано, false - якщо продовжити обробку
     */
    public function blockBots(mixed $handled = false, ?array $context = null): bool
    {
        // Обробка випадку, коли передається масив [$handled, $context]
        if (is_array($handled) && count($handled) >= 1) {
            $context = $handled[1] ?? null;
            $handled = $handled[0] ?? false;
        }
        
        // Нормалізуємо значення
        $handled = (bool)$handled;
        
        // Захист від повторної перевірки
        if (self::$requestChecked) {
            return $handled;
        }

        try {
            $path = $_SERVER['REQUEST_URI'] ?? '/';
            
            // Ігноруємо запити до адмін-панелі, API та статичних файлів
            if (str_starts_with($path, '/admin') || 
                str_starts_with($path, '/api') ||
                $path === '/favicon.ico' ||
                str_starts_with($path, '/robots.txt') ||
                str_starts_with($path, '/sitemap') ||
                preg_match('/\.(ico|png|jpg|jpeg|gif|css|js|woff|woff2|ttf|svg)$/i', $path)) {
                self::$requestChecked = true;
                return $handled;
            }
            
            // Перевіряємо авторизацію
            $adminUserId = 0;
            if (function_exists('sessionManager')) {
                $session = sessionManager();
                $adminUserId = (int)($session->get('admin_user_id') ?? 0);
            }
            
            // Ігноруємо запити від авторизованих користувачів
            if ($adminUserId > 0) {
                self::$requestChecked = true;
                return $handled;
            }
            
            // Додаткова перевірка через SecurityHelper
            if (function_exists('SecurityHelper') && class_exists('SecurityHelper')) {
                if (method_exists('SecurityHelper', 'isAdminLoggedIn') && SecurityHelper::isAdminLoggedIn()) {
                    self::$requestChecked = true;
                    return $handled;
                }
            }
            
            // Отримуємо User-Agent та IP
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            // Перевіряємо, чи це бот
            if (class_exists('BotBlockerService')) {
                $botBlocker = new BotBlockerService($this->getSlug());
                
                $isBotResult = $botBlocker->isBot($userAgent, $ip);
                
                if ($isBotResult) {
                    self::$requestChecked = true;
                    
                    $botBlocker->blockBot($userAgent, $ip, $path);
                    return true;
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->logException($e, ['plugin' => 'bot-blocker', 'method' => 'blockBots']);
            }
        }

        self::$requestChecked = true;
        return $handled; // Продовжуємо обробку запиту
    }

    /**
     * Встановлення плагіна (створення таблиць)
     * 
     * Створює необхідні таблиці в базі даних та встановлює налаштування за замовчуванням.
     */
    public function install(): void
    {
        try {
            $db = DatabaseHelper::getInstance();
            if (!$db || !$db->isAvailable()) {
                return;
            }

            // Перевіряємо існування таблиці
            if (DatabaseHelper::tableExists('bot_blocker_logs')) {
                return; // Таблиця вже існує
            }

            // Таблиця логів блокувань
            $db->execute('
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

            // Встановлюємо налаштування за замовчуванням
            $defaultSettings = [
                'block_enabled' => '1',
                'allowed_bots' => json_encode(['googlebot', 'bingbot', 'yandexbot', 'baiduspider'], JSON_UNESCAPED_UNICODE),
            ];

            foreach ($defaultSettings as $key => $value) {
                $this->setSetting($key, $value);
            }
        } catch (\Exception $e) {
            if (function_exists('logger')) {
                logger()->logException($e, ['plugin' => 'bot-blocker', 'action' => 'install']);
            }
        }
    }
}

return new BotBlockerPlugin();
