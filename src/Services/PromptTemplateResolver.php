<?php

namespace Lyre\AiAgents\Services;

use Illuminate\Support\Facades\Log;
use Lyre\AiAgents\Contracts\PromptSectionContributor;
use Lyre\AiAgents\Exceptions\PromptCompositionException;
use Lyre\AiAgents\Models\Agent;
use Lyre\AiAgents\Models\PromptTemplate;

class PromptTemplateResolver
{
    /**
     * @param  iterable<PromptSectionContributor>  $sectionContributors
     */
    public function __construct(
        protected iterable $sectionContributors = [],
    ) {
    }

    /**
     * Resolve the system instructions to send to OpenAI for a given agent.
     *
     * Resolution order:
     *  1. If `agent.instructions` is set, it wins outright (legacy contract).
     *  2. Otherwise, render the agent's template — walking parent templates via
     *     `extends_template_id` (root → leaf, depth-capped, cycle-safe) and
     *     substituting `{{variable}}` tokens from all available sources.
     *  3. Append output from any registered {@see PromptSectionContributor}s.
     *
     * @param  array<string,mixed>  $variables  optional caller-supplied variables
     *                                          merged on top of agent / template defaults.
     */
    public function resolveInstructionsForAgent(Agent $agent, array $variables = []): ?string
    {
        $agentInstructions = trim((string) ($agent->instructions ?? ''));
        if ($agentInstructions !== '') {
            return $agentInstructions;
        }

        $template = $this->resolveTemplateForAgent($agent);
        if (!$template) {
            return $this->appendContributorSections($agent, null);
        }

        $chain = $this->resolveInheritanceChain($template);
        $separator = (string) config('ai-agents.prompts.section_separator', "\n\n");
        $rendered = trim(implode($separator, array_map(
            fn (PromptTemplate $t) => (string) $t->content,
            $chain
        )));

        $vars = $this->buildVariableMap($agent, $chain, $variables);
        $rendered = $this->substituteVariables($rendered, $vars);

        return $this->appendContributorSections($agent, $rendered);
    }

    public function resolveRuntimeInstructionsForConversation(
        Agent $agent,
        ?string $conversationContext = null,
        array $variables = []
    ): ?string {
        $base = $this->resolveInstructionsForAgent($agent, $variables);
        $separator = (string) config('ai-agents.prompts.section_separator', "\n\n");
        $parts = [];

        if (is_string($base) && trim($base) !== '') {
            $parts[] = trim($base);
        }

        $parts[] = trim(implode("\n", [
            'Conversation handling rules:',
            '- Answer the latest user message as the live turn you are responding to now.',
            '- If the latest user message is primarily a greeting, reply with a salutation.',
            '- If the greeting or latest message refers to earlier discussion, keep continuity with that prior context.',
            '- When prior context conflicts, seems stale, or the user changes direction, prioritize the more recent messages.',
        ]));

        if (is_string($conversationContext) && trim($conversationContext) !== '') {
            $parts[] = trim($conversationContext);
        }

        return trim(implode($separator, array_filter($parts, fn ($part) => $part !== '')));
    }

    public function defaultTemplateId(): ?int
    {
        return $this->resolveDefaultTemplate()?->id;
    }

    protected function resolveTemplateForAgent(Agent $agent): ?PromptTemplate
    {
        if (!empty($agent->prompt_template_id)) {
            $template = PromptTemplate::query()
                ->where('id', $agent->prompt_template_id)
                ->where('is_active', true)
                ->first();

            if ($template) {
                return $template;
            }
        }

        return $this->resolveDefaultTemplate();
    }

    protected function resolveDefaultTemplate(): ?PromptTemplate
    {
        $defaultKey = (string) config('ai-agents.prompts.default_key', 'enterprise_default');

        return PromptTemplate::query()
            ->where('is_active', true)
            ->where(function ($query) use ($defaultKey) {
                $query->where('key', $defaultKey)->orWhere('is_default', true);
            })
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Walk `extends_template_id` from leaf upward, then return root → leaf order.
     *
     * @return array<int,PromptTemplate>
     */
    protected function resolveInheritanceChain(PromptTemplate $leaf): array
    {
        $maxDepth = (int) config('ai-agents.prompts.max_inheritance_depth', 3);
        $visited = [(int) $leaf->id => true];
        $chain = [$leaf];
        $current = $leaf;

        try {
            while (!empty($current->extends_template_id)) {
                if (count($chain) >= $maxDepth) {
                    throw PromptCompositionException::depthExceeded($maxDepth);
                }

                $parentId = (int) $current->extends_template_id;
                if (isset($visited[$parentId])) {
                    throw PromptCompositionException::cycleDetected($parentId);
                }
                $visited[$parentId] = true;

                $parent = PromptTemplate::query()
                    ->where('id', $parentId)
                    ->where('is_active', true)
                    ->first();

                if (!$parent) {
                    break;
                }

                array_unshift($chain, $parent);
                $current = $parent;
            }
        } catch (PromptCompositionException $e) {
            Log::warning('[Lyre PromptTemplateResolver] composition fallback', [
                'leaf_template_id' => $leaf->id,
                'error' => $e->getMessage(),
            ]);

            return [$leaf];
        }

        return $chain;
    }

    /**
     * Compose the variable map used for {{token}} substitution.
     *
     * Priority (highest first):
     *   1. Caller-supplied $variables.
     *   2. agent.metadata.template_variables.
     *   3. System defaults (assistant_name, agent_id, model).
     *   4. Each template's `variables` JSON column (parent first; child overrides parent).
     *
     * @param  array<int,PromptTemplate>  $chain
     * @param  array<string,mixed>  $callerVariables
     * @return array<string,string>
     */
    protected function buildVariableMap(Agent $agent, array $chain, array $callerVariables): array
    {
        $merged = [];

        foreach ($chain as $template) {
            $declared = is_array($template->variables ?? null) ? $template->variables : [];
            foreach ($declared as $key => $value) {
                if (is_string($key) && !is_array($value) && !is_object($value)) {
                    $merged[$key] = (string) $value;
                }
            }
        }

        $merged['assistant_name'] = (string) $agent->name;
        $merged['agent_id'] = (string) $agent->id;
        $merged['model'] = (string) $agent->model;

        $agentMetadata = is_array($agent->metadata ?? null) ? $agent->metadata : [];
        $agentVars = $agentMetadata['template_variables'] ?? [];
        if (is_array($agentVars)) {
            foreach ($agentVars as $key => $value) {
                if (is_string($key) && !is_array($value) && !is_object($value)) {
                    $merged[$key] = (string) $value;
                }
            }
        }

        foreach ($callerVariables as $key => $value) {
            if (is_string($key) && !is_array($value) && !is_object($value)) {
                $merged[$key] = (string) $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string,string>  $variables
     */
    protected function substituteVariables(string $template, array $variables): string
    {
        $rendered = $template;
        foreach ($variables as $key => $value) {
            $rendered = str_replace('{{'.$key.'}}', $value, $rendered);
        }

        return $rendered;
    }

    protected function appendContributorSections(Agent $agent, ?string $base): ?string
    {
        $separator = (string) config('ai-agents.prompts.section_separator', "\n\n");
        $sections = [];

        foreach ($this->sectionContributors as $contributor) {
            if (!$contributor instanceof PromptSectionContributor) {
                continue;
            }

            try {
                if (!$contributor->shouldApply($agent)) {
                    continue;
                }
                $rendered = $contributor->render($agent);
            } catch (\Throwable $e) {
                Log::warning('[Lyre PromptTemplateResolver] contributor failed', [
                    'contributor' => $contributor->name(),
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (is_string($rendered) && trim($rendered) !== '') {
                $sections[] = trim($rendered);
            }
        }

        if (empty($sections)) {
            return $base !== null ? trim($base) : null;
        }

        $parts = [];
        if ($base !== null && trim($base) !== '') {
            $parts[] = trim($base);
        }
        foreach ($sections as $section) {
            $parts[] = $section;
        }

        return implode($separator, $parts);
    }
}
