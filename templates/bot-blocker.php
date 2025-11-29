<?php
/**
 * Шаблон страницы настроек блокировки ботов
 */

// Определяем правильный путь к компонентам админки
$rootDir = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__, 4);
$componentsPath = $rootDir . '/engine/interface/admin-ui/components/';

?>

<!-- Уведомления -->
<?php
if (! empty($message)) {
    include $componentsPath . 'alert.php';
    $type = $messageType ?? 'info';
    $dismissible = true;
}
?>

<?php
// Ініціалізація та нормалізація даних з render()
// Дані передаються через метод render() з AdminPage під унікальними іменами
// щоб уникнути конфлікту з $settings, які використовує layout для кастомізації адмінки

// Отримуємо налаштування плагіна з унікальних змінних
$pluginSettings = isset($botBlockerSettings) && is_array($botBlockerSettings) 
    ? $botBlockerSettings 
    : ['block_enabled' => '0', 'allowed_bots' => []];
$pluginStats = isset($botBlockerStats) && is_array($botBlockerStats) 
    ? $botBlockerStats 
    : ['today_blocks' => 0, 'total_blocks' => 0, 'top_ips' => []];

// Нормалізуємо block_enabled - перевіряємо всі можливі варіанти
// Використовуємо збережені налаштування плагіна
$blockEnabledValue = $pluginSettings['block_enabled'] ?? '0';

if (is_string($blockEnabledValue)) {
    $blockEnabledValue = trim($blockEnabledValue);
} elseif (is_bool($blockEnabledValue)) {
    $blockEnabledValue = $blockEnabledValue ? '1' : '0';
} elseif (is_int($blockEnabledValue)) {
    $blockEnabledValue = ($blockEnabledValue === 1) ? '1' : '0';
} elseif ($blockEnabledValue === null) {
    $blockEnabledValue = '0';
} else {
    $blockEnabledValue = '0';
}

// Фінальне значення - тільки '1' або '0'
// Використовуємо строге порівняння з '1'
$blockEnabledSetting = ($blockEnabledValue === '1') ? '1' : '0';

// Нормалізуємо allowed_bots
// Використовуємо збережені налаштування плагіна
$allowedBotsList = [];
if (!empty($pluginSettings['allowed_bots'])) {
    if (is_array($pluginSettings['allowed_bots'])) {
        $allowedBotsList = $pluginSettings['allowed_bots'];
    } elseif (is_string($pluginSettings['allowed_bots'])) {
        $decoded = json_decode($pluginSettings['allowed_bots'], true);
        if (is_array($decoded) && !empty($decoded)) {
            $allowedBotsList = $decoded;
        }
    }
}

// Формируем содержимое секции
ob_start();
?>
<div class="bot-blocker-page">
    <!-- Статистика -->
    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <div class="card border-left-danger h-100">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                Сьогодні заблоковано
                            </div>
                            <div class="h4 mb-0 font-weight-bold text-gray-800">
                                <?= number_format($pluginStats['today_blocks'] ?? 0, 0, ',', ' ') ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-ban fa-2x text-danger opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-left-warning h-100">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Всього заблоковано
                            </div>
                            <div class="h4 mb-0 font-weight-bold text-gray-800">
                                <?= number_format($pluginStats['total_blocks'] ?? 0, 0, ',', ' ') ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-shield-alt fa-2x text-warning opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-left-info h-100">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Статус
                            </div>
                            <div class="h4 mb-0 font-weight-bold text-gray-800">
                                <?php 
                                $isEnabled = ($blockEnabledSetting === '1');
                                echo $isEnabled ? '<span class="text-success">Активний</span>' : '<span class="text-muted">Неактивний</span>';
                                ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-toggle-<?= ($blockEnabledSetting === '1') ? 'on' : 'off' ?> fa-2x text-info opacity-25"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Настройки -->
    <div class="row mb-4">
        <div class="col-md-8 mb-3">
            <div class="card border-0">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0">Налаштування блокування</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= SecurityHelper::csrfToken() ?>">
                        
                        <div class="mb-4">
                            <?php
                            // Використовуємо компонент форми з ядра
                            $type = 'checkbox';
                            $name = 'block_enabled';
                            $label = 'Увімкнути блокування ботів';
                            // Передаємо значення - компонент form-group перевіряє ($value == '1' || $value === true)
                            $value = $blockEnabledSetting; // Має бути '1' або '0'
                            $helpText = 'Якщо увімкнено, автоматичні запити від ботів будуть заблоковані';
                            $id = 'blockEnabled';
                            $attributes = []; // form-group сам додасть необхідні класи
                            include $componentsPath . 'form-group.php';
                            ?>
                        </div>

                        <div class="mb-3">
                            <label for="allowedBots" class="form-label fw-semibold">
                                Дозволені боти (один на рядок)
                            </label>
                            <textarea class="form-control" id="allowedBots" name="allowed_bots" rows="8" 
                                      placeholder="googlebot&#10;bingbot&#10;yandexbot"><?php 
                                if (!empty($allowedBotsList) && is_array($allowedBotsList)) {
                                    echo htmlspecialchars(implode("\n", $allowedBotsList)); 
                                }
                            ?></textarea>
                            <small class="text-muted d-block mt-2">
                                Вкажіть назви ботів, які мають мати доступ до сайту (наприклад: googlebot, bingbot, yandexbot). 
                                Один бот на рядок.
                            </small>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" name="save_settings" value="1" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>Зберегти налаштування
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card border-0">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0">Топ заблокованих IP</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($pluginStats['top_ips'])): ?>
                        <p class="text-muted mb-0">Немає даних</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($pluginStats['top_ips'] as $index => $ipData): ?>
                                <div class="list-group-item px-0 py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($ipData['ip_address']) ?></div>
                                            <small class="text-muted">
                                                <?= number_format($ipData['count'], 0, ',', ' ') ?> спроб
                                            </small>
                                        </div>
                                        <span class="badge bg-danger"><?= $index + 1 ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Інформація -->
    <div class="card border-0">
        <div class="card-header bg-white border-bottom">
            <h5 class="mb-0">Інформація про блокування</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6 class="fw-semibold mb-3">Як працює блокування:</h6>
                    <ul class="mb-0">
                        <li>Система аналізує User-Agent кожного запиту</li>
                        <li>Блокуються запити, що містять характерні ознаки ботів</li>
                        <li>Дозволені боти (вказані в налаштуваннях) мають доступ</li>
                        <li>Всі заблоковані запити логуються в базу даних</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-semibold mb-3">Що робиться:</h6>
                    <ul class="mb-0">
                        <li>Автоматично блокуються: сканери, скрапери, боти</li>
                        <li>Дозволяється: пошукові боти (Google, Bing, Yandex)</li>
                        <li>Адмін-панель та API завжди доступні</li>
                        <li>Заблокованим ботом повертається 403 Forbidden</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$sectionContent = ob_get_clean();

// Використовуємо компонент секції контенту
$title = '';
$icon = '';
$content = $sectionContent;
$classes = ['bot-blocker-page'];
include $componentsPath . 'content-section.php';
?>
