<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->string('section', 32);                    // ดู App\Enums\RuleSection
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('kind', 16)->default('rule');      // rule | text | heading | highlight
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('note_label', 32)->nullable();
            $table->text('note')->nullable();
            $table->text('penalty')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['section', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rules');
    }
};
