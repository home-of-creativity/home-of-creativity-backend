import base64
import os
from html import escape
from io import BytesIO
from pathlib import Path

import httpx
from dotenv import load_dotenv
from telegram import InlineKeyboardButton, InlineKeyboardMarkup, KeyboardButton, ReplyKeyboardMarkup, Update
from telegram.error import NetworkError, TimedOut
from telegram.ext import (
    Application,
    CallbackQueryHandler,
    CommandHandler,
    ContextTypes,
    ConversationHandler,
    MessageHandler,
    filters,
)

from telegram_http import run_application, telegram_request

load_dotenv(Path(__file__).resolve().parents[1] / ".env")
load_dotenv()

(
    WAITING_TITLE,
    WAITING_BODY,
    WAITING_EDIT_TITLE,
    WAITING_EDIT_BODY,
    WAITING_REJECT_REASON,
    WAITING_REVISION_REASON,
    WAITING_SUPPORT,
    WAITING_RECEIPT,
) = range(8)

_local_api = (os.environ.get("HOC_LOCAL_API_URL") or "http://127.0.0.1:8001").rstrip("/")
_origin = (os.environ.get("HOC_API_URL") or _local_api).rstrip("/")
if "trycloudflare.com" in _origin:
    _origin = _local_api
API_URL = _origin if _origin.endswith("/api") else f"{_origin}/api"
BOT_SECRET = os.environ.get("TELEGRAM_BOT_SECRET", "")
BTN_NEW = "🆕 طلب جديد"
BTN_MY = "📋 طلباتي"
BTN_SUPPORT = "💬 دعم"
BTN_SUBMIT = "✅ تم الإرسال"
MAX_ATTACHMENTS = 5
PERIOD_LABELS = {
    "monthly": "شهري",
    "quarterly": "ربع سنوي",
    "semiannual": "نصف سنوي",
    "yearly": "سنوي",
    "one_time": "دفعة واحدة",
}
PROFILE_PROMPTS = {
    "name": "ما اسمك الكامل؟",
    "phone": "نطلب رقم الهاتف لنتواصل معك عند صدور العرض أو أي استفسار عن الطلب.\nما رقم هاتفك؟",
    "company_name": "نطلب اسم الشركة لنصدر العرض والفاتورة باسم جهتك ونحفظ الطلب في ملفك.\nما اسم الشركة؟",
}
REJECT_REASONS = {
    "rjprice": "السعر غالي",
    "rjdelay": "تأخير بالرد",
}


def reject_reason_keyboard(number: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        [
            [
                InlineKeyboardButton("السعر غالي", callback_data=f"rjprice:{number}"),
                InlineKeyboardButton("تأخير بالرد", callback_data=f"rjdelay:{number}"),
            ],
            [InlineKeyboardButton("غير ذلك", callback_data=f"rjother:{number}")],
        ]
    )
ALLOWED_ATTACHMENT_MIMES = {
    "image/jpeg",
    "image/png",
    "image/webp",
    "application/pdf",
    "audio/ogg",
    "audio/mpeg",
    "audio/mp4",
    "audio/x-m4a",
}


def api_headers() -> dict[str, str]:
    return {
        "Accept": "application/json",
        "X-Webhook-Secret": BOT_SECRET,
    }


def welcome_text(user, *, need_profile: bool) -> str:
    raw_name = (getattr(user, "first_name", None) or getattr(user, "full_name", None) or "").strip()
    name = escape(raw_name) if raw_name else "بك"
    lines = [
        f"أهلاً {name} في Home of Creativity.",
        "هذا بوت العملاء: تطلب الخدمة، تستلم عرض السعر، وتتابع حالة طلبك من هنا.",
    ]
    if need_profile:
        lines.extend(
            [
                "",
                "قبل أول طلب نحتاج رقم هاتفك واسم الشركة:",
                "• رقم الهاتف — لنتواصل معك عند صدور العرض أو أي استفسار عن الطلب.",
                "• اسم الشركة — لنصدر العرض والفاتورة باسم جهتك ونحفظ الطلب في ملفك.",
            ]
        )
    else:
        lines.append("اختر من الأزرار أدناه لطلب جديد أو متابعة طلباتك.")
    return "\n".join(lines)


def main_keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup(
        [[KeyboardButton(BTN_NEW), KeyboardButton(BTN_MY)], [KeyboardButton(BTN_SUPPORT)]],
        resize_keyboard=True,
    )


def default_sham_cash_caption(number: str) -> str:
    return (
        f"تمت الموافقة على عرض السعر للطلب #{escape(number)}.\n"
        "حوّل عبر شام كاش باستخدام الرمز، ثم أرسل إثبات التحويل كصورة أو PDF."
    )


async def send_sham_cash_qr(query, number: str, response) -> None:
    body: dict = {}
    try:
        parsed = response.json()
        if isinstance(parsed, dict):
            body = parsed
    except Exception:
        body = {}

    payload = body.get("sham_cash_qr") if isinstance(body.get("sham_cash_qr"), dict) else {}
    qr_available = bool(payload.get("qr_available"))
    caption = str(payload.get("caption") or "").strip()
    if not caption:
        caption = (
            default_sham_cash_caption(number)
            if qr_available
            else (
                f"تمت الموافقة على عرض السعر للطلب #{escape(number)}.\n"
                "سيصلك المبلغ المطلوب وخطوات التحويل من الفريق."
            )
        )
    delivered = bool(payload.get("delivered"))
    encoded = payload.get("content_base64")
    raw = None
    file_name = str(payload.get("file_name") or "sham-cash-qr.png")
    mime = str(payload.get("mime_type") or "").lower()

    if encoded and not delivered:
        try:
            raw = base64.b64decode(encoded)
        except Exception:
            raw = None

    if raw is None and qr_available and not delivered:
        try:
            async with httpx.AsyncClient(timeout=12) as client:
                image = await client.get(
                    f"{API_URL}/bot/telegram/sham-cash-qr",
                    headers=api_headers(),
                )
            if image.status_code < 400 and image.content:
                raw = image.content
                mime = (image.headers.get("content-type") or mime).split(";")[0].strip().lower()
        except Exception:
            raw = None

    if raw:
        buffer = BytesIO(raw)
        buffer.name = file_name
        await query.message.reply_photo(
            photo=buffer,
            caption=caption,
            reply_markup=main_keyboard(),
        )
        return

    if delivered and qr_available:
        caption = (
            f"تمت الموافقة على عرض السعر للطلب #{escape(number)}.\n"
            "رمز شام كاش للتحويل أعلاه. بعد التحويل أرسل إثبات الدفع كصورة أو PDF."
        )
        await query.message.reply_text(caption, reply_markup=main_keyboard())
        return

    if delivered:
        return

    await query.message.reply_text(caption, reply_markup=main_keyboard())


def body_keyboard() -> ReplyKeyboardMarkup:
    return ReplyKeyboardMarkup([[KeyboardButton(BTN_SUBMIT)]], resize_keyboard=True)


async def download_message_attachment(message) -> dict[str, str] | None:
    file_obj = None
    file_name = "attachment"
    mime_type = "application/octet-stream"

    if message.photo:
        file_obj = message.photo[-1]
        file_name = "photo.jpg"
        mime_type = "image/jpeg"
    elif message.document:
        file_obj = message.document
        mime_type = file_obj.mime_type or "application/octet-stream"
        if mime_type not in ALLOWED_ATTACHMENT_MIMES:
            return None
        file_name = file_obj.file_name or "attachment.pdf"
    elif message.voice:
        file_obj = message.voice
        file_name = "voice.ogg"
        mime_type = "audio/ogg"
    elif message.audio:
        file_obj = message.audio
        mime_type = message.audio.mime_type or "audio/mpeg"
        if mime_type not in ALLOWED_ATTACHMENT_MIMES:
            return None
        file_name = message.audio.file_name or "audio.mp3"
    else:
        return None

    telegram_file = await file_obj.get_file()
    content = await telegram_file.download_as_bytearray()
    return {
        "file_name": file_name,
        "file_base64": base64.b64encode(bytes(content)).decode("ascii"),
        "mime_type": mime_type,
    }


def attachment_status_text(count: int, has_description: bool) -> str:
    label = "مرفق" if count == 1 else "مرفقات"
    if has_description:
        return (
            f"تم استلام {count} {label}.\n"
            "أرسل المزيد أو اضغط «✅ تم الإرسال»."
        )
    return (
        f"تم استلام {count} {label}.\n"
        "اكتب الوصف الذي تريده، أو أرسل المزيد من المرفقات."
    )


async def update_attachment_status(message, context: ContextTypes.DEFAULT_TYPE) -> None:
    attachments = context.user_data.get("attachments") or []
    if not attachments:
        return

    description = (context.user_data.get("description") or "").strip()
    text = attachment_status_text(len(attachments), bool(description))
    previous_id = context.user_data.get("attachment_status_message_id")
    if previous_id:
        try:
            await context.bot.edit_message_text(
                chat_id=message.chat_id,
                message_id=previous_id,
                text=text,
                reply_markup=body_keyboard(),
            )
            return
        except Exception:
            pass

    sent = await message.reply_text(text, reply_markup=body_keyboard())
    context.user_data["attachment_status_message_id"] = sent.message_id


async def submit_new_request(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    message = update.message
    if user is None or message is None:
        return ConversationHandler.END

    if context.user_data.get("submitting"):
        return WAITING_BODY

    description = (context.user_data.get("description") or "").strip()
    attachments = context.user_data.get("attachments") or []
    if not description and not attachments:
        await message.reply_text(
            "أرسل وصفاً أو مرفقاً واحداً على الأقل قبل الإرسال.",
            reply_markup=body_keyboard(),
        )
        return WAITING_BODY

    context.user_data["submitting"] = True
    await message.reply_text("⏳ جاري معالجة طلبك الآن...", reply_markup=body_keyboard())

    payload: dict[str, object] = {
        "telegram_user_id": str(user.id),
        "title": context.user_data.get("title", "طلب جديد"),
        "description": description or "انظر المرفقات.",
    }
    if attachments:
        payload["attachments"] = attachments

    async with httpx.AsyncClient(timeout=30) as client:
        response = await client.post(
            f"{API_URL}/bot/telegram/requests",
            headers=api_headers(),
            json=payload,
        )
        if response.status_code >= 400:
            detail = response.json().get("message", response.text)
            context.user_data["submitting"] = False
            await message.reply_text(
                f"تعذر تسجيل الطلب: {escape(str(detail))}",
                reply_markup=body_keyboard(),
            )
            return WAITING_BODY
        result = response.json()["data"]

    context.user_data.pop("attachments", None)
    context.user_data.pop("description", None)
    context.user_data.pop("title", None)
    context.user_data.pop("composing_request", None)
    context.user_data.pop("submitting", None)
    context.user_data.pop("attachment_status_message_id", None)

    attachment_note = ""
    if attachments:
        attachment_note = f"\n📎 مرفقات: {len(attachments)}"

    await message.reply_text(
        f"تم تسجيل الطلب {escape(result['number'])}.{attachment_note}\nالحالة: {result['status']}",
        reply_markup=main_keyboard(),
    )
    return ConversationHandler.END


async def link_client(telegram_id: int, name: str) -> dict:
    async with httpx.AsyncClient(timeout=20) as client:
        response = await client.post(
            f"{API_URL}/bot/telegram/link",
            headers=api_headers(),
            json={
                "telegram_user_id": str(telegram_id),
                "name": name,
                "locale": "ar",
            },
        )
        response.raise_for_status()
        return response.json()


async def fetch_me(telegram_id: int) -> dict:
    async with httpx.AsyncClient(timeout=20) as client:
        response = await client.get(
            f"{API_URL}/bot/telegram/me",
            headers=api_headers(),
            params={"telegram_user_id": str(telegram_id)},
        )
        response.raise_for_status()
        return response.json().get("data") or {}


async def patch_profile(telegram_id: int, field: str, value: str) -> dict:
    async with httpx.AsyncClient(timeout=20) as client:
        response = await client.post(
            f"{API_URL}/bot/telegram/profile",
            headers=api_headers(),
            json={"telegram_user_id": str(telegram_id), field: value},
        )
        response.raise_for_status()
        return response.json().get("data") or {}


async def fetch_catalog(telegram_id: int, **params) -> dict:
    query = {"telegram_user_id": str(telegram_id)}
    query.update({key: value for key, value in params.items() if value is not None})
    async with httpx.AsyncClient(timeout=12) as client:
        response = await client.get(
            f"{API_URL}/bot/telegram/catalog",
            headers=api_headers(),
            params=query,
        )
        response.raise_for_status()
        return response.json().get("data") or {}


def period_is_subscription(period: str) -> bool:
    return period in {"monthly", "quarterly", "semiannual", "yearly"}


def catalog_footer_rows(scope: str, parent: int, has_more: bool, next_offset: int) -> list[list[InlineKeyboardButton]]:
    rows: list[list[InlineKeyboardButton]] = []
    if has_more:
        rows.append([InlineKeyboardButton("عرض المزيد", callback_data=f"more:{scope}:{parent}:{next_offset}")])
    rows.append([InlineKeyboardButton("طلب يدوي", callback_data="cman")])
    return rows


def catalog_markup(payload: dict, *, scope: str = "root", parent: int = 0) -> InlineKeyboardMarkup:
    kind = str(payload.get("kind") or "categories")
    items = payload.get("items") or []
    has_more = bool(payload.get("has_more"))
    next_offset = int(payload.get("next_offset") or 0)
    rows: list[list[InlineKeyboardButton]] = []

    if kind in {"categories", "category"}:
        for item in items:
            name = str(item.get("name") or "")[:40]
            rows.append([InlineKeyboardButton(name, callback_data=f"cat:{item['id']}")])
    elif kind in {"subcategory", "subcategories"}:
        for item in items:
            name = str(item.get("name") or "")[:40]
            rows.append([InlineKeyboardButton(name, callback_data=f"sub:{item['id']}")])
    elif kind in {"package", "packages"}:
        for item in items:
            name = str(item.get("name") or "")[:40]
            rows.append([InlineKeyboardButton(name, callback_data=f"pkg:{item['id']}")])
    elif kind == "periods":
        first = items[0] if items else {}
        package_id = first.get("id")
        for period in first.get("periods") or []:
            label = PERIOD_LABELS.get(str(period), str(period))
            rows.append([InlineKeyboardButton(label, callback_data=f"per:{package_id}:{period}")])
    else:
        for item in items:
            item_type = str(item.get("type") or "")
            name = str(item.get("name") or "")[:40]
            if item_type == "subcategory":
                rows.append([InlineKeyboardButton(name, callback_data=f"sub:{item['id']}")])
            elif item_type == "package":
                rows.append([InlineKeyboardButton(name, callback_data=f"pkg:{item['id']}")])

    rows.extend(catalog_footer_rows(scope, parent, has_more, next_offset))
    return InlineKeyboardMarkup(rows)


def next_profile_field(me: dict) -> str | None:
    missing = me.get("missing_fields") or []
    if missing:
        return str(missing[0])
    if not me.get("profile_complete"):
        if not me.get("name"):
            return "name"
        if not me.get("phone"):
            return "phone"
        if not me.get("company_name"):
            return "company_name"
    return None


async def ask_profile_field(message, context: ContextTypes.DEFAULT_TYPE, field: str) -> None:
    context.user_data["profile_field"] = field
    await message.reply_text(PROFILE_PROMPTS.get(field, "أكمل بياناتك:"), reply_markup=main_keyboard())


async def notify_api_failure(message) -> None:
    if message is None:
        return
    await message.reply_text(
        "تعذر الاتصال بالخادم حالياً. أعد المحاولة بعد ثوانٍ.",
        reply_markup=main_keyboard(),
    )


async def apply_profile_gate(update: Update, context: ContextTypes.DEFAULT_TYPE, me: dict) -> bool:
    message = update.message or (update.callback_query.message if update.callback_query else None)
    if message is None:
        return False
    field = next_profile_field(me)
    if field is None:
        context.user_data.pop("profile_field", None)
        return True
    await ask_profile_field(message, context, field)
    return False


async def ensure_profile(update: Update, context: ContextTypes.DEFAULT_TYPE) -> bool:
    user = update.effective_user
    message = update.message or (update.callback_query.message if update.callback_query else None)
    if user is None or message is None:
        return False
    try:
        me = await fetch_me(user.id)
    except (httpx.TimeoutException, httpx.HTTPStatusError, httpx.RequestError):
        await notify_api_failure(message)
        return False
    return await apply_profile_gate(update, context, me)


async def capture_profile_field(update: Update, context: ContextTypes.DEFAULT_TYPE) -> bool:
    user = update.effective_user
    if user is None or update.message is None or not update.message.text:
        return False
    field = context.user_data.get("profile_field")
    if not field:
        return False
    value = update.message.text.strip()
    if not value:
        await update.message.reply_text(PROFILE_PROMPTS.get(field, "أكمل بياناتك:"))
        return True
    try:
        me = await patch_profile(user.id, field, value)
    except httpx.HTTPStatusError:
        await notify_api_failure(update.message)
        return True
    except (httpx.TimeoutException, httpx.RequestError):
        try:
            me = await fetch_me(user.id)
        except (httpx.TimeoutException, httpx.HTTPStatusError, httpx.RequestError):
            await notify_api_failure(update.message)
            return True
    nxt = next_profile_field(me)
    if nxt:
        await ask_profile_field(update.message, context, nxt)
        return True
    context.user_data.pop("profile_field", None)
    await update.message.reply_text(
        "تم حفظ بياناتك. يمكنك الآن اختيار خدمة من القائمة.",
        reply_markup=main_keyboard(),
    )
    return True


async def send_catalog_view(message, text: str, markup=None, *, replace: bool = False) -> None:
    if replace:
        try:
            await message.edit_text(text, reply_markup=markup)
            return
        except Exception:
            pass
    kwargs = {}
    if markup is not None:
        kwargs["reply_markup"] = markup
    await message.reply_text(text, **kwargs)


async def send_catalog_result(message, text: str, *, replace: bool = False) -> None:
    if replace:
        try:
            await message.edit_text(text)
            return
        except Exception:
            pass
    await message.reply_text(text, reply_markup=main_keyboard())


async def show_catalog(
    message,
    context: ContextTypes.DEFAULT_TYPE,
    telegram_id: int,
    *,
    category_id: int | None = None,
    subcategory_id: int | None = None,
    package_id: int | None = None,
    offset: int = 0,
    parent: int = 0,
    replace: bool = False,
) -> None:
    parent = 0
    scope = "root"
    if category_id:
        parent = category_id
        scope = "cat"
    if subcategory_id:
        parent = subcategory_id
        scope = "sub"

    payload = await fetch_catalog(
        telegram_id,
        category_id=category_id,
        subcategory_id=subcategory_id,
        package_id=package_id,
        offset=offset,
    )
    kind = str(payload.get("kind") or "")
    if kind == "periods":
        first = (payload.get("items") or [{}])[0]
        periods = [str(item) for item in (first.get("periods") or [])]
        subscription = [item for item in periods if period_is_subscription(item)]
        if not subscription:
            await create_catalog_request(message, telegram_id, int(first.get("id") or 0), None, replace=replace)
            return
        await send_catalog_view(
            message,
            f"اختر مدة الاشتراك لـ {escape(str(first.get('name') or ''))}:",
            catalog_markup(payload, scope="pkg", parent=int(first.get("id") or 0)),
            replace=replace,
        )
        return

    title = "اختر الفئة:"
    if category_id:
        title = "اختر الفئة الفرعية أو الباقة:"
    if subcategory_id:
        title = "اختر الباقة:"
    await send_catalog_view(
        message,
        title,
        catalog_markup(payload, scope=scope, parent=parent),
        replace=replace,
    )


async def create_catalog_request(
    message,
    telegram_id: int,
    package_id: int,
    period: str | None,
    *,
    replace: bool = False,
) -> None:
    payload: dict[str, object] = {
        "telegram_user_id": str(telegram_id),
        "package_id": package_id,
    }
    if period and period != "one_time":
        payload["billing_period"] = period
    elif period == "one_time":
        payload["billing_period"] = "one_time"

    async with httpx.AsyncClient(timeout=30) as client:
        response = await client.post(
            f"{API_URL}/bot/telegram/catalog/requests",
            headers=api_headers(),
            json=payload,
        )
        if response.status_code >= 400:
            detail = response.json().get("message", response.text) if response.headers.get("content-type", "").startswith("application/json") else response.text
            await send_catalog_result(
                message,
                f"تعذر إنشاء الطلب: {escape(str(detail))}",
                replace=replace,
            )
            return
        result = response.json().get("data") or {}

    number = escape(str(result.get("number") or ""))
    await send_catalog_result(
        message,
        f"تم إنشاء الطلب {number} وإرسال عرض السعر.",
        replace=replace,
    )


async def start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or update.message is None:
        return
    try:
        if update.effective_chat is not None:
            await context.bot.send_chat_action(chat_id=update.effective_chat.id, action="typing")
    except Exception:
        pass
    try:
        payload = await link_client(user.id, user.full_name)
    except (httpx.TimeoutException, httpx.HTTPStatusError, httpx.RequestError):
        await notify_api_failure(update.message)
        return
    me = payload.get("data") or {}
    need_profile = next_profile_field(me) is not None
    await update.message.reply_text(
        welcome_text(user, need_profile=need_profile),
        reply_markup=main_keyboard(),
    )
    if not await apply_profile_gate(update, context, me):
        return


async def begin_manual_request(message, context: ContextTypes.DEFAULT_TYPE, *, replace: bool = False) -> int:
    context.user_data["composing_request"] = True
    context.user_data["attachments"] = []
    context.user_data["description"] = ""
    context.user_data.pop("submitting", None)
    context.user_data.pop("attachment_status_message_id", None)
    await send_catalog_view(message, "ما عنوان الطلب؟", None, replace=replace)
    return WAITING_TITLE


async def new_request(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or update.effective_user is None:
        return ConversationHandler.END
    if not await ensure_profile(update, context):
        return ConversationHandler.END
    await show_catalog(update.message, context, update.effective_user.id)
    return ConversationHandler.END


async def manual_from_catalog(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.message is None:
        return ConversationHandler.END
    await query.answer()
    if not await ensure_profile(update, context):
        return ConversationHandler.END
    return await begin_manual_request(query.message, context, replace=True)


async def catalog_action(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None or query.from_user is None or query.message is None:
        return
    await query.answer()
    if not await ensure_profile(update, context):
        return

    data = query.data
    telegram_id = query.from_user.id

    if data.startswith("cat:"):
        category_id = int(data.split(":", 1)[1])
        await show_catalog(query.message, context, telegram_id, category_id=category_id, parent=category_id, replace=True)
        return
    if data.startswith("sub:"):
        subcategory_id = int(data.split(":", 1)[1])
        await show_catalog(query.message, context, telegram_id, subcategory_id=subcategory_id, parent=subcategory_id, replace=True)
        return
    if data.startswith("pkg:"):
        package_id = int(data.split(":", 1)[1])
        await show_catalog(query.message, context, telegram_id, package_id=package_id, parent=package_id, replace=True)
        return
    if data.startswith("per:"):
        _, package_id, period = data.split(":", 2)
        await create_catalog_request(query.message, telegram_id, int(package_id), period, replace=True)
        return
    if data.startswith("more:"):
        _, scope, parent_raw, offset_raw = data.split(":", 3)
        parent = int(parent_raw)
        offset = int(offset_raw)
        kwargs: dict[str, int | None] = {"offset": offset}
        if scope == "sub":
            kwargs["subcategory_id"] = parent
        elif scope == "cat":
            kwargs["category_id"] = parent
        await show_catalog(query.message, context, telegram_id, replace=True, **kwargs)
        return


async def capture_title(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or not update.message.text:
        return WAITING_TITLE
    context.user_data["title"] = update.message.text.strip()
    context.user_data["attachments"] = []
    context.user_data["description"] = ""
    await update.message.reply_text(
        "صف المطلوب.\n"
        "يمكنك إرسال نصاً أو صوراً أو ملفات (JPG, PNG, PDF) أو رسالة صوتية.\n"
        "يمكنك البدء بالمرفقات ثم كتابة الوصف، أو العكس.\n"
        "عند الانتهاء اضغط «✅ تم الإرسال».",
        reply_markup=body_keyboard(),
    )
    return WAITING_BODY


async def capture_body(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    message = update.message
    if message is None:
        return WAITING_BODY

    context.user_data.setdefault("attachments", [])

    if message.text and message.text.strip() in {BTN_SUBMIT, "تم"}:
        return await submit_new_request(update, context)

    attachment = await download_message_attachment(message)
    if attachment is not None:
        attachments: list[dict[str, str]] = context.user_data["attachments"]
        if len(attachments) >= MAX_ATTACHMENTS:
            await message.reply_text(
                f"الحد الأقصى {MAX_ATTACHMENTS} مرفقات.",
                reply_markup=body_keyboard(),
            )
            return WAITING_BODY
        attachments.append(attachment)
        if message.caption:
            context.user_data["description"] = message.caption.strip()
        await update_attachment_status(message, context)
        return WAITING_BODY

    if message.text:
        context.user_data["description"] = message.text.strip()
        attachments = context.user_data.get("attachments") or []
        if attachments:
            await update_attachment_status(message, context)
            await message.reply_text(
                "تم حفظ الوصف. اضغط «✅ تم الإرسال» لإرسال الطلب.",
                reply_markup=body_keyboard(),
            )
        else:
            await message.reply_text(
                "تم حفظ الوصف. أرسل مرفقات (صور، ملفات، أو رسالة صوتية) إن وجدت، ثم اضغط «✅ تم الإرسال».",
                reply_markup=body_keyboard(),
            )
        return WAITING_BODY

    await message.reply_text(
        "أرسل نصاً أو صورة/ملف PDF أو رسالة صوتية.",
        reply_markup=body_keyboard(),
    )
    return WAITING_BODY


async def list_requests(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or update.message is None:
        return

    async with httpx.AsyncClient(timeout=12) as client:
        response = await client.get(
            f"{API_URL}/bot/telegram/requests",
            headers=api_headers(),
            params={"telegram_user_id": str(user.id)},
        )
        response.raise_for_status()
        items = response.json().get("data", [])

    if not items:
        await update.message.reply_text("لا توجد طلبات بعد.", reply_markup=main_keyboard())
        return

    lines = []
    renew_rows: list[list[InlineKeyboardButton]] = []
    for item in items[:10]:
        label = item.get("status_label") or item.get("execution_status_label") or item.get("status")
        package = item.get("package_name") or "طلب يدوي"
        period = PERIOD_LABELS.get(str(item.get("billing_period") or ""), item.get("billing_period") or "")
        paid = item.get("amount_paid")
        remaining = item.get("amount_remaining")
        money = ""
        if paid is not None or remaining is not None:
            money = f"\n  المدفوع: {paid or 0} USD — المتبقي: {remaining or 0} USD"
        extra = f"\n  الباقة: {package}"
        if period:
            extra += f" — {period}"
        lines.append(f"• {item['number']}: {item['title']} — {label}{extra}{money}")
        if item.get("can_edit"):
            lines.append(f"  ✏️ للتعديل: /edit {item['number']}")
        if item.get("receipt_reupload_required") or item.get("accepts_receipt"):
            lines.append("  📎 أرسل وصل الدفع كصورة أو PDF")
        if item.get("can_renew"):
            renew_rows.append(
                [
                    InlineKeyboardButton(f"تجديد {item['number']}", callback_data=f"renew:{item['number']}"),
                    InlineKeyboardButton("لن أجدد", callback_data=f"norenew:{item['number']}"),
                ]
            )

    await update.message.reply_text("\n".join(lines), reply_markup=main_keyboard())
    if renew_rows:
        await update.message.reply_text("تجديد الاشتراك:", reply_markup=InlineKeyboardMarkup(renew_rows))


async def edit_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or update.effective_user is None:
        return ConversationHandler.END
    parts = (update.message.text or "").split(maxsplit=1)
    if len(parts) < 2:
        await update.message.reply_text("استخدم: /edit REQ-2026-000001")
        return ConversationHandler.END
    context.user_data["edit_number"] = parts[1].strip()
    await update.message.reply_text("ما العنوان الجديد؟")
    return WAITING_EDIT_TITLE


async def capture_edit_title(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or not update.message.text:
        return WAITING_EDIT_TITLE
    context.user_data["edit_title"] = update.message.text.strip()
    await update.message.reply_text("ما الوصف الجديد؟")
    return WAITING_EDIT_BODY


async def capture_edit_body(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None or not update.message.text:
        return ConversationHandler.END

    number = context.user_data.get("edit_number")
    async with httpx.AsyncClient(timeout=12) as client:
        response = await client.patch(
            f"{API_URL}/bot/telegram/requests/{number}",
            headers=api_headers(),
            json={
                "telegram_user_id": str(user.id),
                "title": context.user_data.get("edit_title"),
                "description": update.message.text.strip(),
            },
        )
        if response.status_code >= 400:
            detail = response.json().get("message", response.text)
            await update.message.reply_text(f"تعذر التعديل: {escape(str(detail))}", reply_markup=main_keyboard())
            return ConversationHandler.END

    await update.message.reply_text(f"تم تحديث {escape(number)}.", reply_markup=main_keyboard())
    return ConversationHandler.END


async def support_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None:
        return ConversationHandler.END
    await update.message.reply_text("اكتب رسالة الدعم. يمكنك ذكر رقم الطلب في النص.")
    return WAITING_SUPPORT


async def capture_support(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None or not update.message.text:
        return ConversationHandler.END

    async with httpx.AsyncClient(timeout=12) as client:
        await client.post(
            f"{API_URL}/bot/telegram/support",
            headers=api_headers(),
            json={
                "telegram_user_id": str(user.id),
                "message": update.message.text.strip(),
            },
        )

    await update.message.reply_text("تم إرسال رسالة الدعم.", reply_markup=main_keyboard())
    return ConversationHandler.END


def parse_callback(data: str) -> tuple[str, str]:
    action, _, ref = data.partition(":")
    return action, ref


async def api_error_alert(query, response: httpx.Response) -> None:
    try:
        detail = response.json().get("message", response.text)
    except Exception:
        detail = response.text or f"HTTP {response.status_code}"
    await query.answer(str(detail)[:200], show_alert=True)


async def client_request_action(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None:
        return
    await query.answer()

    action, number = parse_callback(query.data)
    user = query.from_user

    async with httpx.AsyncClient(timeout=12) as client:
        if action == "reqack":
            response = await client.post(
                f"{API_URL}/bot/telegram/requests/{number}/acknowledge",
                headers=api_headers(),
                json={"telegram_user_id": str(user.id)},
            )
            if response.status_code < 400:
                await query.edit_message_reply_markup(reply_markup=None)
                await query.message.reply_text(
                    f"شكراً! تم تأكيد اهتمامك بالطلب #{escape(number)}.",
                    reply_markup=main_keyboard(),
                )
            else:
                await api_error_alert(query, response)
            return

        if action == "reqcancel":
            response = await client.post(
                f"{API_URL}/bot/telegram/requests/{number}/cancel",
                headers=api_headers(),
                json={"telegram_user_id": str(user.id)},
            )
            if response.status_code < 400:
                await query.edit_message_reply_markup(reply_markup=None)
                await query.message.reply_text(
                    f"تم إلغاء الطلب #{escape(number)}.",
                    reply_markup=main_keyboard(),
                )
            else:
                await api_error_alert(query, response)
            return

        if action == "complete":
            response = await client.post(
                f"{API_URL}/bot/telegram/requests/{number}/complete",
                headers=api_headers(),
                json={"telegram_user_id": str(user.id)},
            )
            if response.status_code < 400:
                await query.edit_message_reply_markup(reply_markup=None)
                await query.message.reply_text(
                    f"تم اعتماد تسليم الطلب #{escape(number)}. شكراً لك!",
                    reply_markup=main_keyboard(),
                )
            else:
                await api_error_alert(query, response)
            return

        if action == "revision":
            context.user_data["revision_number"] = number
            await query.edit_message_reply_markup(reply_markup=None)
            await query.message.reply_text("ما التعديل المطلوب؟")
            return

        if action == "receipt_hint":
            context.user_data["receipt_number"] = number
            await query.edit_message_reply_markup(reply_markup=None)
            await query.message.reply_text(
                f"أرسل صورة أو PDF لوصل الدفع للطلب #{escape(number)}.",
                reply_markup=main_keyboard(),
            )
            return


async def post_reject(query, number: str, reason: str) -> None:
    user = query.from_user
    async with httpx.AsyncClient(timeout=12) as client:
        response = await client.post(
            f"{API_URL}/bot/telegram/requests/{number}/reject",
            headers=api_headers(),
            json={"telegram_user_id": str(user.id), "reason": reason},
        )
        if response.status_code >= 400:
            await api_error_alert(query, response)
            return
    try:
        await query.edit_message_reply_markup(reply_markup=None)
    except Exception:
        pass
    await query.message.reply_text(
        f"تم تسجيل رفض عرض السعر للطلب #{escape(number)}.",
        reply_markup=main_keyboard(),
    )


async def quotation_action(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None:
        return
    await query.answer()

    action, number = parse_callback(query.data)
    user = query.from_user
    async with httpx.AsyncClient(timeout=12) as client:
        if action == "approve":
            response = await client.post(
                f"{API_URL}/bot/telegram/requests/{number}/approve",
                headers=api_headers(),
                json={"telegram_user_id": str(user.id)},
            )
            if response.status_code < 400:
                context.user_data["receipt_number"] = number
                await query.edit_message_reply_markup(reply_markup=None)
                await send_sham_cash_qr(query, number, response)
            else:
                await api_error_alert(query, response)
            return

        if action in REJECT_REASONS:
            await post_reject(query, number, REJECT_REASONS[action])
            return

        if action == "reject":
            try:
                await query.edit_message_text(
                    "ما سبب الرفض؟",
                    reply_markup=reject_reason_keyboard(number),
                )
            except Exception:
                try:
                    await query.edit_message_reply_markup(reply_markup=None)
                except Exception:
                    pass
                await query.message.reply_text(
                    "ما سبب الرفض؟",
                    reply_markup=reject_reason_keyboard(number),
                )
            return

        if action == "rjother":
            context.user_data["reject_number"] = number
            await query.edit_message_reply_markup(reply_markup=None)
            await query.message.reply_text("اكتب سبب رفضك:")
            return

        if action == "renew":
            response = await client.post(
                f"{API_URL}/bot/telegram/requests/{number}/renew",
                headers=api_headers(),
                json={"telegram_user_id": str(user.id)},
            )
            if response.status_code < 400:
                await query.edit_message_reply_markup(reply_markup=None)
                await query.message.reply_text(
                    f"تم بدء تجديد الاشتراك للطلب #{escape(number)}.",
                    reply_markup=main_keyboard(),
                )
            else:
                await api_error_alert(query, response)
            return

        if action == "norenew":
            response = await client.post(
                f"{API_URL}/bot/telegram/requests/{number}/decline-renewal",
                headers=api_headers(),
                json={"telegram_user_id": str(user.id)},
            )
            if response.status_code < 400:
                await query.edit_message_reply_markup(reply_markup=None)
                await query.message.reply_text(
                    f"لن يتم تجديد الاشتراك للطلب #{escape(number)}. يبقى حتى تاريخ انتهائه.",
                    reply_markup=main_keyboard(),
                )
            else:
                await api_error_alert(query, response)
            return


async def capture_revision_reason(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or update.message is None or not update.message.text:
        return

    number = context.user_data.pop("revision_number", None)
    if not number:
        return

    async with httpx.AsyncClient(timeout=12) as client:
        response = await client.post(
            f"{API_URL}/bot/telegram/requests/{number}/revision",
            headers=api_headers(),
            json={
                "telegram_user_id": str(user.id),
                "reason": update.message.text.strip(),
            },
        )
        if response.status_code >= 400:
            context.user_data["revision_number"] = number
            await update.message.reply_text("تعذر تسجيل طلب التعديل.", reply_markup=main_keyboard())
            return

    await update.message.reply_text(
        f"تم تسجيل طلب التعديل للطلب #{escape(number)}.",
        reply_markup=main_keyboard(),
    )


async def capture_reject_reason(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or update.message is None or not update.message.text:
        return

    number = context.user_data.pop("reject_number", None)
    if not number:
        return

    async with httpx.AsyncClient(timeout=12) as client:
        response = await client.post(
            f"{API_URL}/bot/telegram/requests/{number}/reject",
            headers=api_headers(),
            json={
                "telegram_user_id": str(user.id),
                "reason": update.message.text.strip(),
            },
        )
        if response.status_code >= 400:
            context.user_data["reject_number"] = number
            await update.message.reply_text("تعذر تسجيل الرفض.", reply_markup=main_keyboard())
            return

    await update.message.reply_text(
        f"تم تسجيل رفض عرض السعر للطلب #{escape(number)}.",
        reply_markup=main_keyboard(),
    )


async def pending_callback_followup(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if context.user_data.get("profile_field"):
        await capture_profile_field(update, context)
        return
    if context.user_data.get("reject_number"):
        await capture_reject_reason(update, context)
        return
    if context.user_data.get("revision_number"):
        await capture_revision_reason(update, context)


async def upload_receipt(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    message = update.message
    if user is None or message is None:
        return
    if context.user_data.get("composing_request"):
        return

    file_obj = None
    file_name = "receipt"
    mime_type = "application/octet-stream"
    if message.photo:
        file_obj = message.photo[-1]
        file_name = "receipt.jpg"
        mime_type = "image/jpeg"
    elif message.document:
        file_obj = message.document
        file_name = file_obj.file_name or "receipt.pdf"
        mime_type = file_obj.mime_type or "application/octet-stream"
    else:
        return

    telegram_file = await file_obj.get_file()
    content = await telegram_file.download_as_bytearray()
    encoded = base64.b64encode(bytes(content)).decode("ascii")

    async with httpx.AsyncClient(timeout=20) as client:
        requests_resp = await client.get(
            f"{API_URL}/bot/telegram/requests",
            headers=api_headers(),
            params={"telegram_user_id": str(user.id)},
        )
        requests_resp.raise_for_status()
        items = requests_resp.json().get("data", [])
        hinted = context.user_data.get("receipt_number")
        awaiting = None
        if hinted:
            awaiting = next((i for i in items if i.get("number") == hinted or str(i.get("number", "")).endswith(str(hinted))), None)
        if awaiting is None:
            awaiting = next((i for i in items if i.get("receipt_reupload_required")), None)
        if awaiting is None:
            awaiting = next((i for i in items if i.get("accepts_receipt") or i.get("status") == "awaiting_payment"), None)
        if not awaiting:
            return

        response = await client.post(
            f"{API_URL}/bot/telegram/requests/{awaiting['number']}/receipt",
            headers=api_headers(),
            json={
                "telegram_user_id": str(user.id),
                "file_name": file_name,
                "file_base64": encoded,
                "mime_type": mime_type,
            },
        )
        if response.status_code >= 400:
            detail = response.json().get("message", response.text)
            await message.reply_text(f"تعذر رفع الوصل: {escape(str(detail))}", reply_markup=main_keyboard())
            return

    context.user_data.pop("receipt_number", None)
    await message.reply_text(
        f"تم استلام وصل الدفع للطلب {awaiting['number']}. سيتم مراجعته من الإدارة.",
        reply_markup=main_keyboard(),
    )


async def cancel(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    context.user_data.pop("composing_request", None)
    context.user_data.pop("submitting", None)
    context.user_data.pop("attachment_status_message_id", None)
    context.user_data.pop("profile_field", None)
    if update.message:
        await update.message.reply_text("تم الإلغاء.", reply_markup=main_keyboard())
    return ConversationHandler.END


async def route_text(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or not update.message.text:
        return ConversationHandler.END
    text = update.message.text.strip()
    if text == BTN_NEW:
        return await new_request(update, context)
    if text == BTN_MY:
        await list_requests(update, context)
        return ConversationHandler.END
    if text == BTN_SUPPORT:
        return await support_start(update, context)
    return ConversationHandler.END


async def on_error(update: object, context: ContextTypes.DEFAULT_TYPE) -> None:
    message = update.effective_message if isinstance(update, Update) else None
    await notify_api_failure(message)


def main() -> None:
    token = os.environ.get("TELEGRAM_BOT_TOKEN", "")
    if not token:
        raise RuntimeError("TELEGRAM_BOT_TOKEN is missing.")

    application = (
        Application.builder()
        .token(token)
        .request(telegram_request())
        .get_updates_request(telegram_request(long_polling=True))
        .build()
    )
    application.add_handler(CommandHandler("start", start))
    application.add_handler(
        CallbackQueryHandler(
            catalog_action,
            pattern=r"^(cat|sub|pkg|per|more):",
        ),
        group=-1,
    )
    application.add_handler(
        CallbackQueryHandler(
            client_request_action,
            pattern=r"^(reqack|reqcancel|complete|revision|receipt_hint):",
        ),
        group=-1,
    )
    application.add_handler(
        CallbackQueryHandler(
            quotation_action,
            pattern=r"^(approve|reject|rjprice|rjdelay|rjother|renew|norenew):",
        ),
        group=-1,
    )
    application.add_handler(
        MessageHandler(filters.TEXT & ~filters.COMMAND, pending_callback_followup, block=False),
        group=-1,
    )
    application.add_handler(
        ConversationHandler(
            entry_points=[
                CommandHandler("new", new_request),
                MessageHandler(filters.Regex(f"^{BTN_NEW}$"), new_request),
                MessageHandler(filters.Regex(f"^{BTN_SUPPORT}$"), support_start),
                CallbackQueryHandler(manual_from_catalog, pattern=r"^cman$"),
            ],
            states={
                WAITING_TITLE: [MessageHandler(filters.TEXT & ~filters.COMMAND, capture_title)],
                WAITING_BODY: [
                    MessageHandler(filters.TEXT & ~filters.COMMAND, capture_body),
                    MessageHandler(
                        filters.PHOTO | filters.Document.ALL | filters.VOICE | filters.AUDIO,
                        capture_body,
                    ),
                ],
                WAITING_SUPPORT: [MessageHandler(filters.TEXT & ~filters.COMMAND, capture_support)],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(MessageHandler(filters.PHOTO | filters.Document.ALL, upload_receipt), group=1)
    application.add_handler(
        ConversationHandler(
            entry_points=[CommandHandler("edit", edit_start)],
            states={
                WAITING_EDIT_TITLE: [MessageHandler(filters.TEXT & ~filters.COMMAND, capture_edit_title)],
                WAITING_EDIT_BODY: [MessageHandler(filters.TEXT & ~filters.COMMAND, capture_edit_body)],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, route_text))
    application.add_error_handler(on_error)

    try:
        run_application(
            application,
            port=8445,
            url_path="client-bot",
            secret_token=BOT_SECRET,
        )
    except TimedOut as exc:
        raise SystemExit("انتهت مهلة الاتصال بـ api.telegram.org.") from exc
    except NetworkError as exc:
        raise SystemExit(f"تعذر الوصول إلى api.telegram.org ({exc}).") from exc


if __name__ == "__main__":
    main()
