<?php

namespace App\Sandbox\Agents;

use App\Enums\AgentProvider;
use App\Enums\CredentialType;
use App\Models\AgentConnection;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Jev, TypeSafe's decision model on OpenRouter: it takes some state and typed questions, and answers each with a
 * probability instead of text. https://openrouter.ai/docs/guides/community/jev
 *
 * On a user's own Ollama server with Nimble pulled, the same questions go to Nimble there instead (AI-007).
 *
 * Questions are yes-or-no ("noul": how likely it holds), a choice (which option, with each one's probability) or a
 * score (where it falls on an ordered scale). One request can ask several; they're answered in parallel.
 */
class Jev
{
    public const URL = 'https://openrouter.ai/api/alpha/decisions';

    public function __construct(protected OllamaServer $ollamaServer) {}

    /**
     * Where to ask about a project: Nimble on the owner's own Ollama server when it has it (AI-007), else the owner's
     * OpenRouter key, else the platform's, else nowhere.
     */
    public function endpointFor(Project $project): ?JevEndpoint
    {
        $connections = $project->user->agentConnections()->get();
        $server = $connections->first(fn (AgentConnection $connection) => $connection->provider === AgentProvider::Ollama && OllamaServer::isServer($connection));

        if ($server && ($model = $this->ollamaServer->nimbleModel($server)) !== null) {
            return JevEndpoint::nimble($server, $model);
        }

        $openRouter = $connections->first(fn (AgentConnection $connection) => $connection->provider === AgentProvider::OpenRouter && $connection->credential_type === CredentialType::ApiKey);

        return $openRouter?->credential ? JevEndpoint::openRouter($openRouter->credential) : $this->platformEndpoint();
    }

    /**
     * The platform's own OpenRouter key, for decisions that aren't about one user's project (or null when there's none).
     */
    public function platformEndpoint(): ?JevEndpoint
    {
        $key = config('services.openrouter.key');

        return $key ? JevEndpoint::openRouter($key) : null;
    }

    /**
     * The probability that each yes-or-no question holds, by its key.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, array{instructions: string, true: string, false: string}>  $questions
     * @return array<string, float>
     *
     * @throws ConnectionException|RequestException|RuntimeException
     */
    public function yesOrNo(JevEndpoint $endpoint, array $state, array $questions, int $timeout = 30): array
    {
        $answers = $this->decide($endpoint, $state, array_map(fn (array $question) => self::yesOrNoQuestion(
            $question['instructions'], $question['true'], $question['false'],
        ), $questions), $timeout);

        return array_map(fn (array $answer) => $answer['noul'], $answers);
    }

    /**
     * Ask several questions of any type at once (built with yesOrNoQuestion(), choiceQuestion() and scoreQuestion()),
     * and get each one's answer by its key:
     * - yes-or-no: `['type' => 'noul', 'noul' => 0.96]`
     * - choice: `['type' => 'choice', 'choice' => 'payments', 'confidence' => 0.67, 'probabilities' => ['payments' => 0.78, …]]`
     * - score: `['type' => 'score', 'score' => 1.99, 'confidence' => 0.99, 'probabilities' => ['0' => 0, …]]`, the score
     *   being the probability-weighted position on the scale (0 is its first step).
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, array{type: string, instructions: string, criteria: array<int|string, string>}>  $questions
     * @return array<string, array<string, mixed>>
     *
     * @throws ConnectionException|RequestException|RuntimeException when Jev can't be reached or leaves a question unanswered
     */
    public function decide(JevEndpoint $endpoint, array $state, array $questions, int $timeout = 30): array
    {
        $body = ['model' => $endpoint->model, 'state' => $state, 'questions' => $questions];

        $answers = ($endpoint->ollamaServer
            ? $this->ollamaServer->decide($endpoint->ollamaServer, $body, $timeout)
            : Http::withToken((string) $endpoint->openRouterKey)->timeout($timeout)->post(self::URL, $body))
            ->throw()
            ->json('answers');

        return collect($questions)
            ->map(fn (array $question, string $id) => self::answered($question['type'], $answers[$id] ?? null)
                ? $answers[$id]
                : throw new RuntimeException("Jev didn't answer \"{$id}\"."))
            ->all();
    }

    /**
     * @return array{type: 'noul', instructions: string, criteria: array{true: string, false: string}}
     */
    public static function yesOrNoQuestion(string $instructions, string $true, string $false): array
    {
        return ['type' => 'noul', 'instructions' => $instructions, 'criteria' => ['true' => $true, 'false' => $false]];
    }

    /**
     * @param  array<string, string>  $options  each option's key and what it means
     * @return array{type: 'choice', instructions: string, criteria: array<string, string>}
     */
    public static function choiceQuestion(string $instructions, array $options): array
    {
        return ['type' => 'choice', 'instructions' => $instructions, 'criteria' => $options];
    }

    /**
     * @param  list<string>  $scale  the steps, lowest first
     * @return array{type: 'score', instructions: string, criteria: list<string>}
     */
    public static function scoreQuestion(string $instructions, array $scale): array
    {
        return ['type' => 'score', 'instructions' => $instructions, 'criteria' => $scale];
    }

    /**
     * Whether Jev's answer has what a question of that type needs.
     */
    protected static function answered(string $type, mixed $answer): bool
    {
        return is_array($answer) && match ($type) {
            'noul' => is_numeric($answer['noul'] ?? null),
            'choice' => is_string($answer['choice'] ?? null) && is_array($answer['probabilities'] ?? null),
            'score' => is_numeric($answer['score'] ?? null),
            default => false,
        };
    }
}
