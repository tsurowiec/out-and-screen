<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The push service URL the browser handed us. Unique because a
            // re-subscribe on the same device returns the same endpoint, and we
            // want that to update the row rather than pile up duplicates.
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();

            $table->string('public_key');
            $table->string('auth_token');
            // The browser tells us which encoding it speaks. Everything current
            // uses the RFC 8291 one; the library still defaults to the older
            // draft, so it's stored explicitly rather than left to chance.
            $table->string('content_encoding')->default('aes128gcm');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
