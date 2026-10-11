<?php

namespace Tests\Feature;

use App\Attachments\HasAttachments;
use App\Attachments\Thumbnail;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        // Something to attach to: the real ones (tasks, time logs, expenses) come later.
        Schema::create('jobs_for_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Relation::morphMap(['job' => Job::class, 'user' => User::class]);

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->job = Job::create(['name' => 'Rewire the shed']);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function upload(UploadedFile $file, array $fields = [])
    {
        return $this->actingAs($this->user)->post('/attachments', $fields + [
            'attachable_type' => 'job',
            'attachable_id' => $this->job->id,
            'file' => $file,
        ]);
    }

    private function pdf(string $name = 'quote.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    /**
     * A file on disk, so its kind is read from its contents. A faked upload takes its kind from its name.
     */
    private function realFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tkcrm-upload-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_a_real_file_is_judged_by_its_contents_and_kept_when_they_match(): void
    {
        $this->upload($this->realFile('renamed.txt', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n"))->assertOk();

        $attachment = Attachment::sole();
        $this->assertSame('application/pdf', $attachment->mime_type);
        $this->assertStringEndsWith('.pdf', $attachment->path);
        $this->assertSame('renamed.txt', $attachment->file_name);
    }

    public function test_the_private_disk_is_under_the_data_directory_and_outside_the_web_root(): void
    {
        $root = (require base_path('config/filesystems.php'))['disks']['local']['root'];

        $this->assertSame(base_path('data/private'), $root);
        $this->assertStringStartsNotWith(public_path(), $root);
        $this->assertFalse((require base_path('config/filesystems.php'))['disks']['local']['serve']);
    }

    public function test_a_guest_can_do_nothing(): void
    {
        $this->upload($this->pdf());
        $attachment = Attachment::sole();
        auth()->logout();

        $this->post('/attachments', ['attachable_type' => 'job', 'attachable_id' => $this->job->id, 'file' => $this->pdf()])->assertRedirect(route('login'));
        $this->get("/attachments/{$attachment->id}")->assertRedirect(route('login'));
        $this->get("/attachments/{$attachment->id}/thumbnail")->assertRedirect(route('login'));
        $this->delete("/attachments/{$attachment->id}")->assertRedirect(route('login'));

        $this->assertSame(1, Attachment::count());
    }

    public function test_an_upload_is_stored_on_the_private_disk_and_recorded(): void
    {
        $file = $this->pdf('Signed quote.pdf');

        $this->upload($file, ['label' => 'Signed quote', 'notes' => 'Countersigned 3 May'])
            ->assertOk()
            ->assertSee('<div id="attachments-job-'.$this->job->id.'"', false)
            ->assertSeeInOrder(['Signed quote', 'Signed quote.pdf', 'Countersigned 3 May'])
            ->assertDontSee('<html', false);

        $attachment = $this->job->attachments()->sole();

        $this->assertSame(
            ['Signed quote', 'Signed quote.pdf', $file->getSize(), 'application/pdf', 'Countersigned 3 May', null],
            [$attachment->label, $attachment->file_name, $attachment->bytes, $attachment->mime_type, $attachment->notes, $attachment->thumbnail_path],
        );
        $this->assertMatchesRegularExpression('#^attachments/job/'.$this->job->id.'/[A-Za-z0-9]{40}\.pdf$#', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_a_label_left_blank_is_the_files_name(): void
    {
        $this->upload($this->pdf('March receipt.pdf'))->assertOk();

        $this->assertSame('March receipt', Attachment::sole()->label);
        $this->assertNull(Attachment::sole()->notes);
    }

    public function test_an_image_gets_a_thumbnail(): void
    {
        $this->upload(UploadedFile::fake()->image('site photo.png', 1200, 600))->assertOk()
            ->assertSee(route('attachments.thumbnail', Attachment::sole()), false);

        $attachment = Attachment::sole();
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertTrue($attachment->isImage());
        $this->assertStringEndsWith('_thumb.jpg', $attachment->thumbnail_path);

        [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($attachment->thumbnail_path));
        $this->assertSame([240, 120, IMAGETYPE_JPEG], [$width, $height, $type]);
    }

    public function test_a_small_image_is_not_enlarged_and_a_file_that_is_no_image_gives_no_thumbnail(): void
    {
        $small = UploadedFile::fake()->image('icon.png', 40, 30);

        [$width, $height] = getimagesizefromstring(Thumbnail::make(file_get_contents($small->getRealPath()), 240));
        $this->assertSame([40, 30], [$width, $height]);

        $this->assertNull(Thumbnail::make('not an image at all', 240));
        $this->assertNull(Thumbnail::make('', 240));
    }

    public function test_a_file_over_the_size_limit_is_refused_and_what_was_typed_comes_back(): void
    {
        config(['tkcrm.attachments.max_kb' => 100]);

        $this->upload(UploadedFile::fake()->image('huge.jpg')->size(101), ['label' => 'Too big', 'notes' => 'Typed notes'])
            ->assertStatus(422)
            ->assertSee('<div id="attachments-job-'.$this->job->id.'"', false)
            ->assertSee('The file may not be larger than')
            ->assertSee('value="Too big"', false)
            ->assertSee('value="Typed notes"', false);

        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_default_limit_is_ten_megabytes(): void
    {
        $this->assertSame(10240, config('tkcrm.attachments.max_kb'));

        $this->upload(UploadedFile::fake()->image('big.jpg')->size(10241))->assertStatus(422);
        $this->upload(UploadedFile::fake()->image('ok.jpg')->size(10240))->assertOk();
    }

    public function test_kinds_of_file_that_are_not_allowed_are_refused(): void
    {
        $refused = [
            UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'),
            UploadedFile::fake()->createWithContent('drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;'),
            UploadedFile::fake()->create('setup.exe', 20, 'application/x-msdownload'),
            // The name says PDF but the contents are a web page.
            $this->realFile('innocent.pdf', '<html><body><script>alert(1)</script></body></html>'),
            $this->realFile('photo.png', '<?php echo 1;'),
        ];

        foreach ($refused as $file) {
            $this->upload($file)->assertStatus(422)->assertSee('That kind of file cannot be attached.');
        }

        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_file_is_needed(): void
    {
        $this->actingAs($this->user)
            ->post('/attachments', ['attachable_type' => 'job', 'attachable_id' => $this->job->id, 'label' => 'Nothing'])
            ->assertStatus(422)
            ->assertSee(__('validation.required', ['attribute' => 'file']));
    }

    public function test_it_only_attaches_to_a_record_that_takes_attachments(): void
    {
        $post = fn (array $to) => $this->actingAs($this->user)->post('/attachments', $to + ['file' => $this->pdf()]);

        $post(['attachable_type' => 'nonsense', 'attachable_id' => 1])->assertNotFound();
        $post(['attachable_type' => Job::class, 'attachable_id' => $this->job->id])->assertNotFound();
        $post(['attachable_type' => 'user', 'attachable_id' => $this->user->id])->assertNotFound();
        $post(['attachable_type' => 'job', 'attachable_id' => 9999])->assertNotFound();
        $post(['attachable_id' => $this->job->id])->assertNotFound();

        $this->assertSame(0, Attachment::count());
    }

    public function test_a_pdf_or_an_image_opens_in_the_browser_and_anything_else_downloads(): void
    {
        $this->upload($this->pdf('Signed quote.pdf'));
        $this->upload(UploadedFile::fake()->createWithContent('hours.csv', "date,hours\n2026-05-01,3.5\n"));
        [$pdf, $csv] = Attachment::orderBy('id')->get()->all();

        $this->actingAs($this->user)->get("/attachments/{$pdf->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename="Signed quote.pdf"');

        $this->actingAs($this->user)->get("/attachments/{$pdf->id}?download=1")
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="Signed quote.pdf"');

        $response = $this->actingAs($this->user)->get("/attachments/{$csv->id}");
        $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame("date,hours\n2026-05-01,3.5\n", $response->streamedContent());
    }

    public function test_the_thumbnail_is_served_and_is_not_found_where_there_is_none(): void
    {
        $this->upload(UploadedFile::fake()->image('photo.jpg', 800, 800));
        $this->upload($this->pdf());
        [$image, $pdf] = Attachment::orderBy('id')->get()->all();

        $this->actingAs($this->user)->get("/attachments/{$image->id}/thumbnail")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->user)->get("/attachments/{$pdf->id}/thumbnail")->assertNotFound();
        $this->actingAs($this->user)->get('/attachments/9999')->assertNotFound();
    }

    public function test_a_file_missing_from_the_disk_is_not_found(): void
    {
        $this->upload($this->pdf());
        $attachment = Attachment::sole();
        Storage::disk('local')->delete($attachment->path);

        $this->actingAs($this->user)->get("/attachments/{$attachment->id}")->assertNotFound();
    }

    public function test_deleting_an_attachment_removes_its_row_its_file_and_its_thumbnail(): void
    {
        $this->upload(UploadedFile::fake()->image('photo.jpg', 800, 800), ['label' => 'Photo']);
        $this->upload($this->pdf(), ['label' => 'Kept quote']);
        $photo = Attachment::where('label', 'Photo')->sole();

        $this->actingAs($this->user)
            ->delete("/attachments/{$photo->id}")
            ->assertOk()
            ->assertSee('<div id="attachments-job-'.$this->job->id.'"', false)
            ->assertSee('Kept quote')
            ->assertDontSee('Photo');

        $this->assertSame(['Kept quote'], Attachment::pluck('label')->all());
        Storage::disk('local')->assertMissing($photo->path);
        Storage::disk('local')->assertMissing($photo->thumbnail_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_deleting_the_record_removes_its_attachments_and_their_files(): void
    {
        $this->upload(UploadedFile::fake()->image('photo.jpg', 800, 800));
        $this->upload($this->pdf());

        $other = Job::create(['name' => 'Another job']);
        $this->actingAs($this->user)->post('/attachments', ['attachable_type' => 'job', 'attachable_id' => $other->id, 'file' => $this->pdf()]);

        $this->job->delete();

        $this->assertSame(1, Attachment::count());
        $this->assertSame(1, $other->attachments()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_the_component_lists_the_files_and_has_the_upload_form(): void
    {
        view()->share('errors', new ViewErrorBag);

        $this->actingAs($this->user)
            ->blade('<x-attachments :for="$job" />', ['job' => $this->job])
            ->assertSee('<div id="attachments-job-'.$this->job->id.'"', false)
            ->assertSee('No files attached.')
            ->assertSee('hx-post="'.route('attachments.store').'" hx-encoding="multipart/form-data"', false)
            ->assertSee('name="attachable_type" value="job"', false)
            ->assertSee('name="attachable_id" value="'.$this->job->id.'"', false)
            ->assertSee('Up to 10 MB');

        $this->upload($this->pdf('Signed quote.pdf'), ['label' => 'Quote <signed>', 'notes' => 'By <Sam>']);
        $attachment = Attachment::sole();

        $this->actingAs($this->user)
            ->blade('<x-attachments :for="$job" />', ['job' => $this->job])
            ->assertDontSee('No files attached.')
            ->assertSee('Quote &lt;signed&gt;', false)
            ->assertSee('By &lt;Sam&gt;', false)
            ->assertSee('Signed quote.pdf')
            ->assertSee('ri-file-pdf-line', false)
            ->assertSee('href="'.route('attachments.show', $attachment).'"', false)
            ->assertSee('hx-delete="'.route('attachments.destroy', $attachment).'"', false);
    }

    public function test_sizes_and_icons_read_well(): void
    {
        $this->assertSame('340 KB', (new Attachment(['bytes' => 340 * 1024]))->size());
        $this->assertSame('1.2 MB', (new Attachment(['bytes' => (int) (1.2 * 1024 * 1024)]))->size());
        $this->assertSame('ri-image-line', (new Attachment(['mime_type' => 'image/webp']))->icon());
        $this->assertSame('ri-file-excel-line', (new Attachment(['mime_type' => 'text/csv']))->icon());
        $this->assertSame('ri-file-text-line', (new Attachment(['mime_type' => 'text/plain']))->icon());
        $this->assertFalse((new Attachment(['mime_type' => 'image/svg+xml']))->showsInline());
    }
}

class Job extends Model
{
    use HasAttachments;

    public $timestamps = false;

    protected $table = 'jobs_for_attachments';

    protected $guarded = [];
}
