<?php

namespace App\Enums;

enum StaffAbility: string
{
    case OpsOverview = 'ops.overview';
    case OpsRequests = 'ops.requests';
    case OpsClients = 'ops.clients';
    case OpsReports = 'ops.reports';
    case OpsEmployees = 'ops.employees';
    case OpsPayments = 'ops.payments';
    case OpsFinance = 'ops.finance';
    case OpsVouchers = 'ops.vouchers';
    case OpsChannels = 'ops.channels';
    case OpsReportGemini = 'ops.report_gemini';
    case OpsReportTemplates = 'ops.report_templates';
    case OpsReportMedia = 'ops.report_media';
    case OpsReportCalendars = 'ops.report_calendars';
    case OpsReportCalendarMain = 'ops.report_calendar.main';
    case OpsDrive = 'ops.drive';
    case OpsPhotography = 'ops.photography';
    case OpsPhotographyAll = 'ops.photography_all';
    case OpsPhotographyCalendars = 'ops.photography_calendars';
    case OpsComplaints = 'ops.complaints';
    case SiteProjects = 'site.projects';
    case SiteCategories = 'site.categories';
    case SiteReels = 'site.reels';
    case SiteArticles = 'site.articles';
    case SitePricing = 'site.pricing';
    case SiteContact = 'site.contact';
    case SiteLegal = 'site.legal';
    case SiteProfilePdf = 'site.profile_pdf';
    case SocialContent = 'social.content';
    case SocialApprove = 'social.approve';
    case SocialEngage = 'social.engage';
    case SocialMessages = 'social.messages';
    case SocialAccounts = 'social.accounts';
    case SocialLinks = 'social.links';

    /**
     * The operations of each feature that is split. A role grants `feature.action`; a
     * role that still holds the bare feature has every operation of it. Features not
     * listed here are one switch.
     *
     * @return array<string, array<string, array{ar: string, en: string}>>
     */
    public static function actionCatalog(): array
    {
        $view = ['ar' => 'عرض', 'en' => 'View'];
        $crud = [
            'view' => $view,
            'create' => ['ar' => 'إضافة', 'en' => 'Create'],
            'update' => ['ar' => 'تعديل', 'en' => 'Edit'],
            'delete' => ['ar' => 'حذف', 'en' => 'Delete'],
        ];
        $edit = ['view' => $view, 'update' => ['ar' => 'تعديل', 'en' => 'Edit']];

        return [
            self::OpsRequests->value => [
                'view' => $view,
                'edit' => ['ar' => 'تعديل الطلب', 'en' => 'Edit request'],
                'quotation' => ['ar' => 'عرض السعر وقرار العميل', 'en' => 'Quotation and client decision'],
                'approve_quotation' => ['ar' => 'اعتماد موافقة العميل على العرض', 'en' => 'Confirm a client approval'],
                'payments' => ['ar' => 'تأكيد الدفع وطلب الإيصال', 'en' => 'Confirm payment and ask for receipt'],
                'work_plan' => ['ar' => 'خطة العمل والتمديد', 'en' => 'Work plan and extension'],
                'integrations' => ['ar' => 'ClickUp وDrive والذكاء وإعادة المحاولة', 'en' => 'ClickUp, Drive, AI and retries'],
                'renew' => ['ar' => 'التجديد', 'en' => 'Renew'],
            ],
            self::OpsClients->value => $crud + [
                'odoo' => ['ar' => 'Odoo: مزامنة واستيراد وعروض وفواتير', 'en' => 'Odoo: sync, import, quotations, invoices'],
                'odoo_note' => ['ar' => 'رسالة داخلية على عرض السعر والفاتورة', 'en' => 'Internal note on quotations and invoices'],
                'proforma' => ['ar' => 'الفاتورة الشكلية: معاينة وإرسال للعميل', 'en' => 'Pro-forma invoice: preview and send to the client'],
            ],
            self::OpsReports->value => $crud + [
                'send' => ['ar' => 'إرسال التقرير للعميل بالبريد أو واتساب', 'en' => 'Send the report by email or WhatsApp'],
            ],
            self::OpsEmployees->value => $crud + [
                'approve' => ['ar' => 'قبول ورفض الانضمام', 'en' => 'Approve and reject joins'],
                'sync' => ['ar' => 'مزامنة Odoo', 'en' => 'Odoo sync'],
            ],
            self::OpsPayments->value => [
                'view' => $view,
                'qr' => ['ar' => 'تغيير رمز QR', 'en' => 'Change QR code'],
            ],
            self::OpsFinance->value => [
                'view' => $view,
                'expense_create' => ['ar' => 'إضافة مصروف', 'en' => 'Add expense'],
                'expense_update' => ['ar' => 'تعديل مصروف', 'en' => 'Edit expense'],
                'expense_delete' => ['ar' => 'حذف مصروف', 'en' => 'Delete expense'],
                'sync' => ['ar' => 'المزامنة مع Odoo', 'en' => 'Odoo sync'],
            ],
            self::OpsVouchers->value => $crud + [
                'send' => ['ar' => 'إرسال المستند بالبريد أو واتساب', 'en' => 'Send by email or WhatsApp'],
            ],
            self::OpsChannels->value => [
                'view' => $view,
                'update' => ['ar' => 'تعديل الإعدادات والساعات', 'en' => 'Edit settings and hours'],
                'whatsapp' => ['ar' => 'ربط وفصل واتساب', 'en' => 'Link and unlink WhatsApp'],
            ],
            self::OpsComplaints->value => [
                'view' => $view,
                'reply' => ['ar' => 'الرد وفتح التذكرة', 'en' => 'Reply and open ticket'],
                'status' => ['ar' => 'تغيير الحالة', 'en' => 'Change status'],
            ],
            self::SiteProjects->value => $crud,
            self::SiteCategories->value => $crud,
            self::SiteReels->value => $crud,
            self::SiteArticles->value => $crud,
            self::SitePricing->value => $crud,
            self::SiteContact->value => $crud,
            self::SiteLegal->value => $edit,
            self::SiteProfilePdf->value => $edit,
        ];
    }

    /** @return list<string> */
    public static function actionsOf(string $feature): array
    {
        return array_keys(self::actionCatalog()[$feature] ?? []);
    }

    /** @return list<string> */
    public static function crudResources(): array
    {
        return array_keys(self::actionCatalog());
    }

    /** HTTP verbs the `crud:` middleware maps to actions. */
    public static function crudActions(): array
    {
        return ['view', 'create', 'update', 'delete'];
    }

    /**
     * Roles saved before features were split keep exactly what they could do: the old
     * view, create, update, and delete grants become the operations that used them.
     *
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function upgradeLegacy(array $abilities): array
    {
        $map = [
            'ops.requests.create' => ['ops.requests.quotation', 'ops.requests.payments', 'ops.requests.work_plan', 'ops.requests.integrations', 'ops.requests.renew'],
            'ops.requests.update' => ['ops.requests.edit', 'ops.requests.work_plan'],
            'ops.requests.delete' => [],
            'ops.clients.create' => ['ops.clients.create', 'ops.clients.odoo'],
            'ops.employees.create' => ['ops.employees.create', 'ops.employees.approve', 'ops.employees.sync'],
        ];
        $next = [];
        foreach ($abilities as $ability) {
            if (! is_string($ability)) {
                continue;
            }
            foreach ($map[$ability] ?? [$ability] as $mapped) {
                $next[] = $mapped;
            }
            if ($ability === self::OpsPhotographyAll->value) {
                $next[] = self::OpsPhotographyCalendars->value;
            }
        }

        return array_values(array_unique($next));
    }

    /** @return list<string> */
    public static function pageScoped(): array
    {
        return [
            self::SocialContent->value,
            self::SocialApprove->value,
            self::SocialEngage->value,
            self::SocialMessages->value,
        ];
    }

    /**
     * @return array{ar: string, en: string}
     */
    public function labels(): array
    {
        return match ($this) {
            self::OpsOverview => ['ar' => 'نظرة عامة', 'en' => 'Overview'],
            self::OpsRequests => ['ar' => 'الطلبات', 'en' => 'Requests'],
            self::OpsClients => ['ar' => 'العملاء', 'en' => 'Clients'],
            self::OpsReports => ['ar' => 'التقارير', 'en' => 'Reports'],
            self::OpsEmployees => ['ar' => 'الموظفون', 'en' => 'Employees'],
            self::OpsPayments => ['ar' => 'المدفوعات', 'en' => 'Payments'],
            self::OpsFinance => ['ar' => 'المالية', 'en' => 'Finance'],
            self::OpsVouchers => ['ar' => 'المسندات المالية', 'en' => 'Financial vouchers'],
            self::OpsChannels => ['ar' => 'قنوات البوت', 'en' => 'Bot channels'],
            self::OpsReportGemini => ['ar' => 'مساعد التقارير', 'en' => 'Report assistant'],
            self::OpsReportTemplates => ['ar' => 'قوالب التقارير', 'en' => 'Report templates'],
            self::OpsReportMedia => ['ar' => 'صور التقارير ومربع النص', 'en' => 'Report pictures and text box'],
            self::OpsReportCalendars => ['ar' => 'التقارير: إضافة التقاويم ومن يراها', 'en' => 'Reports: add calendars and who sees them'],
            self::OpsReportCalendarMain => ['ar' => 'التقويم الرئيسي للتقارير', 'en' => 'Main reports calendar'],
            self::OpsDrive => ['ar' => 'حساب تخزين Drive', 'en' => 'Drive storage account'],
            self::OpsPhotography => ['ar' => 'التصوير: الطابور ومواعيدي', 'en' => 'Photography: queue and my shoots'],
            self::OpsPhotographyAll => ['ar' => 'التصوير: كل المواعيد والإلغاء وضبط الجلسات', 'en' => 'Photography: all bookings, cancel, session counts'],
            self::OpsPhotographyCalendars => ['ar' => 'التصوير: إضافة التقاويم وصلاحياتها', 'en' => 'Photography: add calendars and their access'],
            self::OpsComplaints => ['ar' => 'الشكاوى', 'en' => 'Complaints'],
            self::SiteProjects => ['ar' => 'المشاريع', 'en' => 'Projects'],
            self::SiteCategories => ['ar' => 'التصنيفات', 'en' => 'Categories'],
            self::SiteReels => ['ar' => 'الريلز', 'en' => 'Reels'],
            self::SiteArticles => ['ar' => 'المقالات', 'en' => 'Articles'],
            self::SitePricing => ['ar' => 'الأسعار', 'en' => 'Pricing'],
            self::SiteContact => ['ar' => 'التواصل', 'en' => 'Contact'],
            self::SiteLegal => ['ar' => 'الخصوصية والشروط', 'en' => 'Privacy and terms'],
            self::SiteProfilePdf => ['ar' => 'الملف التعريفي', 'en' => 'Profile PDF'],
            self::SocialContent => ['ar' => 'إدارة المحتوى', 'en' => 'Content'],
            self::SocialApprove => ['ar' => 'النشر والموافقة', 'en' => 'Publish and approve'],
            self::SocialEngage => ['ar' => 'التفاعل والتعليقات', 'en' => 'Engagement'],
            self::SocialMessages => ['ar' => 'الرسائل', 'en' => 'Messages'],
            self::SocialAccounts => ['ar' => 'ربط الحسابات', 'en' => 'Accounts'],
            self::SocialLinks => ['ar' => 'الروابط والمظهر', 'en' => 'Links and design'],
        };
    }

    /**
     * Roles that already edit reports or open payments keep the features that used to share those abilities.
     *
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function inheritFeatureAbilities(array $abilities): array
    {
        $next = array_values(array_filter($abilities, is_string(...)));
        $editsReports = false;
        foreach ($next as $ability) {
            if ($ability === self::OpsReports->value || str_starts_with($ability, self::OpsReports->value.'.create') || str_starts_with($ability, self::OpsReports->value.'.update')) {
                $editsReports = true;
            }
        }

        if ($editsReports) {
            foreach ([self::OpsReportGemini, self::OpsReportTemplates, self::OpsReportMedia, self::OpsDrive] as $feature) {
                if (! in_array($feature->value, $next, true)) {
                    $next[] = $feature->value;
                }
            }
        }

        if (in_array(self::OpsPayments->value, $next, true) && ! in_array(self::OpsFinance->value, $next, true)) {
            $next[] = self::OpsFinance->value;
        }

        return array_values(array_unique($next));
    }

    /** @return list<string> */
    public static function values(): array
    {
        $values = [];
        foreach (self::cases() as $case) {
            $actions = self::actionsOf($case->value);
            if ($actions !== []) {
                foreach ($actions as $action) {
                    $values[] = $case->value.'.'.$action;
                }

                continue;
            }

            $values[] = $case->value;
        }

        return $values;
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function expand(array $abilities): array
    {
        $expanded = [];
        foreach ($abilities as $ability) {
            if (! is_string($ability) || $ability === '') {
                continue;
            }
            $actions = self::actionsOf($ability);
            if ($actions !== []) {
                foreach ($actions as $action) {
                    $expanded[] = $ability.'.'.$action;
                }

                continue;
            }
            $expanded[] = $ability;
        }

        return array_values(array_unique($expanded));
    }

    /** @return list<string> */
    public static function socialValues(): array
    {
        return array_values(array_filter(
            self::values(),
            fn (string $value): bool => str_starts_with($value, 'social.'),
        ));
    }

    /**
     * @param  list<string>|null  $legacy
     * @return list<string>
     */
    public static function fromLegacySocial(?array $legacy): array
    {
        if ($legacy === null) {
            return self::socialValues();
        }

        $map = [
            'create' => [self::SocialContent->value],
            'approve' => [self::SocialApprove->value],
            'engage' => [self::SocialEngage->value, self::SocialMessages->value],
            'accounts' => [self::SocialAccounts->value],
        ];

        $resolved = [];
        foreach ($legacy as $ability) {
            foreach ($map[$ability] ?? [] as $next) {
                $resolved[] = $next;
            }
        }

        return array_values(array_unique($resolved));
    }
};
