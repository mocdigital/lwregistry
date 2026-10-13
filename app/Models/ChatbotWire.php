<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A connection from one node's output pin to another node.
 *
 * The pin is the point: a choice node has an output per option, so which pin a
 * wire leaves from IS the answer somebody gave. That is why this is not a
 * `next_node_id` column on the node itself.
 */
final class ChatbotWire extends Model
{
    protected $fillable = ['blueprint_id', 'from_node', 'from_pin', 'to_node'];
}
