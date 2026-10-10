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
    case OpsDrive = 'ops.drive';
    case OpsPhotography = 'ops.photography';
    case OpsPhotographyAll = 'ops.photography_all';
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

    /** @return list<string> */
    public static function crudResources(): array
    {
        return [
            self::OpsRequests->value,
            self::OpsClients->value,
            self::OpsReports->value,
            self::OpsVouchers->value,
            self::OpsEmployees->value,
            self::SiteProjects->value,
            self::SiteCategories->value,
            self::SiteReels->value,
            self::SiteArticles->value,
            self::SitePricing->value,
            self::SiteContact->value,
        ];
    }

    /** @return list<string> */
    public static function crudActions(): array
    {
        return ['view', 'create', 'update', 'delete'];
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
            self::OpsDrive => ['ar' => 'حساب تخزين Drive', 'en' => 'Drive storage account'],
            self::OpsPhotography => ['ar' => 'التصوير: الطابور ومواعيدي', 'en' => 'Photography: queue and my shoots'],
            self::OpsPhotographyAll => ['ar' => 'التصوير: كل المواعيد والإلغاء وضبط الجلسات', 'en' => 'Photography: all bookings, cancel, session counts'],
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
            if (in_array($case->value, self::crudResources(), true)) {
                foreach (self::crudActions() as $action) {
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
            if (in_array($ability, self::crudResources(), true)) {
                foreach (self::crudActions() as $action) {
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
