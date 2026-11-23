<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Mail (MS/MT/MM/ML/NT/CO): accounts, sender profiles, templates with versions, the outgoing queue that is also the
 * mail log, mailings, incoming replies, consents/opt-outs and admin notifications. Upgrade-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(465);
            $table->string('smtp_encryption', 8)->default('ssl'); // ssl (implicit TLS) or tls (STARTTLS)
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable(); // encrypted
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable(); // encrypted
            $table->string('imap_sent_folder')->nullable(); // copy of every sent mail (empty = no copy)
            $table->unsignedInteger('rate_per_minute')->nullable();
            $table->unsignedInteger('rate_per_hour')->nullable();
            $table->unsignedInteger('rate_per_day')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('last_imap_check_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sender_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('from_name');
            $table->string('from_address');
            $table->string('reply_to')->nullable();
            $table->foreignId('mail_account_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('category', 32);
            $table->json('name');
            $table->foreignId('sender_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audience', 16)->default('guardians'); // guardians, person, both
            $table->boolean('is_standard')->default(false);
            $table->unsignedInteger('standard_version')->nullable(); // version of the shipped default it was copied from
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('mail_template_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_template_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('subject');
            $table->longText('html');
            $table->longText('text')->nullable(); // generated from html when empty
            $table->timestamps();
            $table->unique(['mail_template_id', 'locale']);
        });

        Schema::create('mail_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('mailings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('mail_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->json('recipients'); // {group_ids: [], event_id: null, statuses: [], person_ids: []}
            $table->string('routing', 16)->default('guardians'); // guardians, person, both
            $table->boolean('one_per_family')->default(true);
            $table->boolean('edit_before_sending')->default(false);
            $table->string('status', 16)->default('draft'); // draft, prepared, sending, sent, cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        // Queue and mail log at the same time (MS01, ML01).
        Schema::create('outgoing_mails', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('sender_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mail_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('template_version')->nullable();
            $table->foreignId('mailing_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete(); // recipient
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_address');
            $table->string('to_name')->nullable();
            $table->string('subject');
            $table->longText('html');
            $table->longText('text');
            $table->json('attachments')->nullable();
            $table->string('kind', 16)->default('transactional'); // transactional, mailing, newsletter, notification
            $table->string('status', 16)->default('queued'); // held, queued, sending, sent, failed, bounced, cancelled
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->boolean('edited')->default(false);
            $table->timestamp('send_after')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('message_id')->nullable()->index();
            $table->timestamp('imap_copied_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'send_after']);
        });

        Schema::create('incoming_mails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable();
            $table->string('from_address');
            $table->string('subject')->nullable();
            $table->longText('text')->nullable();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('outgoing_mail_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16)->default('reply'); // reply, bounce
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        // Consents and opt-outs with proof (CO02–CO05).
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('kind', 32); // newsletter, optout_group, optout_event_series, deletion_request
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->boolean('granted');
            $table->string('source', 64)->nullable(); // form, mail_link, portal, admin
            $table->string('text_version')->nullable();
            $table->timestamps();
            $table->index(['person_id', 'kind']);
        });

        Schema::create('notification_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 48);
            $table->string('frequency', 16)->default('daily'); // immediate, daily, weekly, off
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'topic']);
        });

        // Non-confidential events for admin notifications and digests (NT01–NT05).
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 48)->index();
            $table->string('summary');
            $table->string('url')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }
};
