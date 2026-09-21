<?php

namespace Database\Seeders;

use App\Models\Article;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ArticleSeeder extends Seeder
{
    public function run(): void
    {
        $articles = [
            [
                'slug' => 'advantages-of-social-media',
                'title_en' => 'Advantages of social media',
                'title_ar' => 'فوائد وسائل التواصل الاجتماعي',
                'excerpt_en' => 'How a consistent social presence grows brand awareness, community, and sales.',
                'excerpt_ar' => 'كيف يعزّز الحضور المنتظم على وسائل التواصل الوعي بالعلامة التجارية والمجتمع والمبيعات.',
                'body_en' => <<<'HTML'
<h2>Advantages of social media</h2>
<p>Social media is one of the most cost-effective ways to reach and grow an audience. A consistent presence keeps your brand top of mind and builds trust over time.</p>
<h3>Key benefits</h3>
<ul>
    <li><strong>Brand awareness:</strong> reach new people every day with shareable content.</li>
    <li><strong>Community:</strong> talk directly with customers and answer questions fast.</li>
    <li><strong>Traffic &amp; sales:</strong> send followers to your website, catalog, or store.</li>
    <li><strong>Insights:</strong> learn what your audience likes from real engagement data.</li>
</ul>
<p>Post regularly, reply to comments, and measure results to keep improving.</p>
HTML,
                'body_ar' => <<<'HTML'
<h2>فوائد وسائل التواصل الاجتماعي</h2>
<p>تُعدّ وسائل التواصل الاجتماعي من أكثر الطرق فاعليةً من حيث التكلفة للوصول إلى الجمهور وتنميته. فالحضور المنتظم يُبقي علامتك التجارية حاضرة في الأذهان ويبني الثقة مع الوقت.</p>
<h3>أبرز الفوائد</h3>
<ul>
    <li><strong>الوعي بالعلامة التجارية:</strong> الوصول إلى جمهور جديد يوميًا بمحتوى قابل للمشاركة.</li>
    <li><strong>المجتمع:</strong> التواصل المباشر مع العملاء والإجابة عن أسئلتهم بسرعة.</li>
    <li><strong>الزيارات والمبيعات:</strong> توجيه المتابعين إلى موقعك أو متجرك.</li>
    <li><strong>التحليلات:</strong> معرفة ما يفضّله جمهورك من بيانات التفاعل الحقيقية.</li>
</ul>
<p>انشر بانتظام، وردّ على التعليقات، وقِس النتائج لتحسين أدائك باستمرار.</p>
HTML,
                'sort_order' => 1,
                'is_published' => true,
                'published_at' => Carbon::now(),
            ],
        ];

        foreach ($articles as $article) {
            Article::query()->updateOrCreate(
                ['slug' => $article['slug']],
                $article,
            );
        }
    }
}
