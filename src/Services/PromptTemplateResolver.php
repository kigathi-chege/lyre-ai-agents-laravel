<?php

namespace Lyre\AiAgents\Services;

use Lyre\AiAgents\Models\Agent;
use Lyre\AiAgents\Models\PromptTemplate;

class PromptTemplateResolver
{
    public function resolveInstructionsForAgent(Agent $agent): ?string
    {
        $agentInstructions = trim((string) ($agent->instructions ?? ''));
        if ($agentInstructions !== '') {
            return $agentInstructions;
        }

        $template = $this->resolveTemplateForAgent($agent);
        if (!$template) {
            return null;
        }

        return $this->renderTemplate((string) $template->content, [
            'assistant_name' => (string) $agent->name,
        ]);
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

    protected function renderTemplate(string $template, array $variables): string
    {
        $rendered = $template;
        foreach ($variables as $key => $value) {
            $rendered = str_replace('{{'.$key.'}}', (string) $value, $rendered);
        }

        return trim($rendered);
    }
}

