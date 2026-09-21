<?php

namespace App\Support;

class LegalDefaults
{
    /**
     * @return list<array{slug: string, title_ar: string, title_en: string, sections: list<array<string, string>>}>
     */
    public static function pages(): array
    {
        return [
            self::privacy(),
            self::terms(),
        ];
    }

    /**
     * @return array{slug: string, title_ar: string, title_en: string, sections: list<array<string, string>>}
     */
    public static function privacy(): array
    {
        return [
            'slug' => 'privacy',
            'title_ar' => 'سياسة الخصوصية',
            'title_en' => 'Privacy Policy',
            'sections' => [
                [
                    'id' => 'about',
                    'heading_ar' => 'من نحن',
                    'heading_en' => 'Who we are',
                    'html_ar' => <<<'HTML'
<p>بيت الإبداع (Home of Creativity / HOC) وكالة هوية بصرية تعمل من دمشق. تشرح هذه السياسة كيف نجمع المعلومات الشخصية ونستخدمها ونحميها عند زيارة <a href="https://hoc.agency">hoc.agency</a> أو التواصل معنا عبر واتساب أو بوت تيليجرام أو لوحة الموظفين.</p>
<p>آخر تحديث: 21 سبتمبر 2026.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>Home of Creativity (HOC / بيت الإبداع) is a brand studio based in Damascus. This policy explains how we collect, use, and protect personal information when you visit <a href="https://hoc.agency">hoc.agency</a>, message us on WhatsApp, use our Telegram client bot, or work with our staff tools.</p>
<p>Last updated: 21 September 2026.</p>
HTML,
                ],
                [
                    'id' => 'information-you-give',
                    'heading_ar' => 'معلومات تقدّمها أنت',
                    'heading_en' => 'Information you provide',
                    'html_ar' => <<<'HTML'
<p>قد نطلب أو نستقبل:</p>
<ul>
<li>الاسم، رقم الهاتف، البريد، واسم الشركة عند طلب عرض أو تعبئة ملف العميل في تيليجرام.</li>
<li>محتوى الرسائل والملفات التي ترسلها (موجز المشروع، مراجع بصرية، وصولات الدفع).</li>
<li>تفاصيل الباقة أو الاشتراك التي تختارها من الكتالوج.</li>
</ul>
<p>إن لم تقدّم البيانات اللازمة لإنشاء الطلب أو إصدار العرض، قد لا نستطيع إكمال الخدمة.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>We may collect:</p>
<ul>
<li>Your name, phone, email, and company when you request a quote or complete a Telegram client profile.</li>
<li>Messages and files you send (briefs, visual references, payment receipts).</li>
<li>The package or subscription you choose from our catalog.</li>
</ul>
<p>If you do not provide information we need to open a request or send a quotation, we may not be able to complete the service.</p>
HTML,
                ],
                [
                    'id' => 'telegram-whatsapp',
                    'heading_ar' => 'تيليجرام وواتساب',
                    'heading_en' => 'Telegram and WhatsApp',
                    'html_ar' => <<<'HTML'
<p>طلبات العملاء تتم عبر بوت تيليجرام وليس من بوابة ويب. عندما تراسل البوت قد نحفظ معرّف تيليجرام، الاسم الظاهر، والمحادثة اللازمة لتنفيذ الطلب وإرسال الملفات. رسائل واتساب التي تبدأها أنت تُستخدم للتواصل التجاري فقط.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>Clients submit work through our Telegram bot, not a public web portal. When you message the bot we may store your Telegram user id, display name, and the conversation needed to deliver the request. WhatsApp threads you start are used only to continue that commercial conversation.</p>
HTML,
                ],
                [
                    'id' => 'social-accounts',
                    'heading_ar' => 'حسابات السوشال المرتبطة',
                    'heading_en' => 'Connected social accounts',
                    'html_ar' => <<<'HTML'
<p>الموظفون قد يربطون صفحات فيسبوك وإنستغرام وثريدز ولينكدإن لنشر المحتوى من لوحة التحكم. عند الربط قد نستقبل اسم الصفحة، المعرّف، صورة الملف، ورموز الوصول اللازمة للنشر وقراءة التفاعل. لا نبيع بيانات المنصات ولا نستخدمها خارج تقديم الخدمة.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>Staff may connect Facebook, Instagram, Threads, and LinkedIn pages so we can publish from the dashboard. Connection may include the page name, id, avatar, and access tokens needed to publish and read engagement. We do not sell platform data or use it outside providing the service.</p>
HTML,
                ],
                [
                    'id' => 'automatic-data',
                    'heading_ar' => 'بيانات تُجمع تلقائياً',
                    'heading_en' => 'Information collected automatically',
                    'html_ar' => <<<'HTML'
<p>عند زيارة الموقع قد تسجّل خوادمنا عنوان IP، نوع المتصفح، الصفحات التي تُفتح، واللغة أو المظهر المحفوظ محلياً في جهازك. نستخدم ملفات تعريف ارتباط بسيطة لتذكّر اللغة (عربي/إنجليزي) والمظهر. يمكنك حذفها من إعدادات المتصفح.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>When you visit the site our servers may log IP address, browser type, pages viewed, and the language or theme stored on your device. We use simple cookies to remember locale (Arabic/English) and color theme. You can clear them in your browser settings.</p>
HTML,
                ],
                [
                    'id' => 'how-we-use',
                    'heading_ar' => 'كيف نستخدم المعلومات',
                    'heading_en' => 'How we use information',
                    'html_ar' => <<<'HTML'
<p>نستخدم البيانات من أجل:</p>
<ul>
<li>الرد على الاستفسارات وإصدار عروض الأسعار والفواتير.</li>
<li>تنفيذ الهوية البصرية والسوشال والمطبوعات وتسليم الملفات عبر Google Drive وبوت العميل.</li>
<li>تأكيد الدفع (شام كاش أو غيره) ومتابعة حالة الطلب.</li>
<li>تحسين الموقع واللوحة، ومنع إساءة الاستخدام، والامتثال للقانون.</li>
</ul>
HTML,
                    'html_en' => <<<'HTML'
<p>We use information to:</p>
<ul>
<li>Answer inquiries and issue quotations and invoices.</li>
<li>Produce identity, social, and print work, and deliver files through Google Drive and the client bot.</li>
<li>Confirm payment (including Sham Cash) and track request status.</li>
<li>Improve the site and dashboard, prevent abuse, and comply with the law.</li>
</ul>
HTML,
                ],
                [
                    'id' => 'sharing',
                    'heading_ar' => 'كيف نشارك المعلومات',
                    'heading_en' => 'How we share information',
                    'html_ar' => <<<'HTML'
<p>لا نبيع بياناتك. قد نشارك ما يلزم فقط مع:</p>
<ul>
<li>أدوات التشغيل التي نعتمدها: Telegram، WhatsApp، Google Drive، Odoo، ClickUp، ومنصات ميتا ولينكدإن عند النشر.</li>
<li>معالجي الدفع أو التحويل عندما ترسل وصلاً.</li>
<li>الجهات الرسمية إن طُلب ذلك قانوناً.</li>
</ul>
<p>الموردون يتصرفون وفق سياساتهم الخاصة. راجع شروط تيليجرام وميتا وجوجل قبل ربط حساب.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>We do not sell your data. We share only what is needed with:</p>
<ul>
<li>Operators we rely on: Telegram, WhatsApp, Google Drive, Odoo, ClickUp, and Meta or LinkedIn when publishing.</li>
<li>Payment or transfer processors when you send a receipt.</li>
<li>Authorities when the law requires it.</li>
</ul>
<p>Those providers follow their own terms. Review Telegram, Meta, and Google policies before you connect an account.</p>
HTML,
                ],
                [
                    'id' => 'protection',
                    'heading_ar' => 'كيف نحمي المعلومات',
                    'heading_en' => 'How we protect information',
                    'html_ar' => <<<'HTML'
<p>نقيّد وصول الموظفين إلى ما يحتاجونه للعمل، ونستخدم اتصالات مشفّرة للموقع ولوحة التحكم، ولا نطلب كلمات مرور حساباتك على السوشال في الدردشة العامة. لا توجد حماية رقمية كاملة؛ استخدم قنوات رسمية فقط لإرسال الوصولات والملفات.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>Staff access is limited to what they need for the job. The site and dashboard use encrypted connections. We do not ask for your social-network passwords in public chat. No digital system is perfectly secure; send receipts and files only through the official channels we provide.</p>
HTML,
                ],
                [
                    'id' => 'rights',
                    'heading_ar' => 'حقوقك وخياراتك',
                    'heading_en' => 'Your rights and choices',
                    'html_ar' => <<<'HTML'
<p>يمكنك طلب الاطلاع على بيانات ملفك، تصحيح الاسم أو الهاتف أو الشركة، أو إيقاف رسائل التسويق. احذف بوت تيليجرام أو راسل الدعم إن أردت إغلاق المحادثة. بعض السجلات المالية والتشغيلية نحتفظ بها للمدة التي يطلبها العمل أو القانون.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>You may ask to see the data in your client file, correct name, phone, or company, or stop marketing messages. Remove the Telegram bot or message support if you want the chat closed. We keep some billing and operations records for as long as the work or the law requires.</p>
HTML,
                ],
                [
                    'id' => 'children',
                    'heading_ar' => 'خصوصية الأطفال',
                    'heading_en' => 'Children’s privacy',
                    'html_ar' => <<<'HTML'
<p>خدماتنا موجّهة لمن بلغ 18 عاماً. لا نجمع عن قصد بيانات من الأطفال. إن علمنا بملف يخص قاصراً نحذفه ونتوقف عن الخدمة.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>Our services are for people 18 and older. We do not knowingly collect data from children. If we learn a file belongs to a minor we delete it and stop the service.</p>
HTML,
                ],
                [
                    'id' => 'updates',
                    'heading_ar' => 'تحديث هذه السياسة',
                    'heading_en' => 'Updates to this policy',
                    'html_ar' => <<<'HTML'
<p>قد نعدّل هذه الصفحة من لوحة التحكم. تاريخ «آخر تحديث» يظهر في الأعلى. استمرارك في استخدام الموقع أو البوت بعد النشر يعني أنك اطّلعت على النسخة الجديدة.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>We may update this page from the staff dashboard. The “last updated” date appears above. Continuing to use the site or bot after a change means you have seen the new version.</p>
HTML,
                ],
                [
                    'id' => 'contact',
                    'heading_ar' => 'التواصل',
                    'heading_en' => 'How to contact us',
                    'html_ar' => <<<'HTML'
<p>بيت الإبداع — دمشق، الحمراء.</p>
<ul>
<li>واتساب: <a href="https://wa.me/963954187154">+963 954 187 154</a></li>
<li>تيليجرام: <a href="https://t.me/pro_design_perfect_bot">بوت العملاء</a></li>
<li>البريد: <a href="mailto:admin@hoc.agency">admin@hoc.agency</a></li>
</ul>
HTML,
                    'html_en' => <<<'HTML'
<p>Home of Creativity — Damascus, Al Hamra.</p>
<ul>
<li>WhatsApp: <a href="https://wa.me/963954187154">+963 954 187 154</a></li>
<li>Telegram: <a href="https://t.me/pro_design_perfect_bot">client bot</a></li>
<li>Email: <a href="mailto:admin@hoc.agency">admin@hoc.agency</a></li>
</ul>
HTML,
                ],
            ],
        ];
    }

    /**
     * @return array{slug: string, title_ar: string, title_en: string, sections: list<array<string, string>>}
     */
    public static function terms(): array
    {
        return [
            'slug' => 'terms',
            'title_ar' => 'شروط الاستخدام',
            'title_en' => 'Terms of Use',
            'sections' => [
                [
                    'id' => 'agreement',
                    'heading_ar' => 'الاتفاق',
                    'heading_en' => 'The agreement',
                    'html_ar' => <<<'HTML'
<p>مرحباً بك في بيت الإبداع. باستخدامك الموقع أو البوت أو أي خدمة نقدمها فإنك توافق على هذه الشروط وعلى <a href="/privacy/">سياسة الخصوصية</a>. إن لم توافق، لا تستخدم الخدمة.</p>
<p>آخر تحديث: 21 سبتمبر 2026.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>Welcome to Home of Creativity. By using the website, Telegram bot, or any service we offer, you agree to these terms and to the <a href="/privacy/">Privacy Policy</a>. If you do not agree, do not use the service.</p>
<p>Last updated: 21 September 2026.</p>
HTML,
                ],
                [
                    'id' => 'service',
                    'heading_ar' => 'الخدمة',
                    'heading_en' => 'The service',
                    'html_ar' => <<<'HTML'
<p>نقدّم تصميم هوية، محتوى سوشال، مواقع، حملات، ومواد مطبوعة. الطلبات تُفتح عبر تيليجرام أو واتساب. قد نعدّل الميزات أو نوقف جزءاً من الخدمة مع إشعار معقول عندما يكون ذلك ممكناً.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>We provide brand identity, social content, websites, campaigns, and print. Requests open through Telegram or WhatsApp. We may change features or pause part of the service, with reasonable notice when we can.</p>
HTML,
                ],
                [
                    'id' => 'eligibility',
                    'heading_ar' => 'الأهلية',
                    'heading_en' => 'Eligibility',
                    'html_ar' => <<<'HTML'
<p>يجب أن تبلغ 18 عاماً. إن قبلت الشروط باسم شركة فأنت تؤكد أنك مخوّل بإلزامها.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>You must be at least 18. If you accept these terms for a company, you confirm you have authority to bind it.</p>
HTML,
                ],
                [
                    'id' => 'accounts',
                    'heading_ar' => 'الحسابات',
                    'heading_en' => 'Accounts',
                    'html_ar' => <<<'HTML'
<p>حساب العميل يُنشأ من بوت تيليجرام بعد الاسم والهاتف والشركة. أنت مسؤول عن دقة بياناتك وعن الجهاز الذي يفتح محادثتك. أبلغنا فوراً إن فقدت الوصول إلى الرقم أو الحساب.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>A client account is created from the Telegram bot after name, phone, and company. You are responsible for accurate details and for the device that opens your chat. Tell us at once if you lose the phone number or account.</p>
HTML,
                ],
                [
                    'id' => 'payments',
                    'heading_ar' => 'الأسعار والدفع',
                    'heading_en' => 'Pricing and payment',
                    'html_ar' => <<<'HTML'
<p>الأسعار بالدولار الأمريكي كما تظهر في صفحة الباقات أو في عرض السعر. الدفع قد يكون كاملاً أو جزئياً حسب الباقة. بعد الموافقة على العرض نرسل تعليمات التحويل (مثل شام كاش). لا نبدأ التنفيذ قبل تأكيد المبلغ المستلم. الرسوم غير مستردّة بعد بدء العمل إلا إذا اتفقنا كتابةً.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>Fees are in US dollars as shown on the pricing page or in your quotation. Payment may be full or partial depending on the package. After you approve a quote we send transfer instructions (for example Sham Cash). Work starts after we confirm the amount received. Fees are not refundable once work has begun unless we agree in writing.</p>
HTML,
                ],
                [
                    'id' => 'license',
                    'heading_ar' => 'استخدام الموقع والمواد',
                    'heading_en' => 'Use of the site and materials',
                    'html_ar' => <<<'HTML'
<p>نمنحك ترخيصاً محدوداً غير حصري لزيارة الموقع واستخدام الخدمة لأغراض عملك. لا تنسخ هوية الموقع أو شفرته أو تعيد بيع أدواتنا. الأعمال المسلَّمة لك تخضع لاتفاق المشروع؛ حتى اكتمال الدفع تبقى حقوق المسودات لنا.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>We grant you a limited, non-exclusive license to visit the site and use the service for your business. Do not copy the site identity or code, or resell our tools. Deliverables follow the project agreement; until payment is complete, draft rights stay with us.</p>
HTML,
                ],
                [
                    'id' => 'feedback',
                    'heading_ar' => 'الملاحظات',
                    'heading_en' => 'Feedback',
                    'html_ar' => <<<'HTML'
<p>إن أرسلت اقتراحاً لتحسين الخدمة يجوز لنا استخدامه دون مقابل أو نسبة.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>If you send an idea to improve the service, we may use it without payment or credit.</p>
HTML,
                ],
                [
                    'id' => 'ownership',
                    'heading_ar' => 'الملكية الفكرية',
                    'heading_en' => 'Intellectual property',
                    'html_ar' => <<<'HTML'
<p>اسم بيت الإبداع، الشعار، قوالب الموقع، وأدوات اللوحة ملك لنا أو لمرخّصينا. أصولك (شعارك الحالي، صور منتجاتك) تبقى لك. تمنحنا ترخيصاً لاستخدامها لتنفيذ الطلب وعرض الأعمال في الموقع إن لم تطلب إخفاءها.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>The Home of Creativity name, mark, site templates, and dashboard tools belong to us or our licensors. Your assets (existing logo, product photos) remain yours. You grant us a license to use them to fulfill the request and to show the work on our site unless you ask us to keep it private.</p>
HTML,
                ],
                [
                    'id' => 'third-parties',
                    'heading_ar' => 'خدمات الغير',
                    'heading_en' => 'Third-party services',
                    'html_ar' => <<<'HTML'
<p>قد نربط العمل بتيليجرام وواتساب وجوجل درايف وميتا ولينكدإن وOdoo وClickUp. استخدامك لتلك المنصات يخضع لشروطها. لسنا مسؤولين عن انقطاعها أو تغيير سياساتها.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>Work may pass through Telegram, WhatsApp, Google Drive, Meta, LinkedIn, Odoo, and ClickUp. Your use of those platforms follows their terms. We are not responsible if they go down or change their policies.</p>
HTML,
                ],
                [
                    'id' => 'client-content',
                    'heading_ar' => 'محتوى العميل',
                    'heading_en' => 'Client content',
                    'html_ar' => <<<'HTML'
<p>أنت تؤكد أن الملفات والنصوص التي ترسلها لا تنتهك حقوق الغير. لا ترسل محتوى يحض على الكراهية أو العنف أو الاستغلال، ولا مواد تخص أطفالاً بشكل جنسي. يحق لنا رفض أو حذف أي مادة تخالف ذلك وإيقاف الطلب.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>You confirm that files and copy you send do not infringe others’ rights. Do not send hate, violence, exploitation, or any sexual content involving children. We may refuse or delete material that breaks this rule and stop the request.</p>
HTML,
                ],
                [
                    'id' => 'prohibited',
                    'heading_ar' => 'سلوك ممنوع',
                    'heading_en' => 'Prohibited conduct',
                    'html_ar' => <<<'HTML'
<p>لا تستخدم الخدمة لأمر غير قانوني، ولا تحاول اختراق الموقع أو البوت، ولا ترسل برمجيات خبيثة، ولا تنتحل صفة غيرك، ولا تُحمّل البنية فوق طاقتها.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>Do not use the service for anything illegal, attempt to break into the site or bot, send malware, impersonate someone else, or overload our systems.</p>
HTML,
                ],
                [
                    'id' => 'child-safety',
                    'heading_ar' => 'سلامة الأطفال',
                    'heading_en' => 'Child safety',
                    'html_ar' => <<<'HTML'
<p>نمنع أي استغلال أو محتوى جنسي يتعلق بالقاصرين. المخالفة توقف الحساب فوراً وقد تُبلَّغ الجهات المختصة. للإبلاغ راسل <a href="mailto:admin@hoc.agency">admin@hoc.agency</a>.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>We forbid any sexual exploitation or content involving minors. A breach ends the account at once and may be reported to the authorities. Report concerns to <a href="mailto:admin@hoc.agency">admin@hoc.agency</a>.</p>
HTML,
                ],
                [
                    'id' => 'termination',
                    'heading_ar' => 'الإنهاء',
                    'heading_en' => 'Termination',
                    'html_ar' => <<<'HTML'
<p>يمكنك التوقف عن الخدمة في أي وقت. قد نعلّق أو نغلق الطلب إن خالفت الشروط أو بقي مبلغ مستحق. عند الإنهاء يتوقف الترخيص باستخدام أدواتنا؛ الملفات المسلَّمة والمدفوعة تبقى وفق اتفاق المشروع.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>You may stop using the service at any time. We may pause or close a request if you break these terms or leave an unpaid balance. When the relationship ends, the license to our tools ends; paid deliverables follow the project agreement.</p>
HTML,
                ],
                [
                    'id' => 'liability',
                    'heading_ar' => 'إخلاء المسؤولية وحدود التعويض',
                    'heading_en' => 'Disclaimer and liability',
                    'html_ar' => <<<'HTML'
<p>الموقع والخدمة يُقدَّمان «كما هما». لا نضمن عملاً بلا انقطاع أو خلوّاً من الأخطاء. إلى أقصى حد يسمح به القانون، مسؤوليتنا تجاه أي مطالبة لا تتجاوز المبلغ الذي دفعته عن ذلك الطلب خلال الاثني عشر شهراً السابقة، ولا نتحمّل أضراراً غير مباشرة مثل فوات الربح.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>The site and service are provided “as is”. We do not warrant uninterrupted or error-free work. To the fullest extent the law allows, our liability for a claim is capped at what you paid for that request in the prior twelve months, and we are not liable for indirect loss such as lost profit.</p>
HTML,
                ],
                [
                    'id' => 'law',
                    'heading_ar' => 'القانون الواجب التطبيق',
                    'heading_en' => 'Governing law',
                    'html_ar' => <<<'HTML'
<p>تخضع هذه الشروط لقوانين الجمهورية العربية السورية، وتختص محاكم دمشق بأي نزاع لا يُحل ودياً.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>These terms are governed by the laws of the Syrian Arab Republic. Courts in Damascus have exclusive jurisdiction over disputes that are not resolved amicably.</p>
HTML,
                ],
                [
                    'id' => 'changes',
                    'heading_ar' => 'تعديل الشروط',
                    'heading_en' => 'Changes to these terms',
                    'html_ar' => <<<'HTML'
<p>يمكن لفريقنا تعديل النص من لوحة التحكم. التعديل الجوهري يظهر بتاريخ محدّث على هذه الصفحة. إن لم توافق على النسخة الجديدة توقّف عن استخدام الخدمة.</p>
HTML,
                    'html_en' => <<<'HTML'
<p>Staff may edit this text from the dashboard. A material change shows an updated date on this page. If you do not accept the new version, stop using the service.</p>
HTML,
                ],
                [
                    'id' => 'contact',
                    'heading_ar' => 'التواصل',
                    'heading_en' => 'Contact',
                    'html_ar' => <<<'HTML'
<p>بيت الإبداع — دمشق، الحمراء.</p>
<ul>
<li>واتساب: <a href="https://wa.me/963954187154">+963 954 187 154</a></li>
<li>تيليجرام: <a href="https://t.me/pro_design_perfect_bot">بوت العملاء</a></li>
<li>البريد: <a href="mailto:admin@hoc.agency">admin@hoc.agency</a></li>
</ul>
HTML,
                    'html_en' => <<<'HTML'
<p>Home of Creativity — Damascus, Al Hamra.</p>
<ul>
<li>WhatsApp: <a href="https://wa.me/963954187154">+963 954 187 154</a></li>
<li>Telegram: <a href="https://t.me/pro_design_perfect_bot">client bot</a></li>
<li>Email: <a href="mailto:admin@hoc.agency">admin@hoc.agency</a></li>
</ul>
HTML,
                ],
            ],
        ];
    }
}
