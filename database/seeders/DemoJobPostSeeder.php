<?php

namespace Database\Seeders;

use App\Enums\JobPostStatus;
use App\Enums\JobPostVisibility;
use App\Models\CleaningJobCategory;
use App\Models\CleaningJobPost;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dev-only demo job posts spanning categories, visibilities, statuses, pay,
 * and media so the frontend can develop the browse/filter/sort UI and the
 * employer dashboard against real records. Idempotent (keyed on employer +
 * title); depends on DemoProfileSeeder having created the demo employers.
 */
class DemoJobPostSeeder extends Seeder
{
    public function run(): void
    {
        $employers = User::whereIn('email', ['employer1@demo.test', 'employer2@demo.test', 'employer3@demo.test'])
            ->get()
            ->keyBy('email');

        if ($employers->isEmpty()) {
            return;
        }

        $categories = CleaningJobCategory::pluck('id', 'slug');

        $posts = [
            [
                'email' => 'employer1@demo.test', 'category' => 'office',
                'title' => 'Office Nightly Cleaning', 'country' => 'Philippines', 'city' => 'Manila',
                'visibility' => JobPostVisibility::Published, 'status' => JobPostStatus::Open,
                'pay_amount' => 22.50, 'pay_currency' => 'USD', 'days' => 10, 'media' => true,
            ],
            [
                'email' => 'employer1@demo.test', 'category' => 'public-space',
                'title' => 'Mall Deep Clean Contract', 'country' => 'Philippines', 'city' => 'Makati',
                'visibility' => JobPostVisibility::Published, 'status' => JobPostStatus::Closed,
                'pay_amount' => 1800.00, 'pay_currency' => 'USD', 'days' => 4, 'media' => false,
            ],
            [
                'email' => 'employer2@demo.test', 'category' => 'hotel',
                'title' => 'Hotel Housekeeping Team', 'country' => 'Germany', 'city' => 'Berlin',
                'visibility' => JobPostVisibility::Published, 'status' => JobPostStatus::Open,
                'pay_amount' => 140.00, 'pay_currency' => 'EUR', 'days' => 21, 'media' => true,
            ],
            [
                'email' => 'employer2@demo.test', 'category' => 'hotel',
                'title' => 'Seasonal Room Turnover', 'country' => 'Germany', 'city' => 'Munich',
                'visibility' => JobPostVisibility::Published, 'status' => JobPostStatus::Completed,
                'pay_amount' => 130.00, 'pay_currency' => 'EUR', 'days' => 2, 'media' => false,
            ],
            [
                'email' => 'employer3@demo.test', 'category' => 'residential',
                'title' => 'Home Weekly Cleaning', 'country' => 'Philippines', 'city' => 'Davao City',
                'visibility' => JobPostVisibility::Published, 'status' => JobPostStatus::Open,
                'pay_amount' => 18.00, 'pay_currency' => 'USD', 'days' => 7, 'media' => false,
            ],
            [
                'email' => 'employer3@demo.test', 'category' => 'residential',
                'title' => 'Move-out Deep Clean', 'country' => 'Philippines', 'city' => 'Davao City',
                'visibility' => JobPostVisibility::Draft, 'status' => JobPostStatus::Open,
                'pay_amount' => 90.00, 'pay_currency' => 'USD', 'days' => 14, 'media' => false,
            ],
        ];

        foreach ($posts as $data) {
            $employer = $employers->get($data['email']);
            $categoryId = $categories->get($data['category']);

            if ($employer === null || $categoryId === null) {
                continue;
            }

            $post = CleaningJobPost::firstOrCreate(
                ['employer_id' => $employer->id, 'title' => $data['title']],
                [
                    'cleaning_job_category_id' => $categoryId,
                    'description' => 'Demo listing for '.$data['title'].'. Reliable, detail-oriented cleaning help needed.',
                    'country' => $data['country'],
                    'city' => $data['city'],
                    'schedule_date' => Carbon::now()->addDays($data['days'])->toDateString(),
                    'start_time' => '09:00',
                    'end_time' => '13:00',
                    'cleaners_needed' => 2,
                    'visibility' => $data['visibility'],
                    'status' => $data['status'],
                    'pay_amount' => $data['pay_amount'],
                    'pay_currency' => $data['pay_currency'],
                ],
            );

            if ($post->wasRecentlyCreated && $data['media']) {
                $post->media = [$this->placeholderImage()];
                $post->save();
            }
        }
    }

    /**
     * Generate and store a small placeholder image so demo media resolves to a
     * real file on the public disk rather than a broken link.
     *
     * @return array{name: string, path: string}
     */
    protected function placeholderImage(): array
    {
        $image = imagecreatetruecolor(400, 300);

        if ($image === false) {
            throw new RuntimeException('Unable to create a placeholder image.');
        }

        $color = imagecolorallocate($image, random_int(90, 200), random_int(90, 200), random_int(90, 200));

        if ($color === false) {
            throw new RuntimeException('Unable to allocate a placeholder color.');
        }

        imagefill($image, 0, 0, $color);

        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        $path = 'job-media/'.Str::random(24).'.png';
        Storage::disk('public')->put($path, $contents);

        return ['name' => 'site-photo.png', 'path' => $path];
    }
}
