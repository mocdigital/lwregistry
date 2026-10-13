<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation itself, as a graph an administrator can draw.
 *
 * Greeting somebody, asking their name, offering four things to ask about and
 * falling back to the knowledge bank are all the same kind of step with
 * different settings. Written as code they are a state machine only a
 * developer can change; written as nodes and wires they are something the
 * people who actually talk to customers can rearrange.
 *
 * THREE TABLES, matching the three things on the canvas:
 *
 *   blueprints — one conversation. Only one is live at a time; the rest are
 *                drafts, so a flow can be built without a visitor walking
 *                through a half-finished one.
 *   nodes      — a step. `type` decides what it does and `config` carries what
 *                it says; `x`/`y` are where it sits on the canvas, which is
 *                presentation and deliberately stored beside it rather than in
 *                a second place that could drift.
 *   wires      — a connection from one node's named output PIN to another
 *                node's input. The pin is why this is not a simple `next_id`:
 *                a choice node has an output per option, and which pin the
 *                wire leaves from is the answer somebody gave.
 *
 * The knowledge bank is no longer the whole assistant — it is one node type
 * among several, reached when the flow decides to answer from it.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_blueprints', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // Exactly one live blueprint. Enforced in the model rather than by
            // a partial index, which SQLite and MySQL spell differently.
            $table->boolean('is_active')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('chatbot_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('chatbot_blueprints')->cascadeOnDelete();

            /*
             * The editor generates these, and wires reference them. A string
             * key rather than the row id, so a blueprint can be exported,
             * edited and imported without every wire having to be renumbered.
             */
            $table->string('key', 64);

            // See App\Support\Chatbot\NodeType.
            $table->string('type', 32);

            /** @var array<string, mixed> What the node says, asks or stores. */
            $table->json('config')->nullable();

            $table->integer('x')->default(0);
            $table->integer('y')->default(0);

            $table->timestamps();

            $table->unique(['blueprint_id', 'key']);
        });

        Schema::create('chatbot_wires', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('chatbot_blueprints')->cascadeOnDelete();

            $table->string('from_node', 64);

            /*
             * Which output the wire leaves from. 'out' on a node with one
             * answer; on a choice node it is the option that was picked, which
             * is how one node branches without a condition anywhere.
             */
            $table->string('from_pin', 64)->default('out');

            $table->string('to_node', 64);

            $table->timestamps();

            $table->unique(['blueprint_id', 'from_node', 'from_pin']);
        });

        /*
         * Where a visitor has got to. One row per session, holding the node
         * they are on and whatever the flow has collected — the name it
         * greeted them by, the email it asked for.
         */
        Schema::create('chatbot_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id', 64)->unique();
            $table->foreignId('blueprint_id')->nullable()->constrained('chatbot_blueprints')->nullOnDelete();
            $table->string('current_node', 64)->nullable();

            /** @var array<string, mixed> Answers collected so far. */
            $table->json('data')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_sessions');
        Schema::dropIfExists('chatbot_wires');
        Schema::dropIfExists('chatbot_nodes');
        Schema::dropIfExists('chatbot_blueprints');
    }
};
