<?php

/**
 * Сторінка налаштувань блокування ботів
 */

declare(strict_types=1);

$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 5);
$engineDir = $rootDir . '/engine';

require_once $engineDir . '/interface/admin-ui/includes/AdminPage.php';


if (!class_exists('Response') && file_exists($engineDir . '/interface/http/controllers/Response.php')) {
    require_once $engineDir . '/interface/http/controllers/Response.php';
}

if (!class_exists('SecurityHelper') && file_exists($engineDir . '/core/support/helpers/SecurityHelper.php')) {
    require_once $engineDir . '/core/support/helpers/SecurityHelper.php';
}

// Завантажуємо BotBlockerService
$serviceFile = dirname(__DIR__, 2) . '/Services/BotBlockerService.php';
if (file_exists($serviceFile)) {
    require_once $serviceFile;
}

class BotBlockerAdminPage extends AdminPage
{
    private string $pluginDir;
    private ?BotBlockerService $botBlockerService = null;

    public function __construct()
    {
        parent::__construct();

        // Перевірка прав доступу
        if (! function_exists('current_user_can') || ! current_user_can('admin.access')) {
            Response::redirectStatic(UrlHelper::admin('dashboard'));
            exit;
        }

        // Визначаємо шлях до директорії плагіна
        $this->pluginDir = dirname(__DIR__, 3);

        $this->pageTitle = 'Блокування ботів - Flowaxy CMS';
        $this->templateName = 'bot-blocker';

        $this->setPageHeader(
            'Блокування ботів',
            'Налаштування блокування автоматичних запитів та ботів',
            'fas fa-shield-alt'
        );

        // Додаємо хлібні крихти
        $this->setBreadcrumbs([
            ['title' => 'Головна', 'url' => UrlHelper::admin('dashboard')],
            ['title' => 'Блокування ботів'],
        ]);

        // Ініціалізуємо сервіс
        if (class_exists('BotBlockerService')) {
            try {
                $this->botBlockerService = new BotBlockerService('bot-blocker');
            } catch (\Throwable $e) {
                logger()->logException($e, ['plugin' => 'bot-blocker', 'action' => 'init']);
            }
        }

        // Підключаємо CSS
        $this->additionalCSS[] = $this->pluginAsset('styles/bot-blocker.css');
    }

    /**
     * Отримання URL до ресурсів плагіна
     */
    private function pluginAsset(string $path): string
    {
        $relativePath = 'plugins/bot-blocker/assets/' . ltrim($path, '/');
        $absolutePath = $this->pluginDir . '/assets/' . ltrim($path, '/');
        $version = file_exists($absolutePath) ? substr(md5_file($absolutePath), 0, 8) : substr((string)time(), -8);

        return UrlHelper::base($relativePath) . '?v=' . $version;
    }

    /**
     * Отримання шляху до шаблонів плагіна
     */
    protected function getTemplatePath()
    {
        return $this->pluginDir . '/templates/';
    }

    public function handle(): void
    {
        if (!$this->botBlockerService) {
            $this->setMessage('Помилка: сервіс блокування ботів недоступний', 'danger');
            $this->render([]);
            return;
        }

        // Обробка збереження налаштувань
        if ($this->isMethod('POST') && $this->post('save_settings')) {
            $this->handleSaveSettings();
            return;
        }

        // Обробка очищення логів
        if ($this->isMethod('POST') && $this->post('clear_logs')) {
            $this->handleClearLogs();
            return;
        }

        // Отримуємо статистику та налаштування
        try {
            $this->botBlockerService = new BotBlockerService('bot-blocker');
            $settings = $this->botBlockerService->getSettings();
            $stats = $this->botBlockerService->getBlockStats();

            $this->render([
                'botBlockerSettings' => $settings,
                'botBlockerStats' => $stats,
            ]);
        } catch (\Throwable $e) {
            logger()->logException($e, ['plugin' => 'bot-blocker', 'action' => 'render']);
            $this->setMessage('Помилка завантаження налаштувань: ' . $e->getMessage(), 'danger');
            $this->render([
                'settings' => ['block_enabled' => '0', 'allowed_bots' => []],
                'stats' => ['today_blocks' => 0, 'total_blocks' => 0, 'top_ips' => []],
            ]);
        }
    }

    /**
     * Збереження налаштувань
     */
    private function handleSaveSettings(): void
    {
        if (!$this->verifyCsrf()) {
            $this->setMessage('Помилка безпеки: невірний CSRF токен', 'danger');
            return;
        }

        try {
            // Отримуємо значення checkbox
            $hasBlockEnabled = $this->request()->has('block_enabled') || isset($_POST['block_enabled']);
            $blockEnabled = $hasBlockEnabled && ($this->post('block_enabled', '0') === '1') ? '1' : '0';
            $allowedBots = [];

            // Обробляємо дозволених ботів
            $allowedBotsRaw = $this->post('allowed_bots', '');
            if (!empty($allowedBotsRaw)) {
                $botsList = explode("\n", $allowedBotsRaw);
                foreach ($botsList as $bot) {
                    $bot = trim($bot);
                    if (!empty($bot)) {
                        $allowedBots[] = $bot;
                    }
                }
            }

            $settings = [
                'block_enabled' => $blockEnabled,
                'allowed_bots' => $allowedBots,
            ];

            $result = $this->botBlockerService->saveSettings($settings);

            if ($result) {
                    // Оновлюємо сервіс для завантаження нових налаштувань
                $this->botBlockerService = new BotBlockerService('bot-blocker');
                $this->setMessage('Налаштування успішно збережено', 'success');
            } else {
                $this->setMessage('Помилка збереження налаштувань', 'danger');
            }
        } catch (\Throwable $e) {
            logger()->logException($e, ['plugin' => 'bot-blocker', 'action' => 'save_settings']);
            $this->setMessage('Помилка: ' . $e->getMessage(), 'danger');
        }

        $this->redirect('bot-blocker');
    }

    /**
     * Очистка логов
     */
    private function handleClearLogs(): void
    {
        if (!$this->verifyCsrf()) {
            $this->setMessage('Помилка безпеки: невірний CSRF токен', 'danger');
            $this->redirect('bot-blocker');
            return;
        }

        try {
            if ($this->botBlockerService) {
                if ($this->botBlockerService->clearLogs()) {
                    $this->setMessage('Логи успішно очищено', 'success');
                } else {
                    $this->setMessage('Помилка очищення логів. Перевірте логи.', 'danger');
                }
            } else {
                $this->setMessage('Помилка: сервіс блокування ботів недоступний', 'danger');
            }
        } catch (\Exception $e) {
            $this->setMessage('Помилка очищення логів: ' . $e->getMessage(), 'danger');
        }

        $this->redirect('bot-blocker');
    }

}

