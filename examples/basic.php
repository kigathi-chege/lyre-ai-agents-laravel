<?php

use Lyre\AiAgents\Facades\Agents;

Agents::registerTool([
    'name' => 'submit_lead',
    'type' => 'function',
    'description' => 'Submit lead to CRM',
    'parameters_schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'phone' => ['type' => 'string'],
        ],
        'required' => ['name', 'phone'],
    ],
    'handler' => function (array $args) {
        return ['ok' => true, 'lead_id' => 'LD-'.strtoupper(substr(md5(json_encode($args)), 0, 8))];
    },
]);

$agent = Agents::registerAgent([
    'name' => 'sales-bot',
    'model' => 'gpt-4.1-mini',
    'instructions' => 'Collect leads and confirm details.',
]);

return Agents::run($agent->id, 'Register lead John +254700001111');
