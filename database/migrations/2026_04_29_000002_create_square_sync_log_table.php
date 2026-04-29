<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kirbygo_squarecatalogsync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16)->index(); // info | warning | error
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kirbygo_squarecatalogsync_logs');
    }
};
