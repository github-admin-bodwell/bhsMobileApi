<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Biometrics v2, task V5.
 *
 * Three things, in order: clean the table, then narrow the columns so the composite
 * index fits SQL Server's key-size limit, then add the index.
 *
 * The cleanup deletes rows, and that is safe here because the rows it deletes are
 * provably unusable: /auth/device/issue (v1) answers 410 unconditionally and is never
 * coming back, and /auth/device/v2/issue refuses any record without a device_id. A
 * token that cannot be exchanged by either route is a long-lived credential sitting in
 * a table earning nothing.
 */
return new class extends Migration {
    private const MONTHS = 12;

    public function up(): void
    {
        // 1. Unbound tokens can never be exchanged under v2. Drop them.
        DB::table('device_tokens')->whereNull('device_id')->delete();

        // 2. Collapse duplicate (tokenable_type, tokenable_id, device_id) rows, keeping
        //    the newest. enable() now replaces rather than stacks, so this is one-off.
        $dupes = DB::table('device_tokens')
            ->select('tokenable_type', 'tokenable_id', 'device_id', DB::raw('MAX(id) as keep_id'))
            ->groupBy('tokenable_type', 'tokenable_id', 'device_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $row) {
            DB::table('device_tokens')
                ->where('tokenable_type', $row->tokenable_type)
                ->where('tokenable_id', $row->tokenable_id)
                ->where('device_id', $row->device_id)
                ->where('id', '!=', $row->keep_id)
                ->delete();
        }

        // 3. Backfill the rolling window for anything that survived, measured from last
        //    use where we have it, creation otherwise.
        DB::table('device_tokens')->whereNull('expires_at')->orderBy('id')
            ->each(function ($row) {
                $from = $row->last_used_at ?? $row->created_at;
                DB::table('device_tokens')->where('id', $row->id)->update([
                    'expires_at' => $from
                        ? \Illuminate\Support\Carbon::parse($from)->addMonths(self::MONTHS)
                        : now()->addMonths(self::MONTHS),
                ]);
            });

        // 4. Narrow the two string columns before indexing them. Laravel's default
        //    string(255) is nvarchar(255) = 510 bytes each on SQL Server; the three
        //    columns together would be ~1028 bytes, over the 900-byte nonclustered
        //    index key limit on SQL Server 2014 and earlier. 64 + 191 + bigint keeps it
        //    around 518 bytes, which is safe on every version. device_id also becomes
        //    NOT NULL now that step 1 has cleared the nulls.
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->string('device_id', 64)->change();
            $table->string('tokenable_type', 191)->change();
        });

        // 5. One live token per user per device.
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->unique(
                ['tokenable_type', 'tokenable_id', 'device_id'],
                'device_tokens_owner_device_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique('device_tokens_owner_device_unique');
        });

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->string('device_id', 255)->nullable()->change();
            $table->string('tokenable_type', 255)->change();
        });
    }
};
