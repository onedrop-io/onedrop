<?php

namespace App\Actions;

use App\Enums\AgentHarness;
use App\Enums\AgentProvider;
use App\Enums\GitSyncStatus;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Enums\SandboxStatus;
use App\Jobs\ApplyRegistryTemplate;
use App\Jobs\CreateSandbox;
use App\Jobs\ImportRepository;
use App\Jobs\RunAgentTask;
use App\Jobs\UpdateProjectIcon;
use App\Models\Attachment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Sandbox\Agents\ModelCatalog;
use App\Sandbox\GitException;
use App\Sandbox\ProjectIcons;
use App\Sandbox\RepositoryImport;
use App\Sandbox\Templates\TemplateCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;

class StartProject
{
    public function __construct(protected ModelCatalog $catalog, protected RepositoryImport $import, protected TemplateCatalog $templates) {}

    /**
     * Create a project in the organization from a description (or a template's, PRJ-012), or from a repository
     * (PRJ-009), and start the agent on it, from the web or the desktop app.
     *
     * @param  string|null  $template  A template's value from the catalog.
     * @param  array{agent_harness: AgentHarness, agent_provider: AgentProvider, agent_model: string, agent_variant: string|null}|null  $agent  The agent the user chose, or null for their default.
     * @param  list<UploadedFile>  $attachments
     * @param  string|null  $repository  A repository to import, as the user gave it.
     *
     * @throws ValidationException
     */
    public function handle(User $user, Organization $organization, string $prompt, ?string $template = null, ?array $agent = null, array $attachments = [], ?string $repository = null): Project
    {
        $template = $template !== null && $template !== '' ? $this->templates->find($template) : null;
        $imported = null;

        if ($repository !== null && $repository !== '') {
            try {
                $imported = $this->import->resolve($user, $repository);
            } catch (GitException $e) {
                throw ValidationException::withMessages(['repository' => $e->getMessage()]);
            }

            $template = null;
            $prompt = trim($prompt) !== '' ? $prompt : __('I imported this app from its repository. Get it running in the preview.');
        }

        $default = $this->catalog->newProjectAgent($user);

        if ($agent) {
            $user->rememberModel($agent['agent_provider'], $agent['agent_model']);

            // Only a choice that differs from the default sticks, so an untouched picker keeps following it.
            if ($agent != $default) {
                $user->preferAgent($agent);
            }
        }

        $agent ??= $default ?? [];

        $project = $user->projects()->create([
            'organization_id' => $organization->id,
            'name' => $imported['name'] ?? $template['label'] ?? Project::nameFromPrompt($prompt),
            'prompt' => $prompt,
            ...$agent,
            ...($imported ? [
                'git_remote_url' => $imported['url'],
                'github_installation_id' => $imported['installation_id'],
                'git_sync_status' => GitSyncStatus::Pulling,
            ] : []),
        ]);

        // Show "Thinking…" right away: the page only polls for updates while the agent is working,
        // and the queued run may take a moment to start.
        $project->update(['status' => ProjectStatus::Working]);

        // A registry template's setup goes to the agent only, beside what the user wrote (PRJ-012).
        $registry = $template ? $this->templates->registryFor($template['value']) : null;

        $message = $project->messages()->create([
            'role' => MessageRole::User,
            'content' => $prompt,
            ...($registry ? ['meta' => ['template' => $template['value'], 'agent_context' => $registry->agentContext($template)]] : []),
        ]);

        foreach ($attachments as $file) {
            Attachment::store($message, $file);
        }

        $project->sandbox()->create([
            'provider' => config('sandbox.provider'),
            'status' => SandboxStatus::Creating,
        ]);

        Bus::chain(array_values(array_filter([
            new CreateSandbox($project),
            $imported ? new ImportRepository($project, $message, $imported['branch']) : null,
            $registry ? new ApplyRegistryTemplate($project, $message, $template['value']) : null,
            new RunAgentTask($project, $message),
            // Beside the first run, not after it: the icon is drawn from the prompt (or the cloned repository's
            // own is picked up) while the agent builds, and checked again when the run ends (PRJ-007).
            new UpdateProjectIcon($project),
        ])))->dispatch();

        ProjectIcons::markDrawing($project);

        return $project;
    }
}
