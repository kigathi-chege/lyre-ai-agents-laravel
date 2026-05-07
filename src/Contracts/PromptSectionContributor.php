<?php

namespace Lyre\AiAgents\Contracts;

use Lyre\AiAgents\Models\Agent;

/**
 * Hook for host applications to inject extra sections into the system prompt
 * resolved for a Lyre agent. Contributors are appended after the composed
 * template content and after variable substitution.
 *
 * Bind implementations to the container under the tag
 * {@see PromptSectionContributor::TAG} and they will be picked up by the
 * resolver in the order they are registered.
 */
interface PromptSectionContributor
{
    public const TAG = 'lyre.prompt_section_contributors';

    /**
     * Stable identifier for this contributor (used for logging / dedup).
     */
    public function name(): string;

    /**
     * Return false to skip this contributor for the given agent.
     */
    public function shouldApply(Agent $agent): bool;

    /**
     * Render the section text or null if there is nothing to add.
     */
    public function render(Agent $agent): ?string;
}
