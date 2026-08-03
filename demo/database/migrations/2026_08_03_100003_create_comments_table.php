<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // EX-307, Phase 9bis : relation polymorphique morphMany/morphTo,
        // type de relation jusque-là non couvert par la démo. Un commentaire
        // peut cibler indifféremment une Category ou un Product.
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable');
            $table->string('author');
            $table->text('body');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
