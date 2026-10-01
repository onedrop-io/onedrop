<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Models\Message;
use App\Models\Project;
use App\Sandbox\Agents\AgentQueue;
use App\Sandbox\SandboxException;
use App\Sandbox\Templates\TemplateCatalog;
use App\Sandbox\Templates\TemplateException;
use App\Sandbox\WorkspaceFiles;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ApplyRegistryTemplate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Project $project, public Message $message, public string $template) {}

    /**
     * Write a registry template's files (PRJ-012) into the new project's sandbox before the agent's first run.
     * If it can't, the chain stops there: the agent never starts on an empty project.
     */
    public function handle(TemplateCatalog $catalog, WorkspaceFiles $files): void
    {
        $registry = $catalog->registryFor($this->template);
        $sandbox = ($this->project->fresh() ?? $this->project)->sandbox;

        try {
            if (! $registry || ! $sandbox) {
                throw new TemplateException(__('That template is no longer available.'));
            }

            $written = $registry->files(Str::after($this->template, '/'));

            foreach ($written as $path => $content) {
                if (str_contains($path, '/')) {
                    $files->create($sandbox, dirname($path), 'dir');
                }

                $files->write($sandbox, $path, $content);
            }
        } catch (TemplateException|SandboxException $e) {
            $this->fail($e);
        }
    }

    /**
     * Say why in the chat, and leave the project idle so the user can try something else.
     */
    public function failed(?Throwable $exception): void
    {
        $reason = $exception instanceof TemplateException || $exception instanceof SandboxException
            ? Str::limit($exception->getMessage(), 500)
            : __('Something went wrong. Try again.');

        $conversation = $this->message->conversation();
        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => __('Couldn\'t start from the template: :reason', ['reason' => $reason]),
        ]);
        app(AgentQueue::class)->finished($conversation);
    }
}
