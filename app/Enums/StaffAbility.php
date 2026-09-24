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
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
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
