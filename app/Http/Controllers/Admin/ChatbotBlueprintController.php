<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotBlueprint;
use App\Models\ChatbotNode;
use App\Models\ChatbotWire;
use App\Support\AdminCapabilities;
use App\Support\Chatbot\NodeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Reading and writing a blueprint's graph.
 *
 * The canvas is JavaScript and talks to these two endpoints rather than to a
 * Livewire component: a node editor sends a whole graph on save, and routing
 * that through component state would mean every drag round-tripping to the
 * server.
 *
 * The graph is replaced wholesale rather than diffed. It is small, it arrives
 * complete, and reconciling three tables against an incoming shape is a lot of
 * code whose only advantage would be preserving row ids nothing refers to —
 * wires point at node KEYS precisely so this is safe.
 */
final class ChatbotBlueprintController extends Controller
{
    public function show(Request $request, ChatbotBlueprint $blueprint): JsonResponse
    {
        $this->authorizeAdmin($request);

        $blueprint->load(['nodes', 'wires']);

        return response()->json([
            'id' => $blueprint->id,
            'name' => $blueprint->name,
            'isActive' => $blueprint->is_active,
            'nodeTypes' => NodeType::all(),
            'nodes' => $blueprint->nodes->map(fn (ChatbotNode $n): array => [
                'key' => $n->key,
                'type' => $n->type,
                'config' => $n->config ?? [],
                'x' => $n->x,
                'y' => $n->y,
                // Sent rather than worked out in the browser, so the canvas and
                // the runtime cannot disagree about a node's pins.
                'pins' => $n->pins(),
            ])->values(),
            'wires' => $blueprint->wires->map(fn (ChatbotWire $w): array => [
                'from' => $w->from_node,
                'pin' => $w->from_pin,
                'to' => $w->to_node,
            ])->values(),
        ]);
    }

    public function save(Request $request, ChatbotBlueprint $blueprint): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'nodes' => ['required', 'array', 'max:200'],
            'nodes.*.key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'nodes.*.type' => ['required', Rule::in(array_keys(NodeType::all()))],
            'nodes.*.config' => ['array'],
            'nodes.*.x' => ['required', 'integer'],
            'nodes.*.y' => ['required', 'integer'],

            'wires' => ['array', 'max:400'],
            'wires.*.from' => ['required', 'string', 'max:64'],
            'wires.*.pin' => ['required', 'string', 'max:64'],
            'wires.*.to' => ['required', 'string', 'max:64'],
        ]);

        $keys = collect($data['nodes'])->pluck('key');

        // Two nodes with one key would make every wire ambiguous.
        abort_if($keys->duplicates()->isNotEmpty(), 422, 'Two nodes share a key.');

        /*
         * A wire to a node that is not in this save would be a dangling
         * reference the runner walks into and stops at — which reads to a
         * visitor as the assistant giving up mid-sentence.
         */
        foreach ($data['wires'] ?? [] as $wire) {
            abort_unless(
                $keys->contains($wire['from']) && $keys->contains($wire['to']),
                422,
                'A wire points at a node that is not on the canvas.',
            );
        }

        DB::transaction(function () use ($blueprint, $data): void {
            $blueprint->nodes()->delete();
            $blueprint->wires()->delete();

            foreach ($data['nodes'] as $node) {
                ChatbotNode::create([
                    'blueprint_id' => $blueprint->id,
                    'key' => $node['key'],
                    'type' => $node['type'],
                    'config' => $node['config'] ?? [],
                    'x' => $node['x'],
                    'y' => $node['y'],
                ]);
            }

            foreach ($data['wires'] ?? [] as $wire) {
                ChatbotWire::create([
                    'blueprint_id' => $blueprint->id,
                    'from_node' => $wire['from'],
                    'from_pin' => $wire['pin'],
                    'to_node' => $wire['to'],
                ]);
            }
        });

        return response()->json(['saved' => true]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->hasPermission(AdminCapabilities::VIEW_ADMIN),
            403,
        );
    }
}
