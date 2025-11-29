# Bot Blocker Plugin

**Version:** 1.0.0  
**Author:** FlowAxy  
**Developer:** iTeffa (iteffa@flowaxy.com)  
**Studio:** FlowAxy  
**Website:** https://flowaxy.com  
**License:** Proprietary

## Description

Bot Blocker plugin for Flowaxy CMS provides automatic blocking of bots and automated scripts from accessing your website. The plugin analyzes User-Agent headers and blocks suspicious requests while allowing legitimate search engine crawlers.

## Features

### Core Features

- 🛡️ **Automatic Bot Detection** — Analyzes User-Agent strings to identify bots
- 🚫 **Blocking System** — Blocks suspicious requests with 403 Forbidden
- ✅ **Allowed Bots List** — Configure which bots should have access (e.g., Google, Bing, Yandex)
- 📊 **Statistics** — View blocking statistics and top blocked IPs
- 📝 **Logging** — All blocked requests are logged to the database
- ⚙️ **Settings Page** — Easy configuration through admin panel
- 🔒 **Admin Panel Protected** — Admin panel and API are always accessible

### Technical Capabilities

- Early request interception via `handle_early_request` hook
- Pattern-based bot detection
- Database-backed settings and logging
- Integration with Flowaxy CMS access control system

## Requirements

- PHP >= 8.4.0
- Flowaxy CMS with plugin support
- MySQL/MariaDB database
- Admin access for configuration

## Installation

1. Copy the plugin directory to `plugins/bot-blocker/`.
2. Activate the plugin via the admin panel (Settings → Plugins).
3. The plugin will automatically create necessary database tables.

The plugin will automatically register its route and menu item upon activation.

## Usage

### Accessing the Settings Page

1. Log in to the admin panel.
2. Navigate to **System → Блокування ботів** in the menu.
3. Or go directly to `/admin/bot-blocker`.

### Configuration

#### Enable/Disable Blocking

- Toggle the "Увімкнути блокування ботів" switch to enable or disable bot blocking.

#### Allowed Bots

Add bots that should have access to your website (one per line):

```
googlebot
bingbot
yandexbot
baiduspider
```

These bots will bypass the blocking system.

### How It Works

1. **Request Interception** — All incoming requests (except admin/API) are intercepted early in the request lifecycle.

2. **User-Agent Analysis** — The plugin analyzes the User-Agent header to identify bots.

3. **Pattern Matching** — Known bot patterns are checked:
   - Social media bots (Facebook, Twitter, LinkedIn, etc.)
   - Scrapers and crawlers
   - Automated tools (curl, wget, Python requests, etc.)
   - Archive bots

4. **Allowed Bots Check** — If the bot is in your allowed list, it's permitted.

5. **Blocking** — Suspicious bots receive a 403 Forbidden response and are logged.

### Statistics

The plugin provides:
- **Today's Blocks** — Number of bots blocked today
- **Total Blocks** — Total number of blocked requests
- **Top Blocked IPs** — IP addresses with the most blocked attempts

## Plugin Structure

```
bot-blocker/
├── assets/
│   └── styles/
│       └── bot-blocker.css    # Styles for the settings page
├── src/
│   ├── admin/
│   │   └── pages/
│   │       └── BotBlockerAdminPage.php  # Admin settings page
│   └── Services/
│       └── BotBlockerService.php        # Core blocking service
├── templates/
│   └── bot-blocker.php                  # Settings page template
├── init.php                             # Plugin initialization
├── plugin.json                          # Plugin metadata
└── README.md                            # Documentation
```

## Technical Details

### Architecture

The plugin uses a service-oriented architecture:

- **BotBlockerService** — Core service for bot detection and blocking
- **BotBlockerAdminPage** — Admin panel page for configuration
- **Templates** — PHP templates for HTML rendering

### Database Tables

#### `bot_blocker_logs`

Logs all blocked requests:
- `id` — Unique identifier
- `ip_address` — IP address of blocked request
- `user_agent` — User-Agent string
- `url` — Requested URL
- `blocked_at` — Block timestamp
- `created_at` — Creation timestamp

### Security

- ✅ CSRF protection for all write operations
- ✅ Access permission checks before executing operations
- ✅ Admin panel and API are always accessible
- ✅ SQL injection protection via prepared statements
- ✅ XSS protection via output sanitization

### Bot Detection Patterns

The plugin detects bots by checking for common patterns in User-Agent strings:

- Social media bots: `facebookexternalhit`, `twitterbot`, `linkedinbot`, etc.
- Scrapers: `scrape`, `crawl`, `spider`, `bot`
- Automated tools: `curl`, `wget`, `python-requests`, `java`, etc.
- Archive bots: `archive`, `wayback`, `ia_archiver`

### Hooks

The plugin uses the following hooks:

- `handle_early_request` (priority: 1) — Early request interception for blocking
- `admin_register_routes` — Register admin route
- `admin_menu` — Add menu item

## Configuration

### Default Behavior

By default, the plugin:
- Blocks all bots except those in the allowed list
- Allows admin panel and API access
- Logs all blocked requests
- Blocks empty User-Agent strings

### Customization

You can customize bot detection by:

1. Adding allowed bots in the admin panel
2. Modifying bot patterns in `BotBlockerService::initializeBotPatterns()`
3. Adjusting blocking logic in `BotBlockerService::isBot()`

## Development

### Dependencies

The plugin uses the following components from the Engine:

- `engine/core/support/base/BasePlugin.php`
- `engine/core/support/helpers/DatabaseHelper.php`
- `engine/interface/admin-ui/includes/AdminPage.php`
- `engine/core/support/helpers/UrlHelper.php`
- `engine/core/support/helpers/SecurityHelper.php`

### Extending Functionality

To extend the plugin:

1. **Add new bot patterns** — Edit `initializeBotPatterns()` in `BotBlockerService.php`
2. **Customize blocking logic** — Modify `isBot()` method
3. **Add new statistics** — Extend `getBlockStats()` method
4. **Customize UI** — Edit `templates/bot-blocker.php` and `assets/styles/bot-blocker.css`

## Support

If you find a bug or have questions:

1. Check log files for errors
2. Verify database tables are created
3. Ensure PHP has proper permissions

## Testing Bot Blocking

### Quick Visual Test

The easiest way to test bot blocking is using `curl`:

```bash
# Test 1: Normal browser (should pass)
curl -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36" http://your-domain.com/
# Expected: HTTP 200

# Test 2: Bot (should be blocked)
curl -A "TestBot/1.0" http://your-domain.com/
# Expected: HTTP 403

# Test 3: Empty User-Agent (should be blocked)
curl -A "" http://your-domain.com/
# Expected: HTTP 403
```

## License

Proprietary. All rights reserved.

## Version History

### 1.0.0 (2025-11-29)

- ✨ Initial release
- ✅ Bot detection and blocking
- ✅ Admin settings page
- ✅ Statistics and logging
- ✅ Allowed bots configuration
- ✅ Integration with Flowaxy CMS Engine
- ✅ Database timezone support

## Author

**FlowAxy**  
Developer: iTeffa  
Email: iteffa@flowaxy.com  
Studio: flowaxy.com  
Website: https://flowaxy.com

---

*Developed with ❤️ for Flowaxy CMS*
