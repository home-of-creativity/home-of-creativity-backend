import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import {
  Browsers,
  DisconnectReason,
  downloadMediaMessage,
  fetchLatestBaileysVersion,
  makeWASocket,
  useMultiFileAuthState,
} from "@whiskeysockets/baileys";
import pino from "pino";
import QRCode from "qrcode";

const port = Number(process.env.WHATSAPP_WEB_PORT || 8090);
const secret = process.env.WHATSAPP_WEB_SECRET || process.env.TELEGRAM_BOT_SECRET || "";
const apiBase = (process.env.HOC_API_URL || "http://127.0.0.1:8000/api").replace(/\/$/, "");
const authDir = process.env.WHATSAPP_WEB_AUTH_DIR || path.resolve("../../storage/app/whatsapp-web");
const logger = pino({ level: "warn" });

let sock = null;
let connected = false;
let qrDataUrl = "";
let linkedPhone = "";
let generation = 0;
const jidByDigits = new Map();
const menuByDigits = new Map();

fs.mkdirSync(authDir, { recursive: true });

function authorized(req) {
  return secret !== "" && req.headers["x-webhook-secret"] === secret;
}

function phoneFromJid(jid) {
  if (!jid || jid.endsWith("@g.us") || jid === "status@broadcast") {
    return "";
  }
  return jid.replace(/@.*/, "").replace(/\D/g, "");
}

function jidFromPhone(phone) {
  const digits = String(phone || "").replace(/\D/g, "");
  if (!digits) {
    return "";
  }
  return jidByDigits.get(digits) || `${digits}@s.whatsapp.net`;
}

function rememberJid(digits, jid) {
  const clean = String(digits || "").replace(/\D/g, "");
  if (clean && jid) {
    jidByDigits.set(clean, jid);
  }
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on("data", (chunk) => {
      size += chunk.length;
      if (size > 12_000_000) {
        reject(new Error("payload too large"));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on("end", () => {
      const raw = Buffer.concat(chunks).toString("utf8");
      if (!raw) {
        resolve({});
        return;
      }
      try {
        resolve(JSON.parse(raw));
      } catch {
        reject(new Error("invalid json"));
      }
    });
    req.on("error", reject);
  });
}

function sendJson(res, status, body) {
  const raw = JSON.stringify(body);
  res.writeHead(status, { "content-type": "application/json" });
  res.end(raw);
}

async function postLaravel(payload) {
  const response = await fetch(`${apiBase}/bot/whatsapp/web`, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      accept: "application/json",
      "x-webhook-secret": secret,
    },
    body: JSON.stringify(payload),
  });
  if (!response.ok) {
    logger.warn({ status: response.status }, "Laravel rejected a WhatsApp Web message");
  }
}

function messageText(message) {
  return (
    message?.conversation ||
    message?.extendedTextMessage?.text ||
    message?.imageMessage?.caption ||
    message?.documentMessage?.caption ||
    ""
  );
}

function buttonId(message) {
  return (
    message?.buttonsResponseMessage?.selectedButtonId ||
    message?.templateButtonReplyMessage?.selectedId ||
    message?.listResponseMessage?.singleSelectReply?.selectedRowId ||
    ""
  );
}

function westernDigits(value) {
  return String(value || "").replace(/[٠-٩]/g, (digit) => "٠١٢٣٤٥٦٧٨٩".indexOf(digit));
}

function choiceId(phone, text) {
  const rows = menuByDigits.get(phone);
  if (!rows) {
    return "";
  }
  const typed = westernDigits(text).trim();
  const number = /^(\d{1,2})$/.exec(typed);
  if (number) {
    return rows[Number(number[1]) - 1]?.id || "";
  }
  const match = rows.find((row) => row.title === typed);
  return match?.id || "";
}

async function forwardMessage(msg) {
  const remoteJid = msg.key?.remoteJid || "";
  const senderPn = msg.key?.senderPn || msg.key?.remoteJidAlt || "";
  const replyJid = remoteJid || senderPn;
  const phone = phoneFromJid(senderPn) || phoneFromJid(remoteJid);
  rememberJid(phone, replyJid);
  rememberJid(phoneFromJid(remoteJid), replyJid);
  if (!phone || msg.key?.fromMe) {
    return;
  }
  logger.warn({ lid: remoteJid.endsWith("@lid") }, "WhatsApp Web received a chat message");
  const message = msg.message || {};
  const text = messageText(message);
  const payload = {
    phone,
    profile_name: msg.pushName || "",
    message_id: msg.key.id || `${Date.now()}`,
    text,
    button_id: buttonId(message) || choiceId(phone, text),
  };
  const image = message.imageMessage;
  const document = message.documentMessage;
  const audio = message.audioMessage;
  const node = image || document;
  if (node) {
    const buffer = await downloadMediaMessage(msg, "buffer", {}, { logger, reuploadRequest: sock.updateMediaMessage });
    if (buffer && buffer.length > 0 && buffer.length <= 8_000_000) {
      payload.media = {
        kind: image ? "image" : "document",
        mime: node.mimetype || (image ? "image/jpeg" : "application/octet-stream"),
        filename: node.fileName || (image ? "image.jpg" : "file"),
        data_base64: Buffer.from(buffer).toString("base64"),
      };
    }
  } else if (audio) {
    const buffer = await downloadMediaMessage(msg, "buffer", {}, { logger, reuploadRequest: sock.updateMediaMessage });
    if (buffer && buffer.length > 0 && buffer.length <= 8_000_000) {
      payload.media = {
        kind: "audio",
        mime: audio.mimetype || "audio/ogg",
        filename: "voice.ogg",
        data_base64: Buffer.from(buffer).toString("base64"),
      };
    }
  }
  await postLaravel(payload);
}

async function start() {
  const mine = ++generation;
  try {
    const { state, saveCreds } = await useMultiFileAuthState(authDir);
    const { version } = await fetchLatestBaileysVersion();
    if (mine !== generation) {
      return;
    }
    sock = makeWASocket({
      version,
      auth: state,
      logger,
      printQRInTerminal: false,
      browser: Browsers.macOS("Home of Creativity"),
      syncFullHistory: false,
      markOnlineOnConnect: false,
    });
    sock.ev.on("creds.update", saveCreds);
    sock.ev.on("connection.update", async (update) => {
      if (mine !== generation) {
        return;
      }
      if (update.qr) {
        connected = false;
        linkedPhone = "";
        qrDataUrl = await QRCode.toDataURL(update.qr);
        logger.warn("WhatsApp Web is waiting for a phone scan");
      }
      if (update.connection === "open") {
        connected = true;
        linkedPhone = phoneFromJid(sock?.user?.id);
        qrDataUrl = "";
        logger.warn("WhatsApp Web is linked");
      }
      if (update.connection === "close") {
        connected = false;
        linkedPhone = "";
        const code = update.lastDisconnect?.error?.output?.statusCode;
        const loggedOut = code === DisconnectReason.loggedOut;
        if (loggedOut) {
          fs.rmSync(authDir, { recursive: true, force: true });
          fs.mkdirSync(authDir, { recursive: true });
        }
        setTimeout(() => {
          if (mine === generation) {
            start();
          }
        }, loggedOut ? 1000 : 4000);
      }
    });
    sock.ev.on("messages.upsert", async ({ messages, type }) => {
      if (mine !== generation || type !== "notify") {
        return;
      }
      for (const msg of messages) {
        try {
          await forwardMessage(msg);
        } catch (error) {
          logger.warn({ err: error }, "WhatsApp Web inbound failed");
        }
      }
    });
  } catch (error) {
    logger.warn({ err: error }, "WhatsApp Web failed to start");
    setTimeout(() => {
      if (mine === generation) {
        start();
      }
    }, 5000);
  }
}

async function sendToWhatsApp(body) {
  if (!sock || !connected) {
    throw new Error("WhatsApp Web is not linked");
  }
  const jid = jidFromPhone(body.to);
  if (!jid) {
    throw new Error("Missing phone");
  }
  const kind = body.kind || "text";
  if (kind === "list") {
    const rows = (Array.isArray(body.buttons) ? body.buttons : [])
      .slice(0, 10)
      .map((row) => ({
        id: String(row.id || ""),
        title: String(row.title || "خيار").slice(0, 24),
      }))
      .filter((row) => row.id !== "" && row.title !== "");
    const digits = String(body.to || "").replace(/\D/g, "");
    if (digits && rows.length > 0) {
      menuByDigits.set(digits, rows);
    }
    const intro = String(body.text || "").replaceAll("من الأزرار أدناه", "برقم الخيار:");
    const lines = rows.map((row, index) => `${index + 1}. ${row.title}`);
    const sent = await sock.sendMessage(jid, {
      text: lines.length > 0 ? `${intro}\n\n${lines.join("\n")}` : intro,
    });
    return sent?.key?.id || "ok";
  }
  if (kind === "image" || kind === "document") {
    const buffer = Buffer.from(String(body.data_base64 || ""), "base64");
    if (buffer.length === 0) {
      throw new Error("Missing media");
    }
    const content =
      kind === "image"
        ? { image: buffer, caption: body.caption || undefined }
        : {
            document: buffer,
            mimetype: body.mime || "application/octet-stream",
            fileName: body.filename || "file",
            caption: body.caption || undefined,
          };
    const sent = await sock.sendMessage(jid, content);
    return sent?.key?.id || "ok";
  }
  const sent = await sock.sendMessage(jid, { text: String(body.text || "") });
  return sent?.key?.id || "ok";
}

async function unlinkPhone() {
  const current = sock;
  generation += 1;
  sock = null;
  connected = false;
  linkedPhone = "";
  qrDataUrl = "";
  try {
    await current?.logout();
  } catch {
    // The session is already gone. Clearing the files still starts a fresh code.
  }
  fs.rmSync(authDir, { recursive: true, force: true });
  fs.mkdirSync(authDir, { recursive: true });
  start();
}

const server = http.createServer(async (req, res) => {
  if (!authorized(req)) {
    sendJson(res, 401, { message: "Invalid webhook secret." });
    return;
  }
  const url = new URL(req.url || "/", "http://localhost");
  try {
    if (req.method === "GET" && url.pathname === "/status") {
      sendJson(res, 200, { connected, qr: qrDataUrl || null, phone: linkedPhone || null });
      return;
    }
    if (req.method === "POST" && url.pathname === "/logout") {
      await unlinkPhone();
      sendJson(res, 200, { connected: false, qr: null, phone: null });
      return;
    }
    if (req.method === "POST" && url.pathname === "/send") {
      const body = await readBody(req);
      const id = await sendToWhatsApp(body);
      sendJson(res, 200, { id });
      return;
    }
    sendJson(res, 404, { message: "Not found" });
  } catch (error) {
    sendJson(res, 502, { message: error instanceof Error ? error.message : "WhatsApp Web failed" });
  }
});

server.listen(port, "0.0.0.0", () => {
  logger.warn({ port }, "WhatsApp Web bridge listening");
  start();
});
