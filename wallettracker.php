<?php
/**
 * Plugin Name: Chain Wallet Discord Tracker
 * Plugin URI: https://example.com
 * Description: Track Ethereum and BSC wallet activity using Etherscan and BscScan APIs and send Discord webhook alerts. Includes wallet management, configurable API keys, and persistent transaction logs via shortcode or admin page.
 * Version: 1.0.0
 * Author: OpenAI Assistant
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

const WALLET_TRACKER_OPTION = 'wallet_tracker_payload_v1';
const WALLET_TRACKER_NONCE = 'wallet_tracker_nonce_v1';

/**
 * Default plugin data structure.
 */
function wallet_tracker_default_data(): array {
    return [
        'config' => [
            'ethApiKey' => '',
            'bscApiKey' => '',
            'discordWebhook' => '',
            'pollInterval' => 60,
        ],
        'wallets' => [],
        'logs' => [],
    ];
}

/**
 * Retrieve stored data merged with defaults.
 */
function wallet_tracker_get_data(): array {
    $stored = get_option(WALLET_TRACKER_OPTION, []);
    $defaults = wallet_tracker_default_data();

    $config = isset($stored['config']) && is_array($stored['config'])
        ? array_merge($defaults['config'], $stored['config'])
        : $defaults['config'];

    $wallets = isset($stored['wallets']) && is_array($stored['wallets'])
        ? $stored['wallets']
        : [];

    $logs = isset($stored['logs']) && is_array($stored['logs'])
        ? $stored['logs']
        : [];

    return [
        'config' => $config,
        'wallets' => $wallets,
        'logs' => $logs,
    ];
}

/**
 * Persist sanitized data structure.
 */
function wallet_tracker_save_data(array $payload): bool {
    return update_option(WALLET_TRACKER_OPTION, $payload);
}

/**
 * Sanitize payload coming from client side before persisting.
 */
function wallet_tracker_sanitize_payload(array $payload): array {
    $clean = wallet_tracker_default_data();

    if (isset($payload['config']) && is_array($payload['config'])) {
        $poll_interval = isset($payload['config']['pollInterval'])
            ? max(15, min(600, intval($payload['config']['pollInterval'])))
            : $clean['config']['pollInterval'];

        $clean['config'] = [
            'ethApiKey' => sanitize_text_field($payload['config']['ethApiKey'] ?? ''),
            'bscApiKey' => sanitize_text_field($payload['config']['bscApiKey'] ?? ''),
            'discordWebhook' => esc_url_raw($payload['config']['discordWebhook'] ?? ''),
            'pollInterval' => $poll_interval,
        ];
    }

    if (isset($payload['wallets']) && is_array($payload['wallets'])) {
        $wallets = [];
        foreach ($payload['wallets'] as $wallet) {
            if (!is_array($wallet)) {
                continue;
            }

            $address = strtolower(sanitize_text_field($wallet['address'] ?? ''));
            if (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
                continue;
            }

            $wallets[] = [
                'id' => sanitize_text_field($wallet['id'] ?? uniqid('wallet_', true)),
                'address' => $address,
                'chain' => ($wallet['chain'] ?? 'eth') === 'bsc' ? 'bsc' : 'eth',
                'label' => sanitize_text_field($wallet['label'] ?? $address),
                'customText' => sanitize_textarea_field($wallet['customText'] ?? '{label} tracked transaction: {amount} {token}'),
                'lastBlock' => isset($wallet['lastBlock']) ? max(0, intval($wallet['lastBlock'])) : 0,
                'lastTxHash' => sanitize_text_field($wallet['lastTxHash'] ?? ''),
            ];
        }
        $clean['wallets'] = $wallets;
    }

    if (isset($payload['logs']) && is_array($payload['logs'])) {
        $logs = [];
        foreach ($payload['logs'] as $log) {
            if (!is_array($log) || empty($log['txHash'])) {
                continue;
            }

            $logs[] = [
                'walletAddress' => strtolower(sanitize_text_field($log['walletAddress'] ?? '')),
                'label' => sanitize_text_field($log['label'] ?? ''),
                'chain' => ($log['chain'] ?? 'eth') === 'bsc' ? 'bsc' : 'eth',
                'txHash' => sanitize_text_field($log['txHash'] ?? ''),
                'token' => sanitize_text_field($log['token'] ?? ''),
                'amount' => sanitize_text_field($log['amount'] ?? ''),
                'timestamp' => sanitize_text_field($log['timestamp'] ?? ''),
                'message' => sanitize_textarea_field($log['message'] ?? ''),
            ];

            if (count($logs) >= 300) {
                break;
            }
        }
        $clean['logs'] = $logs;
    }

    return $clean;
}

/**
 * Ensure the default option exists on activation.
 */
function wallet_tracker_activate(): void {
    if (false === get_option(WALLET_TRACKER_OPTION)) {
        add_option(WALLET_TRACKER_OPTION, wallet_tracker_default_data());
    }
}
register_activation_hook(__FILE__, 'wallet_tracker_activate');

/**
 * Enqueue shared assets and provide AJAX variables.
 */
function wallet_tracker_enqueue_assets(): void {
    wp_enqueue_script('jquery');
    wp_localize_script('jquery', 'walletTrackerAjax', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce(WALLET_TRACKER_NONCE),
    ]);
}

/**
 * Conditionally enqueue assets on the front-end when shortcode exists.
 */
function wallet_tracker_frontend_enqueue(): void {
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'wallet_tracker')) {
        wallet_tracker_enqueue_assets();
    }
}
add_action('wp_enqueue_scripts', 'wallet_tracker_frontend_enqueue');

/**
 * Enqueue assets on admin page load.
 */
function wallet_tracker_admin_enqueue(string $hook): void {
    if ($hook === 'toplevel_page_wallet-tracker') {
        wallet_tracker_enqueue_assets();
    }
}
add_action('admin_enqueue_scripts', 'wallet_tracker_admin_enqueue');

/**
 * AJAX handler to load stored data.
 */
function wallet_tracker_handle_load(): void {
    check_ajax_referer(WALLET_TRACKER_NONCE, 'nonce');
    $data = wallet_tracker_get_data();
    wp_send_json_success($data);
}
add_action('wp_ajax_wallet_tracker_load', 'wallet_tracker_handle_load');
add_action('wp_ajax_nopriv_wallet_tracker_load', 'wallet_tracker_handle_load');

/**
 * AJAX handler to persist data updates.
 */
function wallet_tracker_handle_save(): void {
    check_ajax_referer(WALLET_TRACKER_NONCE, 'nonce');

    if (!isset($_POST['data'])) {
        wp_send_json_error(['message' => 'Missing payload']);
    }

    $decoded = json_decode(wp_unslash($_POST['data']), true);
    if (!is_array($decoded)) {
        wp_send_json_error(['message' => 'Invalid payload']);
    }

    $sanitized = wallet_tracker_sanitize_payload($decoded);
    $saved = wallet_tracker_save_data($sanitized);

    if ($saved) {
        wp_send_json_success(['message' => 'Saved']);
    }

    wp_send_json_error(['message' => 'Failed to persist data']);
}
add_action('wp_ajax_wallet_tracker_save', 'wallet_tracker_handle_save');
add_action('wp_ajax_nopriv_wallet_tracker_save', 'wallet_tracker_handle_save');

/**
 * Register admin menu entry.
 */
function wallet_tracker_admin_menu(): void {
    add_menu_page(
        __('Wallet Tracker', 'wallet-tracker'),
        __('Wallet Tracker', 'wallet-tracker'),
        'manage_options',
        'wallet-tracker',
        'wallet_tracker_render_admin_page',
        'dashicons-chart-line'
    );
}
add_action('admin_menu', 'wallet_tracker_admin_menu');

/**
 * Render admin page using primary shortcode for consistency.
 */
function wallet_tracker_render_admin_page(): void {
    echo do_shortcode('[wallet_tracker]');
}

/**
 * Main shortcode output containing configuration, wallet management, and logs.
 */
function wallet_tracker_shortcode(): string {
    wallet_tracker_enqueue_assets();

    ob_start();
    ?>
    <div class="wallet-tracker-shell">
        <h1><?php esc_html_e('Multi-Chain Wallet Tracker', 'wallet-tracker'); ?></h1>

        <section class="wallet-section">
            <h2><?php esc_html_e('Configuration', 'wallet-tracker'); ?></h2>
            <div class="wallet-grid">
                <label>
                    <?php esc_html_e('Etherscan API Key (ETH):', 'wallet-tracker'); ?>
                    <input type="text" id="ethApiKey" placeholder="<?php esc_attr_e('Required for Ethereum lookups', 'wallet-tracker'); ?>">
                </label>
                <label>
                    <?php esc_html_e('BscScan API Key (BSC):', 'wallet-tracker'); ?>
                    <input type="text" id="bscApiKey" placeholder="<?php esc_attr_e('Required for BSC lookups', 'wallet-tracker'); ?>">
                </label>
                <label>
                    <?php esc_html_e('Discord Webhook URL:', 'wallet-tracker'); ?>
                    <input type="text" id="discordWebhook" placeholder="https://discord.com/api/webhooks/...">
                </label>
                <label>
                    <?php esc_html_e('Polling Interval (seconds):', 'wallet-tracker'); ?>
                    <input type="number" id="pollInterval" min="15" max="600" step="5">
                </label>
            </div>
            <button class="wallet-button" id="saveConfigBtn"><?php esc_html_e('Save Configuration', 'wallet-tracker'); ?></button>
            <div id="configStatus" class="wallet-status"></div>
        </section>

        <section class="wallet-section">
            <h2><?php esc_html_e('Track a Wallet', 'wallet-tracker'); ?></h2>
            <div class="wallet-grid wallet-add-grid">
                <label>
                    <?php esc_html_e('Chain', 'wallet-tracker'); ?>
                    <select id="walletChain">
                        <option value="eth">Ethereum (ETH)</option>
                        <option value="bsc">BNB Smart Chain (BSC)</option>
                    </select>
                </label>
                <label>
                    <?php esc_html_e('Wallet Address', 'wallet-tracker'); ?>
                    <input type="text" id="walletAddress" placeholder="0x...">
                </label>
                <label>
                    <?php esc_html_e('Label', 'wallet-tracker'); ?>
                    <input type="text" id="walletLabel" placeholder="Treasury Wallet">
                </label>
                <label class="wallet-grid-span">
                    <?php esc_html_e('Custom Discord Message', 'wallet-tracker'); ?>
                    <textarea id="walletMessage" rows="2" placeholder="Example: {label} moved {amount} {token}"></textarea>
                </label>
            </div>
            <button class="wallet-button" id="addWalletBtn"><?php esc_html_e('Add Wallet', 'wallet-tracker'); ?></button>
            <div id="walletStatus" class="wallet-status"></div>
        </section>

        <section class="wallet-section">
            <h2><?php esc_html_e('Tracked Wallets', 'wallet-tracker'); ?></h2>
            <div class="wallet-toolbar">
                <input type="text" id="walletSearch" placeholder="<?php esc_attr_e('Search by label or address...', 'wallet-tracker'); ?>">
                <div class="wallet-toolbar-buttons">
                    <button class="wallet-button" id="manualCheckBtn"><?php esc_html_e('Manual Check', 'wallet-tracker'); ?></button>
                    <button class="wallet-button" id="togglePollingBtn"><?php esc_html_e('Toggle Polling', 'wallet-tracker'); ?></button>
                </div>
            </div>
            <div id="pollingStatus" class="wallet-status"></div>
            <div class="wallet-table-wrapper">
                <table class="wallet-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Label', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Address', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Chain', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Last Block', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Custom Message', 'wallet-tracker'); ?></th>
                            <th><?php esc_html_e('Actions', 'wallet-tracker'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="walletTableBody"></tbody>
                </table>
            </div>
        </section>

        <section class="wallet-section">
            <h2><?php esc_html_e('Transaction Logs', 'wallet-tracker'); ?></h2>
            <div class="wallet-toolbar">
                <button class="wallet-button" id="exportLogsBtn"><?php esc_html_e('Export Logs (JSON)', 'wallet-tracker'); ?></button>
                <button class="wallet-button" id="clearLogsBtn"><?php esc_html_e('Clear Logs', 'wallet-tracker'); ?></button>
            </div>
            <div id="logsStatus" class="wallet-status"></div>
            <div id="logsContainer" class="wallet-logs"></div>
        </section>
    </div>

    <style>
        .wallet-tracker-shell {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: #0f172a;
            color: #f1f5f9;
            padding: 20px;
            border-radius: 12px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .wallet-tracker-shell h1 {
            color: #38bdf8;
            margin-bottom: 24px;
        }
        .wallet-section {
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(56, 189, 248, 0.2);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .wallet-section h2 {
            color: #38bdf8;
            margin-top: 0;
        }
        .wallet-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .wallet-grid input,
        .wallet-grid select,
        .wallet-grid textarea {
            width: 100%;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid rgba(148, 163, 184, 0.4);
            background: rgba(15, 23, 42, 0.6);
            color: #e2e8f0;
        }
        .wallet-grid-span {
            grid-column: 1 / -1;
        }
        .wallet-button {
            background: linear-gradient(135deg, #38bdf8, #22d3ee);
            color: #0f172a;
            border: none;
            border-radius: 6px;
            padding: 10px 16px;
            cursor: pointer;
            font-weight: 600;
            margin-right: 10px;
        }
        .wallet-button:hover {
            filter: brightness(1.05);
        }
        .wallet-status {
            display: none;
            margin-top: 10px;
            padding: 8px 12px;
            border-radius: 6px;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid transparent;
        }
        .wallet-status.success {
            border-color: rgba(34, 197, 94, 0.6);
            color: #bbf7d0;
        }
        .wallet-status.error {
            border-color: rgba(248, 113, 113, 0.6);
            color: #fecaca;
        }
        .wallet-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
        }
        .wallet-toolbar input[type="text"] {
            flex: 1 1 260px;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid rgba(148, 163, 184, 0.4);
            background: rgba(15, 23, 42, 0.6);
            color: #e2e8f0;
        }
        .wallet-toolbar-buttons {
            display: flex;
            gap: 10px;
        }
        .wallet-table-wrapper {
            overflow-x: auto;
        }
        .wallet-table {
            width: 100%;
            border-collapse: collapse;
        }
        .wallet-table th,
        .wallet-table td {
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
            padding: 10px 8px;
            text-align: left;
        }
        .wallet-table th {
            color: #67e8f9;
            font-weight: 600;
        }
        .wallet-table td.actions {
            width: 120px;
        }
        .wallet-remove {
            background: linear-gradient(135deg, #f87171, #ef4444);
            color: #0f172a;
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
        }
        .wallet-remove:hover {
            filter: brightness(1.05);
        }
        .wallet-logs {
            max-height: 420px;
            overflow-y: auto;
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 8px;
            padding: 10px;
            background: rgba(15, 23, 42, 0.6);
        }
        .wallet-log-entry {
            border-left: 3px solid #38bdf8;
            padding: 10px;
            margin-bottom: 10px;
            background: rgba(30, 41, 59, 0.8);
            border-radius: 6px;
        }
        .wallet-log-entry:last-child {
            margin-bottom: 0;
        }
        .wallet-log-header {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: baseline;
            margin-bottom: 6px;
        }
        .wallet-log-header strong {
            color: #bae6fd;
        }
        .wallet-log-entry a {
            color: #38bdf8;
            text-decoration: none;
            word-break: break-all;
        }
        .wallet-log-entry a:hover {
            text-decoration: underline;
        }
        @media (max-width: 720px) {
            .wallet-table thead {
                display: none;
            }
            .wallet-table tr {
                display: block;
                margin-bottom: 12px;
                border: 1px solid rgba(148, 163, 184, 0.2);
                border-radius: 8px;
                padding: 12px;
                background: rgba(15, 23, 42, 0.5);
            }
            .wallet-table td {
                display: flex;
                justify-content: space-between;
                border-bottom: none;
                padding: 6px 0;
            }
            .wallet-table td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #67e8f9;
            }
        }
    </style>

    <script>
    (function(){
        const ajaxMeta = window.walletTrackerAjax || {
            ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
            nonce: '<?php echo esc_js(wp_create_nonce(WALLET_TRACKER_NONCE)); ?>'
        };

        const SCAN_APIS = {
            eth: 'https://api.etherscan.io/api',
            bsc: 'https://api.bscscan.com/api'
        };

        let data = { config: {}, wallets: [], logs: [] };
        let config = { ethApiKey: '', bscApiKey: '', discordWebhook: '', pollInterval: 60 };
        let wallets = [];
        let logs = [];
        let pollTimer = null;
        let isPollingEnabled = (localStorage.getItem('walletTrackerPolling') || 'on') !== 'off';
        let searchTerm = '';
        let isChecking = false;

        const elements = {
            ethApiKey: document.getElementById('ethApiKey'),
            bscApiKey: document.getElementById('bscApiKey'),
            discordWebhook: document.getElementById('discordWebhook'),
            pollInterval: document.getElementById('pollInterval'),
            saveConfigBtn: document.getElementById('saveConfigBtn'),
            walletChain: document.getElementById('walletChain'),
            walletAddress: document.getElementById('walletAddress'),
            walletLabel: document.getElementById('walletLabel'),
            walletMessage: document.getElementById('walletMessage'),
            addWalletBtn: document.getElementById('addWalletBtn'),
            walletStatus: document.getElementById('walletStatus'),
            configStatus: document.getElementById('configStatus'),
            manualCheckBtn: document.getElementById('manualCheckBtn'),
            togglePollingBtn: document.getElementById('togglePollingBtn'),
            pollingStatus: document.getElementById('pollingStatus'),
            walletTableBody: document.getElementById('walletTableBody'),
            walletSearch: document.getElementById('walletSearch'),
            logsContainer: document.getElementById('logsContainer'),
            logsStatus: document.getElementById('logsStatus'),
            exportLogsBtn: document.getElementById('exportLogsBtn'),
            clearLogsBtn: document.getElementById('clearLogsBtn'),
        };

        async function loadData() {
            const formData = new FormData();
            formData.append('action', 'wallet_tracker_load');
            formData.append('nonce', ajaxMeta.nonce);

            try {
                const response = await fetch(ajaxMeta.ajaxUrl, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    return result.data;
                }
            } catch (error) {
                console.error('Failed to load wallet tracker data', error);
            }
            return wallet_tracker_defaults();
        }

        function wallet_tracker_defaults() {
            return {
                config: { ethApiKey: '', bscApiKey: '', discordWebhook: '', pollInterval: 60 },
                wallets: [],
                logs: []
            };
        }

        async function saveData(payload, statusEl) {
            const formData = new FormData();
            formData.append('action', 'wallet_tracker_save');
            formData.append('nonce', ajaxMeta.nonce);
            formData.append('data', JSON.stringify(payload));

            try {
                const response = await fetch(ajaxMeta.ajaxUrl, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    if (statusEl) {
                        showStatus(statusEl, 'Saved successfully.', 'success');
                    }
                    return true;
                }
                if (statusEl) {
                    const message = result.data && result.data.message ? result.data.message : 'Failed to save.';
                    showStatus(statusEl, message, 'error');
                }
            } catch (error) {
                console.error('Failed to save wallet tracker data', error);
                if (statusEl) {
                    showStatus(statusEl, 'Unexpected error saving data.', 'error');
                }
            }
            return false;
        }

        function showStatus(target, message, type) {
            const el = typeof target === 'string' ? document.getElementById(target) : target;
            if (!el) {
                return;
            }
            el.textContent = message;
            el.classList.remove('success', 'error');
            if (type) {
                el.classList.add(type);
            }
            if (message) {
                el.style.display = 'block';
                setTimeout(() => {
                    el.textContent = '';
                    el.classList.remove('success', 'error');
                    el.style.display = 'none';
                }, 4000);
            }
        }

        function loadConfigForm() {
            if (elements.ethApiKey) elements.ethApiKey.value = config.ethApiKey || '';
            if (elements.bscApiKey) elements.bscApiKey.value = config.bscApiKey || '';
            if (elements.discordWebhook) elements.discordWebhook.value = config.discordWebhook || '';
            if (elements.pollInterval) elements.pollInterval.value = config.pollInterval || 60;
        }

        function renderWallets() {
            if (!elements.walletTableBody) {
                return;
            }
            const filtered = wallets.filter(wallet => {
                if (!searchTerm) {
                    return true;
                }
                const lcTerm = searchTerm.toLowerCase();
                return wallet.label.toLowerCase().includes(lcTerm) || wallet.address.toLowerCase().includes(lcTerm);
            });

            if (filtered.length === 0) {
                elements.walletTableBody.innerHTML = '<tr><td colspan="6">No wallets tracked yet.</td></tr>';
                return;
            }

            const rows = filtered.map(wallet => {
                const truncatedAddress = wallet.address.slice(0, 6) + '...' + wallet.address.slice(-4);
                const messagePreview = wallet.customText || '';
                return `
                    <tr>
                        <td data-label="Label">${escapeHtml(wallet.label)}</td>
                        <td data-label="Address"><span title="${escapeHtml(wallet.address)}">${escapeHtml(truncatedAddress)}</span></td>
                        <td data-label="Chain">${wallet.chain.toUpperCase()}</td>
                        <td data-label="Last Block">${wallet.lastBlock || 0}</td>
                        <td data-label="Message">${escapeHtml(messagePreview)}</td>
                        <td data-label="Actions" class="actions">
                            <button class="wallet-remove" data-id="${wallet.id}">Remove</button>
                        </td>
                    </tr>
                `;
            }).join('');

            elements.walletTableBody.innerHTML = rows;
            elements.walletTableBody.querySelectorAll('.wallet-remove').forEach(button => {
                button.addEventListener('click', () => {
                    removeWallet(button.getAttribute('data-id'));
                });
            });
        }

        function renderLogs() {
            if (!elements.logsContainer) {
                return;
            }
            if (!logs.length) {
                elements.logsContainer.innerHTML = '<p>No logs available yet.</p>';
                return;
            }

            const entries = logs.map(log => {
                const explorer = getExplorerUrl(log.chain, log.txHash);
                const timestamp = log.timestamp ? new Date(log.timestamp).toLocaleString() : '';
                const messageHtml = log.message ? `<div>${escapeHtml(log.message).replace(/\\n/g, '<br>')}</div>` : '';
                return `
                    <div class="wallet-log-entry">
                        <div class="wallet-log-header">
                            <strong>${escapeHtml(log.label || log.walletAddress.slice(0, 8) + '...')}</strong>
                            <span>${log.chain.toUpperCase()}</span>
                            <span>${escapeHtml(timestamp)}</span>
                        </div>
                        <div>
                            Token: <strong>${escapeHtml(log.token || 'Unknown')}</strong>
                            &nbsp;•&nbsp; Amount: <strong>${escapeHtml(log.amount)}</strong>
                        </div>
                        <div>
                            Tx: <a href="${explorer}" target="_blank" rel="noopener noreferrer">${escapeHtml(log.txHash)}</a>
                        </div>
                        ${messageHtml}
                    </div>
                `;
            }).join('');
            elements.logsContainer.innerHTML = entries;
        }

        function escapeHtml(value) {
            if (typeof value !== 'string') {
                return value;
            }
            return value
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function getExplorerUrl(chain, hash) {
            if (chain === 'bsc') {
                return `https://bscscan.com/tx/${hash}`;
            }
            return `https://etherscan.io/tx/${hash}`;
        }

        async function saveConfig() {
            config.ethApiKey = elements.ethApiKey ? elements.ethApiKey.value.trim() : '';
            config.bscApiKey = elements.bscApiKey ? elements.bscApiKey.value.trim() : '';
            config.discordWebhook = elements.discordWebhook ? elements.discordWebhook.value.trim() : '';
            const poll = elements.pollInterval ? parseInt(elements.pollInterval.value, 10) : 60;
            config.pollInterval = Number.isFinite(poll) ? Math.min(Math.max(poll, 15), 600) : 60;
            data.config = { ...config };

            const saved = await saveData(data);
            if (saved) {
                showStatus(elements.configStatus, 'Configuration saved.', 'success');
            } else {
                showStatus(elements.configStatus, 'Failed to save configuration.', 'error');
            }
            if (saved && isPollingEnabled) {
                startPolling();
            }
        }

        async function addWallet() {
            const chain = elements.walletChain ? elements.walletChain.value : 'eth';
            const address = elements.walletAddress ? elements.walletAddress.value.trim().toLowerCase() : '';
            const label = elements.walletLabel ? elements.walletLabel.value.trim() : '';
            const message = elements.walletMessage ? elements.walletMessage.value.trim() : '';

            if (!/^0x[a-fA-F0-9]{40}$/.test(address)) {
                showStatus(elements.walletStatus, 'Enter a valid wallet address.', 'error');
                return;
            }
            if (!label) {
                showStatus(elements.walletStatus, 'Provide a label for the wallet.', 'error');
                return;
            }

            const wallet = {
                id: `wallet_${Date.now()}`,
                address,
                chain: chain === 'bsc' ? 'bsc' : 'eth',
                label,
                customText: message || '{label} tracked transaction: {amount} {token}',
                lastBlock: 0,
                lastTxHash: ''
            };

            wallets.push(wallet);
            data.wallets = [...wallets];
            const persisted = await saveData(data);
            if (!persisted) {
                wallets.pop();
                data.wallets = [...wallets];
                showStatus(elements.walletStatus, 'Failed to save wallet. Try again.', 'error');
                return;
            }

            elements.walletAddress.value = '';
            elements.walletLabel.value = '';
            if (elements.walletMessage) {
                elements.walletMessage.value = '';
            }

            await initializeWalletBlock(wallet);
            renderWallets();
            updatePollingStatus();
            showStatus(elements.walletStatus, 'Wallet added successfully.', 'success');
        }

        async function initializeWalletBlock(wallet) {
            const apiKey = wallet.chain === 'bsc' ? config.bscApiKey : config.ethApiKey;
            if (!apiKey) {
                return;
            }
            const params = new URLSearchParams({
                module: 'proxy',
                action: 'eth_blockNumber',
                apikey: apiKey
            });
            try {
                const response = await fetch(`${SCAN_APIS[wallet.chain]}?${params.toString()}`);
                const json = await response.json();
                if (json && json.result) {
                    const currentBlock = parseInt(json.result, 16);
                    wallet.lastBlock = Number.isFinite(currentBlock) ? Math.max(currentBlock - 1, 0) : 0;
                    data.wallets = [...wallets];
                    await saveData(data);
                }
            } catch (error) {
                console.error('Failed to initialise wallet block', error);
            }
        }

        async function removeWallet(id) {
            const index = wallets.findIndex(w => w.id === id);
            if (index === -1) {
                return;
            }

            const removedWallet = wallets[index];
            wallets.splice(index, 1);
            data.wallets = [...wallets];
            const originalLogs = [...logs];
            logs = logs.filter(log => log.walletAddress !== removedWallet.address);
            data.logs = [...logs];

            const saved = await saveData(data);
            if (!saved) {
                wallets.splice(index, 0, removedWallet);
                logs = originalLogs;
                data.wallets = [...wallets];
                data.logs = [...logs];
                showStatus(elements.walletStatus, 'Failed to remove wallet. Refresh and try again.', 'error');
                renderWallets();
                renderLogs();
                updatePollingStatus();
                return;
            }

            renderWallets();
            renderLogs();
            updatePollingStatus();
            showStatus(elements.walletStatus, 'Wallet removed.', 'success');
        }

        async function manualCheck() {
            await checkAllWallets();
        }

        function togglePolling() {
            isPollingEnabled = !isPollingEnabled;
            localStorage.setItem('walletTrackerPolling', isPollingEnabled ? 'on' : 'off');
            if (isPollingEnabled) {
                startPolling();
            } else {
                stopPolling();
            }
            updatePollingStatus();
        }

        function updatePollingStatus() {
            if (!elements.pollingStatus) {
                return;
            }
            if (!wallets.length) {
                elements.pollingStatus.textContent = 'Add at least one wallet to enable polling.';
                elements.pollingStatus.classList.remove('success', 'error');
                return;
            }
            if (!hasRequiredApiKeys()) {
                elements.pollingStatus.textContent = 'Provide both API keys before polling can start.';
                elements.pollingStatus.classList.add('error');
                return;
            }
            if (isPollingEnabled && pollTimer) {
                elements.pollingStatus.textContent = `Polling every ${config.pollInterval} seconds.`;
                elements.pollingStatus.classList.add('success');
            } else {
                elements.pollingStatus.textContent = 'Polling paused.';
                elements.pollingStatus.classList.remove('success');
            }
        }

        function hasRequiredApiKeys() {
            if (!wallets.length) {
                return false;
            }
            const needsEth = wallets.some(w => w.chain === 'eth');
            const needsBsc = wallets.some(w => w.chain === 'bsc');
            if (needsEth && !config.ethApiKey) {
                return false;
            }
            if (needsBsc && !config.bscApiKey) {
                return false;
            }
            return true;
        }

        function startPolling() {
            stopPolling();
            if (!hasRequiredApiKeys()) {
                updatePollingStatus();
                return;
            }
            const intervalMs = (config.pollInterval || 60) * 1000;
            pollTimer = setInterval(checkAllWallets, intervalMs);
            checkAllWallets();
            updatePollingStatus();
        }

        function stopPolling() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        async function checkAllWallets() {
            if (isChecking) {
                return;
            }
            isChecking = true;
            try {
                for (const wallet of wallets) {
                    await checkWallet(wallet);
                }
                renderWallets();
                renderLogs();
            } finally {
                isChecking = false;
            }
        }

        async function checkWallet(wallet) {
            const apiKey = wallet.chain === 'bsc' ? config.bscApiKey : config.ethApiKey;
            if (!apiKey) {
                return;
            }
            const startBlock = wallet.lastBlock ? wallet.lastBlock + 1 : 0;
            const params = new URLSearchParams({
                module: 'account',
                action: 'tokentx',
                address: wallet.address,
                startblock: startBlock.toString(),
                endblock: '99999999',
                sort: 'asc',
                page: '1',
                offset: '100',
                apikey: apiKey
            });

            try {
                const response = await fetch(`${SCAN_APIS[wallet.chain]}?${params.toString()}`);
                const json = await response.json();
                if (!json || !json.result || !Array.isArray(json.result) || !json.result.length) {
                    return;
                }

                const newTxs = [];
                for (const tx of json.result) {
                    const blockNumber = parseInt(tx.blockNumber, 10);
                    if (Number.isFinite(blockNumber) && blockNumber >= startBlock) {
                        newTxs.push(tx);
                    }
                }

                if (!newTxs.length) {
                    return;
                }

                let highestBlock = wallet.lastBlock || 0;
                const logEntries = [];
                for (const tx of newTxs) {
                    const blockNumber = parseInt(tx.blockNumber, 10);
                    if (Number.isFinite(blockNumber)) {
                        highestBlock = Math.max(highestBlock, blockNumber);
                    }

                    const tokenSymbol = tx.tokenSymbol || 'Unknown';
                    const amount = formatTokenAmount(tx.value || '0', parseInt(tx.tokenDecimal || '18', 10));
                    const timestamp = tx.timeStamp ? new Date(parseInt(tx.timeStamp, 10) * 1000).toISOString() : new Date().toISOString();
                    const message = (wallet.customText || '')
                        .replace(/\{label\}/gi, wallet.label)
                        .replace(/\{token\}/gi, tokenSymbol)
                        .replace(/\{amount\}/gi, amount);

                    const entry = {
                        walletAddress: wallet.address,
                        label: wallet.label,
                        chain: wallet.chain,
                        txHash: tx.hash,
                        token: tokenSymbol,
                        amount,
                        timestamp,
                        message
                    };

                    if (logs.find(existing => existing.txHash === entry.txHash)) {
                        continue;
                    }

                    logEntries.push(entry);
                }

                if (!logEntries.length) {
                    wallet.lastBlock = highestBlock;
                    data.wallets = [...wallets];
                    await saveData(data);
                    return;
                }

                const chronologicalEntries = [...logEntries];
                wallet.lastBlock = highestBlock;
                wallet.lastTxHash = chronologicalEntries[chronologicalEntries.length - 1]?.txHash || wallet.lastTxHash;
                const newestFirst = [...chronologicalEntries].reverse();
                logs = [...newestFirst, ...logs];
                if (logs.length > 300) {
                    logs = logs.slice(0, 300);
                }
                data.logs = [...logs];
                data.wallets = [...wallets];

                const saveSuccess = await saveData(data);
                if (!saveSuccess) {
                    console.error('Failed to persist logs after new transactions');
                }

                renderLogs();

                for (const entry of chronologicalEntries) {
                    await sendDiscordAlert(entry, wallet);
                }
            } catch (error) {
                console.error(`Failed to check wallet ${wallet.address}`, error);
            }
        }

        function formatTokenAmount(value, decimals) {
            try {
                const bigValue = BigInt(value);
                const base = BigInt(10) ** BigInt(Math.max(decimals, 0));
                const whole = bigValue / base;
                const remainder = bigValue % base;
                if (remainder === BigInt(0)) {
                    return whole.toString();
                }
                const remainderStr = remainder.toString().padStart(Math.max(decimals, 0), '0').replace(/0+$/, '');
                return `${whole.toString()}.${remainderStr.slice(0, 8)}`;
            } catch (error) {
                const floatVal = parseFloat(value) / Math.pow(10, Math.max(decimals, 0));
                return floatVal.toFixed(6).replace(/\.0+$/, '').replace(/0+$/, '');
            }
        }

        async function sendDiscordAlert(entry, wallet) {
            if (!config.discordWebhook) {
                return;
            }
            const explorer = getExplorerUrl(wallet.chain, entry.txHash);
            const payload = {
                content: `**${wallet.label} (${wallet.chain.toUpperCase()})**\nToken: ${entry.token}\nAmount: ${entry.amount}\nHash: ${explorer}\n${entry.message}`
            };

            try {
                await fetch(config.discordWebhook, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            } catch (error) {
                console.error('Failed to send Discord alert', error);
            }
        }

        async function clearLogs() {
            logs = [];
            data.logs = [];
            const saved = await saveData(data);
            if (saved) {
                showStatus(elements.logsStatus, 'Logs cleared.', 'success');
            } else {
                showStatus(elements.logsStatus, 'Failed to clear logs.', 'error');
            }
            renderLogs();
        }

        function exportLogs() {
            const blob = new Blob([JSON.stringify(logs, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'wallet-tracker-logs.json';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        function attachEvents() {
            if (elements.saveConfigBtn) {
                elements.saveConfigBtn.addEventListener('click', saveConfig);
            }
            if (elements.addWalletBtn) {
                elements.addWalletBtn.addEventListener('click', addWallet);
            }
            if (elements.manualCheckBtn) {
                elements.manualCheckBtn.addEventListener('click', manualCheck);
            }
            if (elements.togglePollingBtn) {
                elements.togglePollingBtn.addEventListener('click', togglePolling);
            }
            if (elements.walletSearch) {
                elements.walletSearch.addEventListener('input', (event) => {
                    searchTerm = event.target.value || '';
                    renderWallets();
                });
            }
            if (elements.clearLogsBtn) {
                elements.clearLogsBtn.addEventListener('click', clearLogs);
            }
            if (elements.exportLogsBtn) {
                elements.exportLogsBtn.addEventListener('click', exportLogs);
            }
        }

        document.addEventListener('DOMContentLoaded', async () => {
            attachEvents();
            data = await loadData();
            config = { ...wallet_tracker_defaults().config, ...(data.config || {}) };
            wallets = Array.isArray(data.wallets) ? data.wallets : [];
            logs = Array.isArray(data.logs) ? data.logs : [];

            loadConfigForm();
            renderWallets();
            renderLogs();
            updatePollingStatus();

            if (isPollingEnabled) {
                startPolling();
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('wallet_tracker', 'wallet_tracker_shortcode');

?>
