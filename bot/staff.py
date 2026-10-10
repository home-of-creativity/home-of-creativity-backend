import base64
import os
from html import escape
from io import BytesIO
from pathlib import Path
from typing import Any, Optional

import httpx
from dotenv import load_dotenv
from telegram import InlineKeyboardButton, InlineKeyboardMarkup, KeyboardButton, ReplyKeyboardMarkup, Update
from telegram.error import NetworkError, TimedOut
from telegram.ext import Application, CallbackQueryHandler, CommandHandler, ContextTypes, ConversationHandler, MessageHandler, filters

from telegram_http import run_application, start_heartbeat, telegram_request

load_dotenv(Path(__file__).resolve().parents[1] / ".env")
load_dotenv()

WAITING_DELIVER = 1
WAITING_QUOTE_PICK = 2
WAITING_QUOTE_AMOUNT = 3
WAITING_QUOTE_NOTES = 4
WAITING_DELIVER_PICK = 5
WAITING_PROGRESS_PICK = 6
WAITING_COMPLETE_PICK = 7
WAITING_QUOTE_CONFIRM = 8
WAITING_LINK = 9
BTN_TASKS = "📌 مهامي"
BTN_NEW = "🆕 طلبات جديدة"
BTN_REPLY = "💬 رد على طلب"
BTN_QUOTE = "📄 إرسال عرض سعر"
BTN_DELIVER = "📤 تسليم نتيجة"
BTN_PROGRESS = "🚧 قيد التجهيز"
BTN_COMPLETE = "✅ إكمال الطلب"
PROFESSION_AR = {
    "sales": "المبيعات",
    "design": "التصميم",
    "content": "المحتوى",
    "branding": "الهوية البصرية",
    "3d_visualization": "التصميم ثلاثي الأبعاد",
    "media": "التصوير",
    "web": "البرمجة",
    "print": "الطباعة",
    "creative_direction": "الإدارة الإبداعية",
}
STATUS_AR = {
    "in_progress": "قيد التجهيز",
    "revision_requested": "مطلوب تعديل",
    "ready_for_review": "بانتظار المراجعة",
    "payment_confirmed": "تم تأكيد الدفع",
    "submitted": "قيد المراجعة",
}
_local_api = (os.environ.get("HOC_LOCAL_API_URL") or "http://127.0.0.1:8000").rstrip("/")
_origin = (os.environ.get("HOC_API_URL") or _local_api).rstrip("/")
if "trycloudflare.com" in _origin:
    _origin = _local_api
API_URL = _origin if _origin.endswith("/api") else f"{_origin}/api"
BOT_SECRET = os.environ.get("TELEGRAM_STAFF_BOT_SECRET", "change-me-staff")
API_TIMEOUT = httpx.Timeout(30.0)

REPLY_TEMPLATES: dict[str, str] = {
    "received": "شكراً لتواصلك. تم استلام طلبك وسنعود إليك قريباً.",
    "details": "نحتاج مزيداً من التفاصيل لإعداد عرض السعر. يرجى إرسال المعلومات المطلوبة.",
    "quote_soon": "جاري إعداد عرض السعر وسنرسله لك خلال يوم عمل.",
    "followup": "شكراً لصبرك. ما زلنا نتابع طلبك وسنرسل لك التحديث قريباً.",
}


def api_headers() -> dict[str, str]:
    return {
        "Accept": "application/json",
        "X-Webhook-Secret": BOT_SECRET,
    }


def is_sales(employee: dict[str, Any]) -> bool:
    return employee.get("profession") == "sales"


def staff_keyboard(employee: Optional[dict[str, Any]] = None) -> ReplyKeyboardMarkup:
    if employee is not None and not is_sales(employee):
        return ReplyKeyboardMarkup(
            [
                [KeyboardButton(BTN_TASKS), KeyboardButton(BTN_DELIVER)],
                [KeyboardButton(BTN_PROGRESS)],
            ],
            resize_keyboard=True,
        )
    return ReplyKeyboardMarkup(
        [
            [KeyboardButton(BTN_TASKS), KeyboardButton(BTN_NEW)],
            [KeyboardButton(BTN_REPLY), KeyboardButton(BTN_QUOTE)],
            [KeyboardButton(BTN_PROGRESS), KeyboardButton(BTN_COMPLETE)],
        ],
        resize_keyboard=True,
    )


def employee_payload(body: dict[str, Any]) -> dict[str, Any]:
    data = body.get("data") or {}
    if isinstance(data, dict) and "name" not in data and isinstance(data.get("data"), dict):
        return data["data"]
    return data if isinstance(data, dict) else {}


def display_number(item: dict[str, Any]) -> str:
    return str(item.get("display_number") or item.get("number", ""))


def format_staff_card(item: dict[str, Any]) -> str:
    num = display_number(item)
    status = item.get("status_label") or STATUS_AR.get(str(item.get("status", "")), str(item.get("status", "")))
    package = item.get("package_name") or ("طلب يدوي" if item.get("is_manual") else "—")
    company = item.get("company_name") or "—"
    assignee = item.get("assignee") or "—"
    client = item.get("client_name") or "—"
    lines = [
        f"• #{num}: {item.get('title', '')}",
        f"  الزبون: {client}",
        f"  الشركة: {company}",
        f"  الباقة: {package}",
        f"  الحالة: {status}",
        f"  المسند: {assignee}",
    ]
    telegram_url = item.get("telegram_url")
    if telegram_url:
        lines.append(f"  تواصل خاص: {telegram_url}")
    return "\n".join(lines)


async def lookup_employee(telegram_id: int) -> Optional[dict[str, Any]]:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.get(
            f"{API_URL}/bot/staff/me",
            headers=api_headers(),
            params={"telegram_user_id": str(telegram_id)},
        )
        if response.status_code == 404:
            return None
        response.raise_for_status()
        return employee_payload(response.json())


async def link_employee(telegram_id: int, code: str, username: Optional[str]) -> dict[str, Any]:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/link",
            headers=api_headers(),
            json={
                "telegram_user_id": str(telegram_id),
                "code": code,
                "telegram_username": username,
            },
        )
        if response.status_code == 422:
            body = response.json()
            errors = body.get("errors") or {}
            code_errors = errors.get("code") or []
            detail = code_errors[0] if code_errors else body.get("message", "تعذر الربط.")
            raise ValueError(str(detail))
        response.raise_for_status()
        return employee_payload(response.json())


async def join_employee(telegram_id: int, name: str, username: Optional[str]) -> dict[str, Any]:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/join",
            headers=api_headers(),
            json={
                "telegram_user_id": str(telegram_id),
                "name": name,
                "telegram_username": username,
            },
        )
        response.raise_for_status()
        return employee_payload(response.json())


def is_approved(employee: dict[str, Any]) -> bool:
    return employee.get("status") == "approved" and employee.get("is_active") is True


async def ensure_approved(update: Update) -> Optional[dict[str, Any]]:
    user = update.effective_user
    if user is None:
        return None
    employee = await lookup_employee(user.id)
    if employee is None or not is_approved(employee):
        message = update.message or (update.callback_query.message if update.callback_query else None)
        if message:
            await message.reply_text("حسابك غير مفعّل.")
        return None
    return employee


async def fetch_quotable_requests(telegram_id: int) -> list[dict[str, Any]]:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.get(
            f"{API_URL}/bot/staff/quotable-requests",
            headers=api_headers(),
            params={"telegram_user_id": str(telegram_id)},
        )
        response.raise_for_status()
        return response.json().get("data", [])


async def quote_menu(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    message = update.message or (update.callback_query.message if update.callback_query else None)
    if user is None or message is None:
        return ConversationHandler.END
    employee = await ensure_approved(update)
    if employee is None:
        return ConversationHandler.END
    if not is_sales(employee):
        await message.reply_text("إرسال عرض السعر متاح للمبيعات فقط.", reply_markup=staff_keyboard(employee))
        return ConversationHandler.END

    items = await fetch_quotable_requests(user.id)
    if not items:
        await message.reply_text("لا توجد طلبات يمكن إرسال عرض سعر لها.", reply_markup=staff_keyboard(employee))
        return ConversationHandler.END

    await message.reply_text(
        "اختر الطلب لإرسال عرض السعر:",
        reply_markup=request_picker_keyboard(items, prefix="qsel"),
    )
    return WAITING_QUOTE_PICK


async def on_select_quote_request(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.data is None or query.message is None:
        return ConversationHandler.END
    await query.answer()
    if await ensure_approved(update) is None:
        return ConversationHandler.END

    request_ref = query.data.split(":", 1)[1]
    context.user_data["quote_request"] = request_ref
    try:
        await query.edit_message_text(f"الطلب #{escape(request_ref)} — ما مبلغ عرض السعر بالدولار؟")
    except Exception:
        await query.message.reply_text(f"الطلب #{escape(request_ref)} — ما مبلغ عرض السعر بالدولار؟")
    return WAITING_QUOTE_AMOUNT


async def capture_quote_amount(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or not update.message.text:
        return WAITING_QUOTE_AMOUNT
    raw = update.message.text.strip().replace(",", "")
    try:
        amount = float(raw)
        if amount <= 0:
            raise ValueError
    except ValueError:
        await update.message.reply_text("أدخل مبلغاً صحيحاً أكبر من صفر.")
        return WAITING_QUOTE_AMOUNT

    context.user_data["quote_amount"] = amount
    await update.message.reply_text(
        "إذا بدك ملاحظة اكتبها.",
        reply_markup=InlineKeyboardMarkup([[InlineKeyboardButton("تخطي", callback_data="qskip")]]),
    )
    return WAITING_QUOTE_NOTES


def quote_confirm_markup(token: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        [
            [InlineKeyboardButton("✅ أرسل للزبون", callback_data=f"qsend:{token}")],
            [InlineKeyboardButton("✏️ تعديل المبلغ", callback_data=f"qedit:{token}")],
        ]
    )


def staff_api_error(response: httpx.Response) -> str:
    try:
        payload = response.json()
    except Exception:
        return (response.text or f"HTTP {response.status_code}")[:300]
    errors = payload.get("errors")
    if isinstance(errors, dict):
        for value in errors.values():
            if isinstance(value, list) and value:
                return str(value[0])
    return str(payload.get("message") or response.text)[:300]


async def capture_quote_notes(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None or not update.message.text:
        return ConversationHandler.END

    notes = update.message.text.strip()
    if notes in {"-", "تخطي", "تخطى"}:
        notes = ""

    return await send_quote_preview(update.message, context, notes, user.id)


async def skip_quote_notes(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    user = update.effective_user
    if query is None or query.message is None or user is None:
        return ConversationHandler.END
    await query.answer()
    return await send_quote_preview(query.message, context, "", user.id)


async def send_quote_preview(message, context: ContextTypes.DEFAULT_TYPE, notes: str, user_id: int) -> int:
    payload = {
        "telegram_user_id": str(user_id),
        "request_number": context.user_data.get("quote_request"),
        "amount": context.user_data.get("quote_amount"),
        "notes": notes or None,
    }

    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/quotation/preview",
            headers=api_headers(),
            json=payload,
        )
    if response.status_code >= 400:
        await message.reply_text(
            f"تعذر تجهيز المعاينة: {escape(staff_api_error(response))}",
            reply_markup=staff_keyboard(await lookup_employee(user_id)),
        )
        return ConversationHandler.END

    data = response.json().get("data") or {}
    token = str(data.get("token") or "")
    card = str(data.get("card") or "")
    encoded = data.get("pdf_base64")
    if encoded:
        buffer = BytesIO(base64.b64decode(encoded))
        buffer.name = str(data.get("file_name") or "quotation.pdf")
        await message.reply_document(document=buffer, caption=card[:1024], reply_markup=quote_confirm_markup(token))
    else:
        await message.reply_text(card, reply_markup=quote_confirm_markup(token))
    return WAITING_QUOTE_CONFIRM


async def on_quote_confirm(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.data is None or query.from_user is None:
        return ConversationHandler.END
    token = query.data.split(":", 1)[1]
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/quotation/confirm",
            headers=api_headers(),
            json={"telegram_user_id": str(query.from_user.id), "token": token},
        )
    if response.status_code >= 400:
        await query.answer(staff_api_error(response)[:200], show_alert=True)
        return ConversationHandler.END

    await query.answer("تم الإرسال.")
    try:
        await query.edit_message_reply_markup(reply_markup=None)
    except Exception:
        pass
    data = response.json().get("data") or {}
    request_ref = str(data.get("display_number") or data.get("request_number") or "")
    context.user_data.pop("quote_request", None)
    context.user_data.pop("quote_amount", None)
    if query.message:
        await query.message.reply_text(
            f"✅ تم إرسال عرض السعر v{escape(str(data.get('quotation_version', '')))} "
            f"للطلب #{escape(request_ref)}.\n"
            f"المبلغ: {escape(str(data.get('amount', '')))} USD",
            reply_markup=staff_keyboard(await lookup_employee(query.from_user.id)),
        )
    return ConversationHandler.END


async def on_quote_edit(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.data is None or query.from_user is None:
        return ConversationHandler.END
    token = query.data.split(":", 1)[1]
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        await client.post(
            f"{API_URL}/bot/staff/quotation/discard",
            headers=api_headers(),
            json={"telegram_user_id": str(query.from_user.id), "token": token},
        )
    await query.answer()
    try:
        await query.edit_message_reply_markup(reply_markup=None)
    except Exception:
        pass
    request_ref = context.user_data.get("quote_request")
    if not request_ref:
        if query.message:
            await query.message.reply_text("أُلغيت المعاينة. اضغط «📄 إرسال عرض سعر» لإدخال مبلغ جديد.")
        return ConversationHandler.END
    if query.message:
        await query.message.reply_text(f"الطلب #{escape(str(request_ref))} — ما مبلغ عرض السعر بالدولار؟")
    return WAITING_QUOTE_AMOUNT


async def fetch_assigned_tasks(telegram_id: int) -> list[dict[str, Any]]:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.get(
            f"{API_URL}/bot/staff/tasks",
            headers=api_headers(),
            params={"telegram_user_id": str(telegram_id)},
        )
        response.raise_for_status()
        return response.json().get("data", [])


async def fetch_replyable_requests(telegram_id: int) -> list[dict[str, Any]]:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.get(
            f"{API_URL}/bot/staff/replyable-requests",
            headers=api_headers(),
            params={"telegram_user_id": str(telegram_id)},
        )
        response.raise_for_status()
        return response.json().get("data", [])


def request_picker_keyboard(items: list[dict[str, Any]], *, prefix: str = "rsel") -> InlineKeyboardMarkup:
    rows: list[list[InlineKeyboardButton]] = []
    for item in items[:12]:
        num = display_number(item)
        title = (item.get("title") or "")[:28]
        rows.append([InlineKeyboardButton(f"#{num} — {title}", callback_data=f"{prefix}:{num}")])
    return InlineKeyboardMarkup(rows)


def template_keyboard(request_ref: str) -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(
        [
            [InlineKeyboardButton("✅ تم الاستلام", callback_data=f"rtpl:{request_ref}:received")],
            [InlineKeyboardButton("❓ نحتاج تفاصيل", callback_data=f"rtpl:{request_ref}:details")],
            [InlineKeyboardButton("📄 جاري عرض السعر", callback_data=f"rtpl:{request_ref}:quote_soon")],
            [InlineKeyboardButton("⏳ متابعة الطلب", callback_data=f"rtpl:{request_ref}:followup")],
        ]
    )


async def send_staff_reply(update: Update, request_ref: str, text: str) -> bool:
    user = update.effective_user
    if user is None:
        return False

    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/reply",
            headers=api_headers(),
            json={
                "telegram_user_id": str(user.id),
                "request_number": request_ref,
                "text": text,
            },
        )
        if response.status_code >= 400:
            detail = response.json().get("message", response.text)
            target = update.callback_query.message if update.callback_query else update.message
            if target:
                await target.reply_text(f"تعذر إرسال الرسالة: {escape(str(detail))}")
            return False
    return True


async def greet_employee(update: Update, employee: dict[str, Any]) -> None:
    if update.message is None:
        return
    if is_approved(employee):
        if is_sales(employee):
            hint = "للرد: 💬 رد على طلب | لعرض السعر: 📄 إرسال عرض سعر"
        else:
            hint = "ستظهر لك مهامك فقط. خذ المهمة ثم أرسل نتيجة التنفيذ. إذا طُلب تعديل ستراها هنا وتعيد التسليم."
        profession = str(employee.get("profession") or "")
        department = PROFESSION_AR.get(profession, profession or "غير محدد")
        await update.message.reply_text(
            f"مرحباً {escape(employee['name'])}.\nقسمك: {escape(department)}\n{hint}",
            reply_markup=staff_keyboard(employee),
        )
        return
    if employee.get("status") == "rejected":
        await update.message.reply_text("طلب انضمامك مرفوض حالياً.")
        return
    await update.message.reply_text("تم الربط. انتظر موافقة الإدارة إن لم يكن الحساب مفعّلاً.")


async def start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None:
        return ConversationHandler.END
    try:
        employee = await lookup_employee(user.id)
    except httpx.TimeoutException:
        await update.message.reply_text("تعذر الاتصال بالخادم حالياً. أعد /start بعد ثوانٍ.")
        return ConversationHandler.END
    except httpx.HTTPStatusError:
        await update.message.reply_text("تعذر التحقق من الحساب حالياً. أعد /start بعد ثوانٍ.")
        return ConversationHandler.END
    if employee:
        await greet_employee(update, employee)
        return ConversationHandler.END
    await update.message.reply_text(
        "أدخل رقم الموظف الظاهر في اللوحة، مثل EMP-0001.\nيُضاف الموظف من الداشبورد أو من أودو أولاً، ثم يُرسل الرقم هنا."
    )
    return WAITING_LINK


async def capture_employee_code(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None or not update.message.text:
        return WAITING_LINK
    try:
        employee = await link_employee(user.id, update.message.text.strip(), user.username)
    except ValueError as error:
        await update.message.reply_text(str(error))
        return WAITING_LINK
    except httpx.TimeoutException:
        await update.message.reply_text("تعذر الاتصال بالخادم حالياً. أعد إرسال الرقم.")
        return WAITING_LINK
    except httpx.HTTPStatusError:
        await update.message.reply_text("تعذر الربط حالياً. أعد إرسال الرقم.")
        return WAITING_LINK
    await greet_employee(update, employee)
    return ConversationHandler.END


async def reply_menu(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    message = update.message or (update.callback_query.message if update.callback_query else None)
    if user is None or message is None:
        return
    employee = await ensure_approved(update)
    if employee is None:
        return
    if not is_sales(employee):
        await message.reply_text("الرد على الزبون متاح للمبيعات فقط.", reply_markup=staff_keyboard(employee))
        return

    items = await fetch_replyable_requests(user.id)
    if not items:
        await message.reply_text("لا توجد طلبات يمكن الرد عليها حالياً.", reply_markup=staff_keyboard(employee))
        return

    await message.reply_text(
        "اختر الطلب الذي تريد الرد عليه:",
        reply_markup=request_picker_keyboard(items),
    )


async def on_select_request(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None or query.message is None:
        return
    await query.answer()
    if await ensure_approved(update) is None:
        return

    request_ref = query.data.split(":", 1)[1]
    context.user_data["reply_request"] = request_ref
    try:
        await query.edit_message_text(
            f"الطلب #{escape(request_ref)} — اختر نص الرد:",
            reply_markup=template_keyboard(request_ref),
        )
    except Exception:
        await query.message.reply_text(
            f"الطلب #{escape(request_ref)} — اختر نص الرد:",
            reply_markup=template_keyboard(request_ref),
        )


async def on_send_template(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None or query.message is None:
        return
    await query.answer()
    if await ensure_approved(update) is None:
        return

    _, request_ref, template_key = query.data.split(":", 2)
    text = REPLY_TEMPLATES.get(template_key)
    if not text:
        await query.message.reply_text("قالب غير معروف.")
        return

    if await send_staff_reply(update, request_ref, text):
        confirmation = f"✅ تم إرسال الرد للطلب #{escape(request_ref)}.\n\n{escape(text)}"
        try:
            await query.edit_message_text(confirmation, reply_markup=None)
        except Exception:
            await query.message.reply_text(confirmation)


async def list_tasks(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or update.message is None:
        return
    employee = await ensure_approved(update)
    if employee is None:
        return

    items = await fetch_assigned_tasks(user.id)
    if not items:
        await update.message.reply_text("لا توجد مهام مسندة إليك حالياً.", reply_markup=staff_keyboard(employee))
        return

    lines = []
    for item in items:
        line = format_staff_card(item)
        if item.get("revision_comments"):
            line += f"\n  تعديل مطلوب: {item['revision_comments']}"
        if item.get("clickup_url"):
            line += f"\n  {item['clickup_url']}"
        lines.append(line)
    await update.message.reply_text("\n\n".join(lines), reply_markup=staff_keyboard(employee))


async def list_new_requests(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    user = update.effective_user
    if user is None or update.message is None:
        return
    employee = await ensure_approved(update)
    if employee is None:
        return
    if not is_sales(employee):
        await update.message.reply_text("الطلبات الجديدة تظهر للمبيعات فقط.", reply_markup=staff_keyboard(employee))
        return

    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.get(
            f"{API_URL}/bot/staff/new-requests",
            headers=api_headers(),
            params={"telegram_user_id": str(user.id)},
        )
        if response.status_code == 403:
            await update.message.reply_text("الطلبات الجديدة تظهر للمبيعات فقط.", reply_markup=staff_keyboard(employee))
            return
        response.raise_for_status()
        items = response.json().get("data", [])

    if not items:
        await update.message.reply_text("لا توجد طلبات جديدة.", reply_markup=staff_keyboard(employee))
        return

    lines = []
    for item in items:
        line = format_staff_card(item)
        desc = (item.get("description") or "")[:120]
        if desc:
            line += f"\n  {desc}"
        if item.get("sales_clickup_url"):
            line += f"\n  ClickUp: {item['sales_clickup_url']}"
        lines.append(line)
    lines.append("للرد: اضغط 💬 رد على طلب أو /reply")
    await update.message.reply_text("\n\n".join(lines), reply_markup=staff_keyboard(employee))


async def deliver_menu(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    message = update.message or (update.callback_query.message if update.callback_query else None)
    if user is None or message is None:
        return ConversationHandler.END
    employee = await ensure_approved(update)
    if employee is None:
        return ConversationHandler.END
    if is_sales(employee):
        await message.reply_text("تسليم نتيجة التنفيذ لأقسام الإنتاج فقط.", reply_markup=staff_keyboard(employee))
        return ConversationHandler.END

    items = [
        item
        for item in await fetch_assigned_tasks(user.id)
        if item.get("status") in {"in_progress", "revision_requested"}
    ]
    if not items:
        await message.reply_text("لا توجد مهام جاهزة للتسليم.", reply_markup=staff_keyboard(employee))
        return ConversationHandler.END

    await message.reply_text(
        "اختر مهمتك لإرسال نتيجة التنفيذ:",
        reply_markup=request_picker_keyboard(items, prefix="dsel"),
    )
    return WAITING_DELIVER_PICK


async def on_select_deliver_request(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.data is None or query.message is None:
        return ConversationHandler.END
    await query.answer()
    if await ensure_approved(update) is None:
        return ConversationHandler.END

    request_ref = query.data.split(":", 1)[1]
    context.user_data["deliver_number"] = request_ref
    prompt = f"الطلب #{escape(request_ref)} — اكتب نتيجة التنفيذ (أو أرفق ملفاً مع نص)."
    try:
        await query.edit_message_text(prompt)
    except Exception:
        await query.message.reply_text(prompt)
    return WAITING_DELIVER


async def deliver_start(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    if update.message is None or update.effective_user is None:
        return ConversationHandler.END
    employee = await ensure_approved(update)
    if employee is None:
        return ConversationHandler.END
    if is_sales(employee):
        await update.message.reply_text("تسليم نتيجة التنفيذ لأقسام الإنتاج فقط.", reply_markup=staff_keyboard(employee))
        return ConversationHandler.END
    parts = (update.message.text or "").split(maxsplit=1)
    if len(parts) < 2:
        return await deliver_menu(update, context)
    context.user_data["deliver_number"] = parts[1].strip()
    await update.message.reply_text("اكتب نتيجة التنفيذ (أو أرفق ملفاً مع نص).")
    return WAITING_DELIVER


async def capture_deliver(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    if user is None or update.message is None:
        return ConversationHandler.END

    payload: dict[str, Any] = {
        "telegram_user_id": str(user.id),
        "request_number": context.user_data.get("deliver_number"),
        "notes": update.message.text or update.message.caption or "",
    }

    if update.message.document:
        file_obj = await update.message.document.get_file()
        content = await file_obj.download_as_bytearray()
        import base64

        payload["file_base64"] = base64.b64encode(bytes(content)).decode("ascii")
        payload["file_name"] = update.message.document.file_name

    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/deliver",
            headers=api_headers(),
            json=payload,
        )
        if response.status_code >= 400:
            detail = response.json().get("message", response.text)
            await update.message.reply_text(
                f"تعذر التسليم: {escape(str(detail))}",
                reply_markup=staff_keyboard(await lookup_employee(user.id)),
            )
            return ConversationHandler.END

    await update.message.reply_text("تم إرسال نتيجة التنفيذ.", reply_markup=staff_keyboard(await lookup_employee(user.id)))
    return ConversationHandler.END


async def fetch_progressable(telegram_id: int) -> list[dict[str, Any]]:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.get(
            f"{API_URL}/bot/staff/progressable-requests",
            headers=api_headers(),
            params={"telegram_user_id": str(telegram_id)},
        )
        response.raise_for_status()
        return response.json().get("data", [])


async def fetch_completable(telegram_id: int) -> list[dict[str, Any]]:
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.get(
            f"{API_URL}/bot/staff/completable-requests",
            headers=api_headers(),
            params={"telegram_user_id": str(telegram_id)},
        )
        response.raise_for_status()
        return response.json().get("data", [])


async def progress_menu(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    message = update.message
    if user is None or message is None:
        return ConversationHandler.END
    employee = await ensure_approved(update)
    if employee is None:
        return ConversationHandler.END

    items = await fetch_progressable(user.id)
    if not items:
        await message.reply_text("لا توجد طلبات مدفوعة لنقلها إلى قيد التجهيز.", reply_markup=staff_keyboard(employee))
        return ConversationHandler.END

    await message.reply_text(
        "اختر الطلب لنقله إلى قيد التجهيز:\n\n" + "\n\n".join(format_staff_card(item) for item in items[:12]),
        reply_markup=request_picker_keyboard(items, prefix="psel"),
    )
    return WAITING_PROGRESS_PICK


async def on_select_progress(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.data is None or query.from_user is None:
        return ConversationHandler.END
    await query.answer()
    employee = await ensure_approved(update)
    if employee is None:
        return ConversationHandler.END

    request_ref = query.data.split(":", 1)[1]
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/in-progress",
            headers=api_headers(),
            json={"telegram_user_id": str(query.from_user.id), "request_number": request_ref},
        )
        if response.status_code >= 400:
            await api_staff_error(query, response)
            return ConversationHandler.END

    text = f"تم نقل الطلب #{escape(request_ref)} إلى قيد التجهيز."
    try:
        await query.edit_message_text(text)
    except Exception:
        if query.message:
            await query.message.reply_text(text, reply_markup=staff_keyboard(employee))
    return ConversationHandler.END


async def complete_menu(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    user = update.effective_user
    message = update.message
    if user is None or message is None:
        return ConversationHandler.END
    employee = await ensure_approved(update)
    if employee is None:
        return ConversationHandler.END
    if not is_sales(employee):
        await message.reply_text("إكمال الطلب متاح للمبيعات فقط.", reply_markup=staff_keyboard(employee))
        return ConversationHandler.END

    items = await fetch_completable(user.id)
    if not items:
        await message.reply_text("لا توجد طلبات بانتظار الإكمال.", reply_markup=staff_keyboard(employee))
        return ConversationHandler.END

    await message.reply_text(
        "اختر الطلب لإكماله:\n\n" + "\n\n".join(format_staff_card(item) for item in items[:12]),
        reply_markup=request_picker_keyboard(items, prefix="xsel"),
    )
    return WAITING_COMPLETE_PICK


async def on_select_complete(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    query = update.callback_query
    if query is None or query.data is None or query.from_user is None:
        return ConversationHandler.END
    await query.answer()
    employee = await ensure_approved(update)
    if employee is None:
        return ConversationHandler.END

    request_ref = query.data.split(":", 1)[1]
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/complete",
            headers=api_headers(),
            json={"telegram_user_id": str(query.from_user.id), "request_number": request_ref},
        )
        if response.status_code >= 400:
            await api_staff_error(query, response)
            return ConversationHandler.END

    text = f"تم إكمال الطلب #{escape(request_ref)}."
    try:
        await query.edit_message_text(text)
    except Exception:
        if query.message:
            await query.message.reply_text(text, reply_markup=staff_keyboard(employee))
    return ConversationHandler.END


async def on_confirm_plan(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None or query.from_user is None:
        return
    employee = await ensure_approved(update)
    if employee is None:
        await query.answer("حسابك غير مفعّل.", show_alert=True)
        return
    request_ref = query.data.split(":", 1)[1]
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/confirm-plan",
            headers=api_headers(),
            json={"telegram_user_id": str(query.from_user.id), "request_number": request_ref},
        )
        if response.status_code >= 400:
            await api_staff_error(query, response)
            return
    await query.answer("تم تأكيد الخطة.")
    if query.message:
        await query.message.reply_text(
            f"تم تأكيد خطة العمل للطلب #{escape(request_ref)}.",
            reply_markup=staff_keyboard(employee),
        )


async def on_confirm_payment(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None or query.from_user is None:
        return
    employee = await ensure_approved(update)
    if employee is None:
        await query.answer("حسابك غير مفعّل.", show_alert=True)
        return
    if not is_sales(employee):
        await query.answer("تأكيد الدفع متاح للمبيعات فقط.", show_alert=True)
        return

    request_ref = query.data.split(":", 1)[1]
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/confirm-payment",
            headers=api_headers(),
            json={"telegram_user_id": str(query.from_user.id), "request_number": request_ref},
        )
        if response.status_code >= 400:
            await api_staff_error(query, response)
            return

    data = response.json().get("data") or {}
    amount = data.get("amount", "")
    text = f"✅ تم تأكيد الدفع للطلب #{escape(request_ref)}"
    if amount != "":
        text += f" — {escape(str(amount))} USD"
    await query.answer("تم تأكيد الدفع.")
    try:
        await query.edit_message_reply_markup(reply_markup=None)
    except Exception:
        pass
    if query.message:
        await query.message.reply_text(text, reply_markup=staff_keyboard(employee))


async def api_staff_error(query, response: httpx.Response) -> None:
    try:
        detail = response.json().get("message", response.text)
    except Exception:
        detail = response.text or f"HTTP {response.status_code}"
    await query.answer(str(detail)[:200], show_alert=True)


async def on_photo_approve(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None or query.from_user is None:
        return
    employee = await ensure_approved(update)
    if employee is None:
        await query.answer("حسابك غير مفعّل.", show_alert=True)
        return
    booking_id = query.data.split(":", 1)[1]
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/photography-bookings/{booking_id}/approve",
            headers=api_headers(),
            json={"telegram_user_id": str(query.from_user.id)},
        )
        if response.status_code >= 400:
            await api_staff_error(query, response)
            return
        body = response.json()
    await photo_outcome(query, employee, body)


async def photo_outcome(query, employee, body: dict) -> None:
    """One reply for accept and decline. A second tap shows that someone already answered."""
    data = body.get("data", {}) or {}
    message = str(body.get("message") or "تم.")
    if data.get("result") == "already":
        await query.answer(message, show_alert=True)
        return
    await query.answer(message[:190])
    if query.message:
        await query.message.reply_text(message, reply_markup=staff_keyboard(employee))


async def on_photo_decline(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None or query.from_user is None:
        return
    employee = await ensure_approved(update)
    if employee is None:
        await query.answer("حسابك غير مفعّل.", show_alert=True)
        return
    booking_id = query.data.split(":", 1)[1]
    async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
        response = await client.post(
            f"{API_URL}/bot/staff/photography-bookings/{booking_id}/decline",
            headers=api_headers(),
            json={"telegram_user_id": str(query.from_user.id)},
        )
        if response.status_code >= 400:
            await api_staff_error(query, response)
            return
        body = response.json()
    await photo_outcome(query, employee, body)


async def on_photo_time(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    query = update.callback_query
    if query is None or query.data is None or query.from_user is None:
        return
    employee = await ensure_approved(update)
    if employee is None:
        await query.answer("حسابك غير مفعّل.", show_alert=True)
        return
    context.user_data["photo_booking_id"] = query.data.split(":", 1)[1]
    await query.answer()
    if query.message:
        await query.message.reply_text("أرسل الوقت الذي يناسبك بصيغة 2026-10-05 16:00. الحضور 3 ساعات داخل دوام الفريق، وبين موعدين 5 ساعات.")


async def route_text(update: Update, context: ContextTypes.DEFAULT_TYPE) -> None:
    if update.message is None or not update.message.text:
        return
    text = update.message.text.strip()
    booking_id = context.user_data.get("photo_booking_id")
    if booking_id and update.effective_user is not None:
        context.user_data.pop("photo_booking_id", None)
        async with httpx.AsyncClient(timeout=API_TIMEOUT) as client:
            response = await client.post(
                f"{API_URL}/bot/staff/photography-bookings/{booking_id}/propose",
                headers=api_headers(),
                json={"telegram_user_id": str(update.effective_user.id), "starts_at": text},
            )
        if response.status_code >= 400:
            try:
                detail = response.json().get("message") or response.text
            except Exception:
                detail = response.text or "تعذر حفظ الوقت."
            await update.message.reply_text(str(detail)[:300])
            return
        employee = await lookup_employee(update.effective_user.id)
        # «أُرسل للعميل» only when WhatsApp accepted the message.
        message = response.json().get("message") or "الوقت محفوظ بانتظار العميل."
        await update.message.reply_text(message, reply_markup=staff_keyboard(employee))
        return
    if text == BTN_TASKS:
        await list_tasks(update, context)
    elif text == BTN_NEW:
        await list_new_requests(update, context)
    elif text == BTN_REPLY:
        await reply_menu(update, context)


async def cancel(update: Update, context: ContextTypes.DEFAULT_TYPE) -> int:
    employee = None
    if update.effective_user:
        employee = await lookup_employee(update.effective_user.id)
    if update.message:
        await update.message.reply_text("تم الإلغاء.", reply_markup=staff_keyboard(employee))
    return ConversationHandler.END


def main() -> None:
    token = os.environ.get("TELEGRAM_STAFF_BOT_TOKEN", "")
    if not token:
        raise RuntimeError("TELEGRAM_STAFF_BOT_TOKEN is missing.")

    application = (
        Application.builder()
        .token(token)
        .request(telegram_request())
        .get_updates_request(telegram_request(long_polling=True))
        .build()
    )
    application.add_handler(
        ConversationHandler(
            entry_points=[
                CommandHandler("start", start),
                CommandHandler("join", start),
            ],
            states={
                WAITING_LINK: [MessageHandler(filters.TEXT & ~filters.COMMAND, capture_employee_code)],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(CommandHandler("reply", reply_menu))
    application.add_handler(CallbackQueryHandler(on_confirm_payment, pattern=r"^payok:"))
    application.add_handler(CallbackQueryHandler(on_confirm_plan, pattern=r"^planok:"))
    application.add_handler(CallbackQueryHandler(on_photo_approve, pattern=r"^photook:"))
    application.add_handler(CallbackQueryHandler(on_photo_time, pattern=r"^phototime:"))
    application.add_handler(CallbackQueryHandler(on_photo_decline, pattern=r"^photono:"))
    application.add_handler(CallbackQueryHandler(on_select_request, pattern=r"^rsel:"))
    application.add_handler(CallbackQueryHandler(on_send_template, pattern=r"^rtpl:"))
    application.add_handler(MessageHandler(filters.Regex(f"^{BTN_TASKS}$"), list_tasks))
    application.add_handler(MessageHandler(filters.Regex(f"^{BTN_NEW}$"), list_new_requests))
    application.add_handler(MessageHandler(filters.Regex(f"^{BTN_REPLY}$"), reply_menu))
    application.add_handler(
        ConversationHandler(
            entry_points=[
                CommandHandler("quote", quote_menu),
                MessageHandler(filters.Regex(f"^{BTN_QUOTE}$"), quote_menu),
            ],
            states={
                WAITING_QUOTE_PICK: [CallbackQueryHandler(on_select_quote_request, pattern=r"^qsel:")],
                WAITING_QUOTE_AMOUNT: [MessageHandler(filters.TEXT & ~filters.COMMAND, capture_quote_amount)],
                WAITING_QUOTE_NOTES: [
                    CallbackQueryHandler(skip_quote_notes, pattern=r"^qskip$"),
                    MessageHandler(filters.TEXT & ~filters.COMMAND, capture_quote_notes),
                ],
                WAITING_QUOTE_CONFIRM: [
                    CallbackQueryHandler(on_quote_confirm, pattern=r"^qsend:"),
                    CallbackQueryHandler(on_quote_edit, pattern=r"^qedit:"),
                ],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(CallbackQueryHandler(on_quote_confirm, pattern=r"^qsend:"))
    application.add_handler(CallbackQueryHandler(on_quote_edit, pattern=r"^qedit:"))
    application.add_handler(
        ConversationHandler(
            entry_points=[
                CommandHandler("deliver", deliver_start),
                MessageHandler(filters.Regex(f"^{BTN_DELIVER}$"), deliver_menu),
            ],
            states={
                WAITING_DELIVER_PICK: [CallbackQueryHandler(on_select_deliver_request, pattern=r"^dsel:")],
                WAITING_DELIVER: [MessageHandler(filters.TEXT | filters.Document.ALL, capture_deliver)],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(
        ConversationHandler(
            entry_points=[
                CommandHandler("progress", progress_menu),
                MessageHandler(filters.Regex(f"^{BTN_PROGRESS}$"), progress_menu),
            ],
            states={
                WAITING_PROGRESS_PICK: [CallbackQueryHandler(on_select_progress, pattern=r"^psel:")],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(
        ConversationHandler(
            entry_points=[
                CommandHandler("complete", complete_menu),
                MessageHandler(filters.Regex(f"^{BTN_COMPLETE}$"), complete_menu),
            ],
            states={
                WAITING_COMPLETE_PICK: [CallbackQueryHandler(on_select_complete, pattern=r"^xsel:")],
            },
            fallbacks=[CommandHandler("cancel", cancel)],
        )
    )
    application.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, route_text))
    start_heartbeat("staff")

    try:
        run_application(
            application,
            port=8444,
            url_path="staff-bot",
            secret_token=BOT_SECRET,
        )
    except TimedOut as exc:
        raise SystemExit("انتهت مهلة الاتصال بـ api.telegram.org.") from exc
    except NetworkError as exc:
        raise SystemExit(f"تعذر الوصول إلى api.telegram.org ({exc}).") from exc


if __name__ == "__main__":
    main()
