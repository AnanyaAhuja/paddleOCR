<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per document (front + optional back): where the images are,
     * what was extracted, what the automatic checks said, and the manual review.
     */
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->string('batch');                       // import folder, e.g. 10-11-2026
            $table->string('case_number');                 // sub-folder, e.g. 100001
            $table->string('document_type');
            $table->string('front_path');                  // relative to the documents disk
            $table->string('back_path')->nullable();

            $table->string('status')->default('pending')->index(); // pending, processing, done, failed
            $table->text('error')->nullable();

            $table->json('fields')->nullable();            // extracted values
            $table->json('ocr')->nullable();               // per side: text, confidence, retried
            $table->json('checks')->nullable();            // MRZ and tax-code checks per field
            $table->json('judge')->nullable();             // JEV P(true) per field
            $table->json('timings')->nullable();
            $table->string('model')->nullable();

            $table->json('review')->nullable();            // per field: correct / wrong + corrected value
            $table->timestamp('reviewed_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['batch', 'case_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
