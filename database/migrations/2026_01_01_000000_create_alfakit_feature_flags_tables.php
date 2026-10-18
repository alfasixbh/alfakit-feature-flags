<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('alfakit-feature-flags.connection');

        return is_string($connection) ? $connection : null;
    }

    public function up(): void
    {
        // One row per feature managed in the panel: kill switch, "everyone" value,
        // percentage rollout and schedule.
        Schema::create('alfakit_feature_flag_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('feature')->unique();
            // null (the feature's own definition), `everyone` or `rollout`.
            $table->string('mode', 20)->nullable();
            // JSON: the value everyone gets (`everyone`).
            $table->text('value')->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            // Changing it reshuffles who is in a rollout.
            $table->string('salt')->nullable();
            $table->timestamp('killed_at')->nullable();
            $table->timestamp('activate_at')->nullable();
            // JSON: the value the schedule activates (true by default).
            $table->text('activate_value')->nullable();
            $table->timestamp('deactivate_at')->nullable();
            $table->timestamps();
        });

        // Explicit values for one scope (a user, a team, a tenant…). They win over the
        // "everyone" value and rollouts; only the kill switch beats them.
        Schema::create('alfakit_feature_flag_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('feature');
            // Pennant's serialised scope (`App\Models\User|5`).
            $table->string('scope');
            $table->string('scope_type')->nullable();
            $table->string('scope_id')->nullable();
            // JSON.
            $table->text('value');
            // The tenant the entry was made in (tenant panels list only their own).
            $table->string('tenant_type')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('updated_by_type')->nullable();
            $table->string('updated_by_id')->nullable();
            $table->string('updated_by_name')->nullable();
            $table->timestamps();

            $table->unique(['feature', 'scope']);
            $table->index(['tenant_type', 'tenant_id']);
        });

        Schema::create('alfakit_feature_flag_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('feature')->index();
            $table->string('action', 40);
            $table->string('scope')->nullable();
            $table->string('scope_label')->nullable();
            // JSON snapshots of the feature (or the scope's value) before and after.
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('tenant_type')->nullable();
            $table->string('tenant_id')->nullable();
            // `panel`, `schedule` or `code`.
            $table->string('source', 20)->default('panel');
            $table->string('panel')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['tenant_type', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alfakit_feature_flag_audits');
        Schema::dropIfExists('alfakit_feature_flag_overrides');
        Schema::dropIfExists('alfakit_feature_flag_rules');
    }
};
