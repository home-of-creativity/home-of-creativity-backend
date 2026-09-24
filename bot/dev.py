import os
from pathlib import Path

import httpx
from dotenv import load_dotenv
from telegram import Update
from telegram.ext import Application, CommandHandler, ContextTypes

from telegram_http import run_application, telegram_request

load_dotenv(Path(__file__).resolve().parents[1] / ".env")
load_dotenv()

_local_api = (os.environ.get("HOC_LOCAL_API_URL") or "http://127.0.0.1:8000").rstrip("/")
_origin = (os.environ.get("HOC_API_URL") or _local_api).rstrip("/")
if "trycloudflare.com" in _origin:
    _origin = _local_api
API_URL = _origin if _origin.endswith("/api") else f"{_origin}/api"
BOT_SECRET = os.environ.get("TELEGRAM_DEV_BOT_SECRET", "change-me-dev")
API_TIMEOUT = httpx.Timeout(20.0)
DEV_IDS = {
    item.strip()
    for item in (os.environ.get("TELEGRAM_DEV_IDS") or "").split(",")
    if item.strip()
}


def api_headers() -> dict[str, str]:
    return {
        "Accept": "application/json",
        "X-Webhook-Secret": BOT_SECRET,
    }


async def request_json(method: str, path: str) -> httpx.Response:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        return await client.request(method, f"{API_URL}{path}", headers=api_headers())


def allowed(update: Update) -> bool:
    user = update.effective_user
    return user is not None and str(user.id) in DEV_IDS


async def ping(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None:
        return
    if not allowed(update):
        await update.message.reply_text("هذا البوت لمطوري Home of Creativity فقط.")
        return
    response = await request_json("GET", "/bot/dev/ping")
    if response.status_code >= 400:
        await update.message.reply_text("تعذر الوصول إلى واجهة المطور.")
        return
    await update.message.reply_text("pong")


async def status(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None:
        return
    if not allowed(update):
        await update.message.reply_text("هذا البوت لمطوري Home of Creativity فقط.")
        return
    response = await request_json("GET", "/bot/dev/status")
    if response.status_code >= 400:
        await update.message.reply_text("تعذر قراءة حالة السيرفر.")
        return
    data = response.json().get("data") or {}
    state = "يعمل" if data.get("up") else "Server Down"
    await update.message.reply_text(f"{state}\n{data.get('health_url', '')}")


def main() -> None:
    token = (os.environ.get("TELEGRAM_DEV_BOT_TOKEN") or "").strip()
    if token == "":
        raise SystemExit("TELEGRAM_DEV_BOT_TOKEN is empty")
    application = (
        Application.builder()
        .token(token)
        .request(telegram_request())
        .get_updates_request(telegram_request(long_polling=True))
        .build()
    )
    application.add_handler(CommandHandler("ping", ping))
    application.add_handler(CommandHandler("status", status))
    application.add_handler(CommandHandler("start", status))
    run_application(application, port=8447, url_path="dev")


if __name__ == "__main__":
    main()
