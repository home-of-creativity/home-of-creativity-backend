<?php

namespace Tests\Feature;

use App\Models\PortfolioCategory;
use App\Models\PortfolioProject;
use App\Models\PortfolioProjectImage;
use App\Models\ShowcaseClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_portfolio_clients_are_readable(): void
    {
        ShowcaseClient::query()->create([
            'name' => 'IZORA',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $this->getJson('/api/portfolio/clients')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'IZORA');
    }

    public function test_admin_can_create_showcase_client_with_logo(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->post('/api/admin/portfolio/clients', [
            'name' => 'Riva Boutique',
            'website_url' => 'https://example.com',
            'sort_order' => 2,
            'is_published' => true,
            'logo' => UploadedFile::fake()->image('riva.png'),
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Riva Boutique');

        $client = ShowcaseClient::query()->first();
        $this->assertNotNull($client?->logo_path);
        Storage::disk('public')->assertExists($client->logo_path);
    }

    public function test_admin_can_create_showcase_client_with_svg_logo(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $svg = UploadedFile::fake()->createWithContent(
            'mark.svg',
            '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>',
        );

        $this->post('/api/admin/portfolio/clients', [
            'name' => 'SVG Mark',
            'is_published' => true,
            'logo' => $svg,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'SVG Mark');

        $client = ShowcaseClient::query()->where('name', 'SVG Mark')->first();
        $this->assertNotNull($client?->logo_path);
        $this->assertStringEndsWith('.svg', $client->logo_path);
        Storage::disk('public')->assertExists($client->logo_path);
    }

    public function test_admin_can_create_portfolio_project(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $category = PortfolioCategory::query()->create([
            'slug' => 'identity',
            'name_en' => 'Identity',
            'name_ar' => 'الهوية',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $this->post('/api/admin/portfolio/projects', [
            'category_id' => $category->id,
            'title_en' => 'Brand system',
            'title_ar' => 'نظام الهوية',
            'summary_en' => 'Logo and print',
            'summary_ar' => 'شعار ومطبوعات',
            'is_published' => true,
            'featured' => true,
            'image' => UploadedFile::fake()->image('project.jpg'),
        ])->assertCreated()
            ->assertJsonPath('data.title_en', 'Brand system');

        $this->assertSame(1, PortfolioProject::query()->count());
    }

    public function test_admin_can_manage_portfolio_categories(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/portfolio/categories', [
            'slug' => 'web',
            'name_en' => 'Web',
            'name_ar' => 'المواقع',
            'sort_order' => 1,
            'is_published' => true,
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'web');

        $category = PortfolioCategory::query()->first();
        $this->assertNotNull($category);

        $this->putJson("/api/admin/portfolio/categories/{$category->id}", [
            'name_en' => 'Digital',
        ])->assertOk()
            ->assertJsonPath('data.name_en', 'Digital');

        $this->deleteJson("/api/admin/portfolio/categories/{$category->id}")
            ->assertOk();

        $this->assertSame(0, PortfolioCategory::query()->count());
    }

    public function test_admin_can_move_portfolio_category_order(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $events = PortfolioCategory::query()->create([
            'slug' => 'events',
            'name_en' => 'Events',
            'name_ar' => 'الفعاليات',
            'sort_order' => 9,
            'is_published' => true,
        ]);
        $identity = PortfolioCategory::query()->create([
            'slug' => 'identity',
            'name_en' => 'Identity',
            'name_ar' => 'الهوية',
            'sort_order' => 2,
            'is_published' => true,
        ]);

        $this->postJson("/api/admin/portfolio/categories/{$identity->id}/move", ['direction' => 'down'])
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'events')
            ->assertJsonPath('data.1.slug', 'identity');

        $this->postJson("/api/admin/portfolio/categories/{$identity->id}/move", ['direction' => 'up'])
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'identity')
            ->assertJsonPath('data.1.slug', 'events');
    }

    public function test_admin_can_delete_all_showcase_clients(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        ShowcaseClient::query()->create(['name' => 'A', 'sort_order' => 1, 'is_published' => true]);
        ShowcaseClient::query()->create(['name' => 'B', 'sort_order' => 2, 'is_published' => true]);

        $this->deleteJson('/api/admin/portfolio/clients/bulk')
            ->assertOk()
            ->assertJsonPath('data.deleted', 2);

        $this->assertSame(0, ShowcaseClient::query()->count());
    }

    public function test_admin_can_update_showcase_client_with_logo_via_form_post(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $client = ShowcaseClient::query()->create([
            'name' => 'IZORA',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $this->post('/api/admin/portfolio/clients/'.$client->id, [
            '_method' => 'PUT',
            'name' => 'IZORA Updated',
            'website_url' => '',
            'sort_order' => 2,
            'is_published' => '0',
            'logo' => UploadedFile::fake()->image('updated.png'),
        ])->assertOk()
            ->assertJsonPath('data.name', 'IZORA Updated')
            ->assertJsonPath('data.is_published', false);

        $client->refresh();
        $this->assertSame('IZORA Updated', $client->name);
        $this->assertNotNull($client->logo_path);
        Storage::disk('public')->assertExists($client->logo_path);
    }

    public function test_public_can_view_published_portfolio_project_details(): void
    {
        Storage::fake('public');

        $category = PortfolioCategory::query()->create([
            'slug' => 'events',
            'name_en' => 'Events',
            'name_ar' => 'الفعاليات',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $project = PortfolioProject::query()->create([
            'category_id' => $category->id,
            'title_en' => 'Stage design',
            'title_ar' => 'تصميم المسرح',
            'summary_en' => 'Full event identity',
            'summary_ar' => 'هوية فعالية كاملة',
            'website_url' => 'https://example.com',
            'social_links' => ['instagram' => 'https://instagram.com/example'],
            'image_path' => 'portfolio/projects/cover.jpg',
            'sort_order' => 1,
            'is_published' => true,
            'featured' => true,
        ]);

        PortfolioProjectImage::query()->create([
            'portfolio_project_id' => $project->id,
            'image_path' => 'portfolio/projects/gallery/one.jpg',
            'sort_order' => 1,
        ]);

        $this->getJson("/api/portfolio/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.title_en', 'Stage design')
            ->assertJsonPath('data.website_url', 'https://example.com')
            ->assertJsonPath('data.social_links.instagram', 'https://instagram.com/example')
            ->assertJsonCount(1, 'data.images');
    }

    public function test_admin_can_create_portfolio_project_with_gallery_and_links(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $category = PortfolioCategory::query()->create([
            'slug' => 'digital',
            'name_en' => 'Web',
            'name_ar' => 'المواقع',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $this->post('/api/admin/portfolio/projects', [
            'category_id' => $category->id,
            'title_en' => 'Brand site',
            'title_ar' => 'موقع العلامة',
            'summary_en' => 'Website launch',
            'summary_ar' => 'إطلاق موقع',
            'website_url' => 'https://brand.test',
            'social_links' => [
                'instagram' => 'https://instagram.com/brand',
                'linkedin' => 'https://linkedin.com/company/brand',
            ],
            'is_published' => true,
            'featured' => false,
            'image' => UploadedFile::fake()->image('cover.jpg'),
            'gallery' => [
                UploadedFile::fake()->image('gallery-1.jpg'),
                UploadedFile::fake()->image('gallery-2.jpg'),
            ],
        ])->assertCreated()
            ->assertJsonPath('data.website_url', 'https://brand.test')
            ->assertJsonPath('data.social_links.instagram', 'https://instagram.com/brand')
            ->assertJsonCount(2, 'data.images');

        $project = PortfolioProject::query()->first();
        $this->assertNotNull($project?->image_path);
        $this->assertSame(2, PortfolioProjectImage::query()->count());
    }

    public function test_admin_can_update_portfolio_project_with_image_via_form_post(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $category = PortfolioCategory::query()->create([
            'slug' => 'media',
            'name_en' => 'Media',
            'name_ar' => 'المحتوى',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $project = PortfolioProject::query()->create([
            'category_id' => $category->id,
            'title_en' => 'Launch film',
            'title_ar' => 'فيلم إطلاق',
            'sort_order' => 1,
            'is_published' => true,
            'featured' => false,
        ]);

        $this->post('/api/admin/portfolio/projects/'.$project->id, [
            '_method' => 'PUT',
            'category_id' => (string) $category->id,
            'title_en' => 'Launch film updated',
            'title_ar' => 'فيلم محدّث',
            'summary_en' => '',
            'summary_ar' => '',
            'sort_order' => '3',
            'is_published' => '1',
            'featured' => '1',
            'image' => UploadedFile::fake()->image('updated.jpg'),
        ])->assertOk()
            ->assertJsonPath('data.title_en', 'Launch film updated')
            ->assertJsonPath('data.featured', true);

        $project->refresh();
        $this->assertNotNull($project->image_path);
        Storage::disk('public')->assertExists($project->image_path);
    }

    private function project(PortfolioCategory $category, string $title, bool $published = true): PortfolioProject
    {
        return PortfolioProject::query()->create([
            'category_id' => $category->id,
            'title_en' => $title,
            'title_ar' => $title,
            'sort_order' => 1,
            'is_published' => $published,
            'featured' => false,
        ]);
    }

    public function test_admin_saves_project_body_html_and_related_projects(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $category = PortfolioCategory::query()->create([
            'slug' => 'identity', 'name_en' => 'Identity', 'name_ar' => 'الهوية', 'sort_order' => 1, 'is_published' => true,
        ]);
        $project = $this->project($category, 'Main');
        $first = $this->project($category, 'First');
        $second = $this->project($category, 'Second');

        $this->post('/api/admin/portfolio/projects/'.$project->id, [
            '_method' => 'PUT',
            'body_ar' => '<h2>التحدي</h2><p onclick="x()">نص <a href="javascript:alert(1)">رابط</a></p><script>alert(1)</script>',
            'body_en' => '<h2>Challenge</h2><p>Text</p>',
            'related_ids' => [(string) $second->id, (string) $first->id, (string) $project->id],
        ])->assertOk()
            ->assertJsonPath('data.related_ids', [$second->id, $first->id]);

        $project->refresh();
        $this->assertSame('<h2>التحدي</h2><p>نص <a>رابط</a></p>', $project->body_ar);
        $this->assertSame('<h2>Challenge</h2><p>Text</p>', $project->body_en);

        // An empty list clears the related projects; leaving the field out keeps them.
        $this->post('/api/admin/portfolio/projects/'.$project->id, ['_method' => 'PUT', 'title_en' => 'Main 2'])->assertOk();
        $this->assertSame([$second->id, $first->id], $project->related()->pluck('portfolio_projects.id')->all());
        $this->post('/api/admin/portfolio/projects/'.$project->id, ['_method' => 'PUT', 'related_sync' => '1'])
            ->assertOk()
            ->assertJsonPath('data.related_ids', []);
    }

    public function test_public_project_details_include_body_and_published_related_projects(): void
    {
        $category = PortfolioCategory::query()->create([
            'slug' => 'identity', 'name_en' => 'Identity', 'name_ar' => 'الهوية', 'sort_order' => 1, 'is_published' => true,
        ]);
        $project = $this->project($category, 'Main');
        $project->forceFill(['body_ar' => '<p>تفاصيل</p>'])->save();
        $shown = $this->project($category, 'Shown');
        $hidden = $this->project($category, 'Hidden', false);
        $project->related()->sync([$hidden->id => ['sort_order' => 0], $shown->id => ['sort_order' => 1]]);

        $this->getJson('/api/portfolio/projects/'.$project->id)
            ->assertOk()
            ->assertJsonPath('data.body_ar', '<p>تفاصيل</p>')
            ->assertJsonCount(1, 'data.related')
            ->assertJsonPath('data.related.0.id', $shown->id)
            ->assertJsonPath('data.related.0.title_en', 'Shown')
            ->assertJsonPath('data.related.0.category.slug', 'identity');
    }
}
