<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_identity_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('name');
            $table->enum('protocol', ['saml', 'oidc']);

            // The email domain used to route a login attempt to this
            // connection (e.g. "acme-corp.com"). One connection per domain.
            $table->string('domain');

            // Stays inactive by default so a newly created connection can be
            // test-driven before it can actually authenticate anyone.
            $table->boolean('is_active')->default(false);

            // When true, local password login is blocked for this domain
            // (see LoginRequest) so SSO cannot be silently bypassed.
            $table->boolean('enforce_sso')->default(false);

            // Protocol-specific settings (IdP entity ID / SSO URL / cert for
            // SAML, or issuer / client id / client secret for OIDC).
            // Encrypted at rest via the model's `encrypted:array` cast.
            $table->text('config')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('domain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_identity_providers');
    }
};
