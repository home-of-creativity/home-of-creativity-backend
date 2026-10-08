<?php

namespace Tests\Feature;

use App\Models\ReportTemplate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_word_templates_round_trip_and_the_same_name_replaces(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->admin()->create());

        $catalog = $this->getJson('/api/admin/report-templates')->assertOk()->json('data');
        $this->assertSame(['social', 'campaign', 'minutes', 'blank'], array_column($catalog['builtin'], 'id'));
        $this->assertSame([], $catalog['saved']);

        $first = 'PK first template';
        $this->post('/api/admin/report-templates', [
            'name' => 'تقرير شهري',
            'document' => UploadedFile::fake()->createWithContent('monthly.docx', $first),
        ])->assertCreated()
            ->assertJsonPath('data.saved.0.name', 'تقرير شهري');

        $this->assertSame(1, ReportTemplate::query()->count());

        $second = 'PK replaced template';
        $this->post('/api/admin/report-templates', [
            'name' => 'تقرير شهري',
            'document' => UploadedFile::fake()->createWithContent('monthly.docx', $second),
        ])->assertCreated();

        $template = ReportTemplate::query()->firstOrFail();
        $this->assertSame(1, ReportTemplate::query()->count());
        $this->assertSame(
            $second,
            $this->get('/api/admin/report-templates/'.$template->id.'/document')->assertOk()->streamedContent(),
        );

        $this->deleteJson('/api/admin/report-templates/'.$template->id)
            ->assertOk()
            ->assertJsonPath('data.saved', []);
        $this->assertSame(0, ReportTemplate::query()->count());
        Storage::disk('local')->assertMissing($template->document_path);
    }

    public function test_report_templates_stay_behind_their_own_ability(): void
    {
        $reports = Role::query()->create([
            'name' => 'تقارير',
            'abilities' => ['ops.reports.view', 'ops.reports.create'],
        ]);
        $editor = User::factory()->create(['role_id' => $reports->id]);

        Sanctum::actingAs($editor);
        $this->getJson('/api/admin/report-templates')->assertForbidden();
        $this->post('/api/admin/report-templates', [
            'name' => 'قالب',
            'document' => UploadedFile::fake()->createWithContent('blank.docx', 'PK'),
        ])->assertForbidden();

        $reports->forceFill([
            'abilities' => ['ops.reports.view', 'ops.report_templates'],
        ])->save();

        Sanctum::actingAs($editor->fresh());
        $this->getJson('/api/admin/report-templates')->assertOk()->assertJsonPath('data.builtin.0.id', 'social');
    }
}
