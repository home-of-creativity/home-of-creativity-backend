<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $categories = DB::table('pricing_categories')->get();
        foreach ($categories as $category) {
            $haystack = strtolower(trim(($category->slug ?? '').' '.($category->name_en ?? '').' '.($category->name_ar ?? '')));
            $full = str_contains($haystack, 'reach')
                || str_contains($haystack, 'ads')
                || str_contains($haystack, 'ad-')
                || str_contains($haystack, 'انتشار')
                || str_contains($haystack, 'إعلان')
                || str_contains($haystack, 'اعلان')
                || str_contains($haystack, 'ممولة');

            DB::table('pricing_categories')->where('id', $category->id)->update([
                'requires_full_payment' => $full,
                'allows_renewal' => false,
            ]);
        }

        $packages = DB::table('pricing_packages')->get();
        foreach ($packages as $package) {
            $reach = $package->reach;
            $hasReach = filled($reach) && $reach !== 'null' && $reach !== '[]' && $reach !== '{}';
            if ($hasReach) {
                DB::table('pricing_packages')->where('id', $package->id)->update([
                    'allows_partial_payment' => false,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('pricing_packages')->update(['allows_partial_payment' => null]);
        DB::table('pricing_categories')->update([
            'requires_full_payment' => false,
            'allows_renewal' => false,
        ]);
    }
};
