<?php

use Lyre\AiAgents\Facades\Agents;

$stream = Agents::stream('sales-bot', 'Draft a follow-up message for the lead');

foreach ($stream as $chunk) {
    echo $chunk;
}
