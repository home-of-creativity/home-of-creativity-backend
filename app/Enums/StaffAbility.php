<?php

namespace App\Enums;

enum StaffAbility: string
{
    case OpsOverview = 'ops.overview';
    case OpsRequests = 'ops.requests';
    case OpsClients = 'ops.clients';
    case OpsEmployees = 'ops.employees';
    case OpsPayments = 'ops.payments';
    case OpsChannels = 'ops.channels';
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
