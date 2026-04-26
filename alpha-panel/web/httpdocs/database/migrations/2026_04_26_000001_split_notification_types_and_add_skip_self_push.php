<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'skip_self_push')) {
                $table->boolean('skip_self_push')->default(false)->after('admin');
            }
        });

        $this->expandPreference('ssl_certificate', ['ssl_issuance', 'ssl_renewal']);
        $this->expandPreference('backup_status', ['backup_started', 'backup_completed', 'backup_failed']);
    }

    public function down(): void
    {
        $this->collapsePreference(['ssl_issuance', 'ssl_renewal'], 'ssl_certificate');
        $this->collapsePreference(['backup_started', 'backup_completed', 'backup_failed'], 'backup_status');

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'skip_self_push')) {
                $table->dropColumn('skip_self_push');
            }
        });
    }

    /**
     * Replace every preference row of $oldType with one row per $newTypes
     * carrying the same database/push/mail booleans.
     *
     * @param  array<int, string>  $newTypes
     */
    private function expandPreference(string $oldType, array $newTypes): void
    {
        $rows = DB::table('notification_preferences')->where('type', $oldType)->get();

        foreach ($rows as $row) {
            foreach ($newTypes as $newType) {
                DB::table('notification_preferences')->updateOrInsert(
                    ['user_id' => $row->user_id, 'type' => $newType],
                    [
                        'database' => $row->database,
                        'push' => $row->push,
                        'mail' => $row->mail,
                        'created_at' => $row->created_at,
                        'updated_at' => now(),
                    ],
                );
            }
        }

        DB::table('notification_preferences')->where('type', $oldType)->delete();
    }

    /**
     * Reverse expandPreference: pick the first new-type row per user as
     * the source of truth and rewrite it as the old type.
     *
     * @param  array<int, string>  $newTypes
     */
    private function collapsePreference(array $newTypes, string $oldType): void
    {
        $userIds = DB::table('notification_preferences')
            ->whereIn('type', $newTypes)
            ->pluck('user_id')
            ->unique();

        foreach ($userIds as $userId) {
            $source = DB::table('notification_preferences')
                ->where('user_id', $userId)
                ->whereIn('type', $newTypes)
                ->first();

            if ($source === null) {
                continue;
            }

            DB::table('notification_preferences')->updateOrInsert(
                ['user_id' => $userId, 'type' => $oldType],
                [
                    'database' => $source->database,
                    'push' => $source->push,
                    'mail' => $source->mail,
                    'created_at' => $source->created_at,
                    'updated_at' => now(),
                ],
            );
        }

        DB::table('notification_preferences')->whereIn('type', $newTypes)->delete();
    }
};
