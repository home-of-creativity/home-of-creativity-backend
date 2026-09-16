<?php

namespace Database\Seeders;

use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Support\PricingAmounts;
use Illuminate\Database\Seeder;

class PricingSeeder extends Seeder
{
    public function run(): void
    {
        if (PricingCategory::query()->exists()) {
            return;
        }

        $sort = 0;
        foreach ($this->catalog() as $categoryData) {
            $sort++;
            $category = PricingCategory::query()->create([
                'slug' => $categoryData['id'],
                'name_en' => $categoryData['name']['en'],
                'name_ar' => $categoryData['name']['ar'],
                'lead_en' => $categoryData['lead']['en'],
                'lead_ar' => $categoryData['lead']['ar'],
                'sort_order' => $sort,
                'is_published' => true,
                'requires_full_payment' => ($categoryData['id'] ?? '') === 'reach',
                'allows_renewal' => false,
            ]);

            $subSort = 0;
            foreach ($categoryData['subcategories'] as $subData) {
                $subSort++;
                $subcategory = PricingSubcategory::query()->create([
                    'category_id' => $category->id,
                    'slug' => $subData['id'],
                    'name_en' => $subData['name']['en'],
                    'name_ar' => $subData['name']['ar'],
                    'lead_en' => $subData['lead']['en'] ?? null,
                    'lead_ar' => $subData['lead']['ar'] ?? null,
                    'one_time' => $subData['oneTime'] ?? false,
                    'lead_in_box' => $subData['leadInBox'] ?? false,
                    'lead_note_en' => $subData['leadNote']['en'] ?? null,
                    'lead_note_ar' => $subData['leadNote']['ar'] ?? null,
                    'sort_order' => $subSort,
                    'is_published' => true,
                ]);

                $planSort = 0;
                foreach ($subData['plans'] as $planData) {
                    $planSort++;
                    PricingPackage::query()->create([
                        'subcategory_id' => $subcategory->id,
                        'slug' => $planData['id'],
                        'name_en' => $planData['name']['en'],
                        'name_ar' => $planData['name']['ar'],
                        'subtitle_en' => $planData['subtitle']['en'],
                        'subtitle_ar' => $planData['subtitle']['ar'],
                        'price_usd' => $planData['priceUsd'] ?? null,
                        'prices' => isset($planData['monthly'])
                            ? PricingAmounts::fromMonthly($planData['monthly'])
                            : null,
                        'features' => $planData['features'] ?? [],
                        'reach' => $planData['reach'] ?? null,
                        'featured' => $planData['featured'] ?? false,
                        'badge_en' => $planData['badge']['en'] ?? null,
                        'badge_ar' => $planData['badge']['ar'] ?? null,
                        'sort_order' => $planSort,
                        'is_published' => true,
                        'allows_partial_payment' => isset($planData['reach']) ? false : null,
                    ]);
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        return [
            [
                'id' => 'strategic',
                'name' => ['en' => 'Strategic solutions', 'ar' => 'باقات الحلول الاستراتيجية'],
                'lead' => [
                    'en' => 'Full-service retainers for startups, growing businesses, and established brands building long-term authority.',
                    'ar' => 'اشتراكات متكاملة للمنشآت الصغيرة والمتوسطة والكبيرة التي تبني حضوراً طويل الأمد.',
                ],
                'subcategories' => [
                    [
                        'id' => 'strategic-retainers',
                        'name' => ['en' => 'Integrated strategic packages', 'ar' => 'باقات استراتيجية متكاملة'],
                        'lead' => [
                            'en' => 'Monthly, 3-month, 6-month, or yearly billing — longer commitments unlock bigger savings.',
                            'ar' => 'اشتراك شهري أو 3 شهور أو 6 شهور أو سنوي — كلما طالت المدة زاد التوفير.',
                        ],
                        'plans' => [
                            ['id' => 'startup-build', 'name' => ['en' => 'Startup Build', 'ar' => 'Startup Build'], 'subtitle' => ['en' => 'Small business package', 'ar' => 'باقة المنشآت الصغيرة'], 'monthly' => 399, 'features' => $this->startupFeatures()],
                            ['id' => 'business-growth', 'name' => ['en' => 'Business Growth', 'ar' => 'Business Growth'], 'subtitle' => ['en' => 'Mid-size business package', 'ar' => 'باقة المنشآت المتوسطة'], 'monthly' => 899, 'featured' => true, 'badge' => ['en' => 'Popular', 'ar' => 'الأكثر طلباً'], 'features' => $this->growthFeatures()],
                            ['id' => 'elite-authority', 'name' => ['en' => 'Elite Authority', 'ar' => 'Elite Authority'], 'subtitle' => ['en' => 'Enterprise package', 'ar' => 'باقة المنشآت الكبيرة'], 'monthly' => 1499, 'features' => $this->eliteFeatures()],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'production',
                'name' => ['en' => 'Flexible production', 'ar' => 'باقات الإنتاج المرن'],
                'lead' => [
                    'en' => 'Integrated content packs and reels-only plans for brands that need focused output without the full strategic layer.',
                    'ar' => 'باقات محتوى متكاملة وباقات ريلز مخصصة للعلامات التي تحتاج إنتاجاً مركزاً دون طبقة استراتيجية كاملة.',
                ],
                'subcategories' => [
                    [
                        'id' => 'integrated-packs',
                        'name' => ['en' => 'Integrated content packs', 'ar' => 'باقات محتوى متكاملة'],
                        'lead' => [
                            'en' => 'Posts, reels, and stories in one subscription — pick monthly or save with 3, 6, or 12-month plans.',
                            'ar' => 'بوستات وريلز وستوري في اشتراك واحد — شهرياً أو وفّر مع خطط 3 و6 و12 شهراً.',
                        ],
                        'plans' => [
                            ['id' => 'premium-pack', 'name' => ['en' => 'Premium Pack', 'ar' => 'Premium Pack'], 'subtitle' => ['en' => 'Premium package', 'ar' => 'باقة بريميوم'], 'monthly' => 399, 'features' => $this->premiumFeatures()],
                            ['id' => 'growth-pack', 'name' => ['en' => 'Growth Pack', 'ar' => 'Growth Pack'], 'subtitle' => ['en' => 'Growth package', 'ar' => 'باقة النمو'], 'monthly' => 599, 'featured' => true, 'badge' => ['en' => 'Best value', 'ar' => 'الأوفر'], 'features' => $this->growthPackFeatures()],
                            ['id' => 'infinity-pack', 'name' => ['en' => 'Infinity Pack', 'ar' => 'Infinity Pack'], 'subtitle' => ['en' => 'Infinity package', 'ar' => 'باقة إنفنتي'], 'monthly' => 899, 'features' => $this->infinityFeatures()],
                        ],
                    ],
                    [
                        'id' => 'reels-packs',
                        'name' => ['en' => 'Reels-focused packs', 'ar' => 'باقات مخصصة للريلز'],
                        'lead' => [
                            'en' => 'Video-first plans for brands that want reach without the full content mix.',
                            'ar' => 'باقات تركز على الفيديو للعلامات التي تريد انتشاراً دون مزيج محتوى كامل.',
                        ],
                        'plans' => [
                            ['id' => 'reels-premium', 'name' => ['en' => 'Reels Premium', 'ar' => 'Reels Premium'], 'subtitle' => ['en' => 'Reels premium', 'ar' => 'ريلز بريميوم'], 'monthly' => 399, 'features' => $this->reelsPremiumFeatures()],
                            ['id' => 'reels-pro', 'name' => ['en' => 'Reels Pro', 'ar' => 'Reels Pro'], 'subtitle' => ['en' => 'Reels pro', 'ar' => 'ريلز برو'], 'monthly' => 699, 'features' => $this->reelsProFeatures()],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'reach',
                'name' => ['en' => 'Paid ads & reach', 'ar' => 'باقات الإعلانات الممولة والانتشار'],
                'lead' => [
                    'en' => 'One-time campaigns for seasonal offers and multi-region targeting (e.g. Syria and Gulf together).',
                    'ar' => 'حملات تستخدم لمرة واحدة للعروض الموسمية واستهداف مناطق متعددة (مثل سوريا ودول الخليج معاً).',
                ],
                'subcategories' => [
                    [
                        'id' => 'reach-campaigns',
                        'name' => ['en' => 'One-time reach campaigns', 'ar' => 'حملات انتشار لمرة واحدة'],
                        'oneTime' => true,
                        'leadInBox' => true,
                        'leadNote' => ['en' => 'For clients not on a subscription plan.', 'ar' => 'مخصصة للعملاء غير المشتركين بالباقات.'],
                        'plans' => [
                            ['id' => 'reach-package', 'name' => ['en' => 'Reach Package', 'ar' => 'Reach Package'], 'subtitle' => ['en' => 'Reach package', 'ar' => 'باقة الانتشار'], 'priceUsd' => 50, 'reach' => ['adBudgetUsd' => 50, 'adCreditUsd' => 40, 'estimatedReach' => ['en' => '+1,200,000 people', 'ar' => '+1,200,000 شخص'], 'goal' => ['en' => 'Make your brand a familiar name to millions.', 'ar' => 'الوصول بعلامتك التجارية إلى اسم مألوف لدى الملايين.']], 'features' => []],
                            ['id' => 'gold-package', 'name' => ['en' => 'Gold Package', 'ar' => 'Gold Package'], 'subtitle' => ['en' => 'Gold package', 'ar' => 'الباقة الذهبية'], 'priceUsd' => 100, 'featured' => true, 'badge' => ['en' => 'Strong reach', 'ar' => 'انتشار قوي'], 'reach' => ['adBudgetUsd' => 100, 'adCreditUsd' => 80, 'estimatedReach' => ['en' => '+2,200,000 people', 'ar' => '+2,200,000 شخص'], 'goal' => ['en' => 'Clear ad dominance and million-scale visibility over competitors.', 'ar' => 'سيطرة إعلانية واضحة ووصول مليوني يضمن تفوقاً ملحوظاً على المنافسين.']], 'features' => []],
                            ['id' => 'diamond-package', 'name' => ['en' => 'Diamond Package', 'ar' => 'Diamond Package'], 'subtitle' => ['en' => 'Diamond package', 'ar' => 'الباقة الماسية'], 'priceUsd' => 200, 'reach' => ['adBudgetUsd' => 200, 'adCreditUsd' => 160, 'estimatedReach' => ['en' => '+4,200,000 people', 'ar' => '+4,200,000 شخص'], 'goal' => ['en' => 'Full dominance of the target market for an extended period.', 'ar' => 'الهيمنة الكاملة على السوق المستهدف لفترة طويلة.']], 'features' => []],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return list<array{en: string, ar: string}> */
    private function startupFeatures(): array
    {
        return [
            ['en' => '7 graphic posts', 'ar' => '7 بوست غرافيك'],
            ['en' => '2 reels (filming & editing)', 'ar' => '2 ريلز (تصوير ومونتاج)'],
            ['en' => '9 stories', 'ar' => '9 ستوري'],
            ['en' => 'Highlights', 'ar' => 'هايلايت'],
            ['en' => '2 social platforms managed', 'ar' => 'إدارة منصتين تواصل اجتماعي'],
            ['en' => 'Performance report', 'ar' => 'تقرير الأداء'],
            ['en' => '2 paid ad campaigns managed', 'ar' => 'إدارة حملتين إعلان ممولة'],
            ['en' => 'Advanced QR code for contact & services', 'ar' => 'كيو آر كود متطور لعرض معلومات التواصل والخدمات'],
        ];
    }

    /** @return list<array{en: string, ar: string}> */
    private function growthFeatures(): array
    {
        return array_merge($this->startupFeatures(), [
            ['en' => 'Google Maps business listing', 'ar' => 'إنشاء موقع على جوجل ماب'],
            ['en' => 'Periodic competitor study', 'ar' => 'دراسة دورية للمنافسين'],
            ['en' => 'Dedicated brand advisory team', 'ar' => 'فريق استشاري مخصص للعلامة'],
        ]);
    }

    /** @return list<array{en: string, ar: string}> */
    private function eliteFeatures(): array
    {
        return [
            ['en' => '22 graphic posts', 'ar' => '22 بوست غرافيك'],
            ['en' => '8 reels (filming, editing & CGI)', 'ar' => '8 ريلز (تصوير ومونتاج وCGI)'],
            ['en' => '30 stories', 'ar' => '30 ستوري'],
            ['en' => 'Highlights', 'ar' => 'هايلايت'],
            ['en' => '5 social platforms managed', 'ar' => 'إدارة 5 منصات تواصل اجتماعي'],
            ['en' => 'Unlimited paid ad campaigns', 'ar' => 'إدارة عدد مفتوح من الإعلانات الممولة'],
            ['en' => 'Periodic competitor study', 'ar' => 'دراسة دورية للمنافسين'],
            ['en' => 'Website or e-store development & management', 'ar' => 'تطوير وإدارة موقع أو متجر إلكتروني'],
            ['en' => 'Product photo sessions (limited)', 'ar' => 'جلسات تصوير للمنتجات (عدد محدود)'],
            ['en' => 'Dedicated brand advisory team', 'ar' => 'فريق استشاري مخصص للعلامة'],
            ['en' => 'Advanced analytics reports', 'ar' => 'تقارير تحليلية متقدمة'],
            ['en' => 'Advanced ROI reports', 'ar' => 'تقارير ROI متقدمة'],
            ['en' => 'Full brand strategy incl. visual identity development', 'ar' => 'استراتيجية براند كاملة تشمل تطوير الهوية البصرية'],
        ];
    }

    /** @return list<array{en: string, ar: string}> */
    private function premiumFeatures(): array
    {
        return array_slice($this->startupFeatures(), 0, 7);
    }

    /** @return list<array{en: string, ar: string}> */
    private function growthPackFeatures(): array
    {
        return [
            ['en' => '11 graphic posts', 'ar' => '11 بوست غرافيك'],
            ['en' => '4 reels (filming & editing)', 'ar' => '4 ريلز (تصوير ومونتاج)'],
            ['en' => '15 stories', 'ar' => '15 ستوري'],
            ['en' => 'Highlights', 'ar' => 'هايلايت'],
            ['en' => '2 social platforms managed', 'ar' => 'إدارة منصتين تواصل اجتماعي'],
            ['en' => 'Advanced reports', 'ar' => 'تقارير متقدمة'],
            ['en' => '5 paid ad campaigns managed', 'ar' => 'إدارة 5 حملات إعلان ممولة'],
        ];
    }

    /** @return list<array{en: string, ar: string}> */
    private function infinityFeatures(): array
    {
        return [
            ['en' => '22 graphic posts', 'ar' => '22 بوست غرافيك'],
            ['en' => '8 reels (filming, editing & CGI)', 'ar' => '8 ريلز (تصوير ومونتاج وCGI)'],
            ['en' => '30 stories', 'ar' => '30 ستوري'],
            ['en' => 'Highlights', 'ar' => 'هايلايت'],
            ['en' => '3 social platforms managed', 'ar' => 'إدارة 3 منصات تواصل اجتماعي'],
            ['en' => 'Advanced analytics reports', 'ar' => 'تقارير تحليلية متقدمة'],
            ['en' => 'Unlimited paid ad campaigns', 'ar' => 'إدارة عدد مفتوح من الإعلانات الممولة'],
        ];
    }

    /** @return list<array{en: string, ar: string}> */
    private function reelsPremiumFeatures(): array
    {
        return [
            ['en' => '4 reels videos (filming & editing only, no graphic posts)', 'ar' => '4 فيديوهات ريلز (تصوير ومونتاج فقط) دون بوستات غرافيك'],
            ['en' => '4 stories', 'ar' => '4 ستوري'],
            ['en' => 'Highlights', 'ar' => 'هايلايت'],
            ['en' => '2 social platforms managed', 'ar' => 'إدارة منصتين تواصل اجتماعي'],
            ['en' => 'Performance report', 'ar' => 'تقرير الأداء'],
            ['en' => '2 paid ad campaigns managed', 'ar' => 'إدارة حملتين إعلان ممولة'],
        ];
    }

    /** @return list<array{en: string, ar: string}> */
    private function reelsProFeatures(): array
    {
        return [
            ['en' => '8 reels videos (filming & editing only, no graphic posts)', 'ar' => '8 فيديوهات ريلز (تصوير ومونتاج فقط) دون بوستات غرافيك'],
            ['en' => '8 stories', 'ar' => '8 ستوري'],
            ['en' => 'Highlights', 'ar' => 'هايلايت'],
            ['en' => 'Social platform management', 'ar' => 'إدارة منصات تواصل اجتماعي'],
            ['en' => 'Advanced analytics reports', 'ar' => 'تقارير تحليلية متقدمة'],
            ['en' => 'Unlimited paid ad campaigns', 'ar' => 'إدارة عدد مفتوح من الحملات الممولة'],
        ];
    }
}
