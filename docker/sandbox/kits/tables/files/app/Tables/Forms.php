<?php

namespace App\Tables;

use App\Models\TableView;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Form views (TABLE-009): the form page's props (FormPageData), and adding the records sent through them,
 * signed in or through a public link.
 */
final class Forms
{
    /**
     * Field types a public form leaves out: their pickers would list the app's people and records.
     *
     * @var list<string>
     */
    public const PRIVATE_TYPES = ['user', 'link'];

    /**
     * A form view the person can see and add records with, or a 404 (403 when they may not create records).
     */
    public static function findOrFail(Table $table, ?User $user, int $id): TableView
    {
        $view = Views::findOrFail($table, $user, $id);
        abort_unless($view->type === 'form', 404);
        $table->authorize($user, 'create');

        return $view;
    }

    /**
     * The table and form view of a public link, or a 404 when the link is unknown, replaced or turned off.
     *
     * @return array{0: Table, 1: TableView}
     */
    public static function findPublicOrFail(string $token): array
    {
        $view = TableView::query()->where('public_token', $token)->where('type', 'form')->first();
        $table = $view === null ? null : Tables::find($view->table);

        abort_if($view === null || $table === null || ! self::config($table, $view)['public'], 404);

        if ($view->isPersonal()) {
            $owner = $view->user()->first();
            abort_unless($owner instanceof User && $table->can($owner, 'create'), 404);
        }

        return [$table, $view];
    }

    /**
     * The form page's props (FormPageData).
     *
     * @return array<string, mixed>
     */
    public static function page(Table $table, TableView $view, ?User $user, bool $public): array
    {
        $config = self::config($table, $view);
        $data = $public ? null : new TableData($table, $user);

        return [
            'tableName' => $table->name(),
            'title' => $config['title'],
            'description' => $config['description'],
            'fields' => array_map(
                fn (array $formField) => [...$table->fieldData($formField['field'], true), 'required' => $formField['required'], 'help' => $formField['help']],
                self::fields($table, $view, $public),
            ),
            'submitLabel' => $config['submitLabel'],
            'thankYou' => $config['thankYou'],
            'allowAnother' => $config['allowAnother'],
            'submitUrl' => $public
                ? route('tables.public-forms.submit', ['token' => $view->public_token], absolute: false)
                : route('tables.forms.submit', ['table' => $table->key(), 'view' => $view->id], absolute: false),
            'uploadUrl' => $public
                ? route('tables.public-forms.attachments', ['token' => $view->public_token], absolute: false)
                : route('tables.attachments.store', ['table' => $table->key()], absolute: false),
            'users' => $data?->users() ?? [],
            'linked' => (object) ($data?->linked() ?? []),
            'public' => $public,
        ];
    }

    /**
     * Add the record sent through the form. Values of fields the form doesn't ask for are ignored.
     *
     * @param  array<string, mixed>  $input  values by field key
     *
     * @throws ValidationException keyed by field key, when a required field is empty or a value doesn't fit
     */
    public static function submit(Table $table, TableView $view, ?User $user, array $input, bool $public): void
    {
        $computation = new Computation($user);
        $computation->register($table);
        $normalizer = new Normalizer($table, $computation, false);
        $values = [];
        $errors = [];

        foreach (self::fields($table, $view, $public) as ['field' => $field, 'required' => $required]) {
            $value = $input[$field->key] ?? null;

            try {
                $normalized = $normalizer->normalize($field, $value);
            } catch (InvalidValue $invalid) {
                $errors[$field->key][] = $invalid->getMessage();

                continue;
            }

            if ($public && $field->type === 'attachment' && ! self::fromPublicForm($table, $normalized)) {
                $errors[$field->key][] = "{$field->name} has a file that wasn't uploaded here.";

                continue;
            }

            if ($required && in_array($normalized, [null, '', [], false], true)) {
                $errors[$field->key][] = "{$field->name} is required.";

                continue;
            }

            if (array_key_exists($field->key, $input)) {
                $values[$field->key] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            (new RecordChanges($table, $user))
                ->throughForm($view->name, $public)
                ->apply(['creates' => [['values' => $values]]]);
        } catch (ValidationException $exception) {
            $byField = [];

            foreach ($exception->errors() as $key => $messages) {
                $byField[preg_replace('/^new\.0\./', '', (string) $key)] = $messages;
            }

            throw ValidationException::withMessages($byField);
        }
    }

    /**
     * Store a file sent through a public form, for one of its attachment fields.
     *
     * @return array{key: string, name: string, size: int, type: string, url: string}
     */
    public static function upload(Table $table, TableView $view, UploadedFile $file): array
    {
        $takesFiles = array_filter(self::fields($table, $view, true), fn (array $formField) => $formField['field']->type === 'attachment');

        if ($takesFiles === []) {
            throw ValidationException::withMessages(['file' => "This form doesn't take files."]);
        }

        return Attachments::upload($table, $file, Attachments::FORM_UPLOADS);
    }

    /**
     * The form's FormConfig, filled in.
     *
     * @return array{title: string, description: string, fields: list<array{key: string, required: bool, help: string}>, submitLabel: string, thankYou: string, allowAnother: bool, public: bool}
     */
    public static function config(Table $table, TableView $view): array
    {
        return Views::normalize($table, $view->config ?? [], 'form', $view->name)['form'];
    }

    /**
     * The fields the form asks for, in order; without person and link fields when it's public.
     *
     * @return list<array{field: Field, required: bool, help: string}>
     */
    private static function fields(Table $table, TableView $view, bool $public): array
    {
        $fields = [];

        foreach (self::config($table, $view)['fields'] as $formField) {
            $field = $table->field($formField['key']);

            if ($field !== null && ! ($public && in_array($field->type, self::PRIVATE_TYPES, true))) {
                $fields[] = ['field' => $field, 'required' => $formField['required'], 'help' => $formField['help']];
            }
        }

        return $fields;
    }

    /**
     * Whether every file was uploaded through a public form, so its visitors can't attach the table's other files.
     *
     * @param  list<array{key: string}>  $attachments
     */
    private static function fromPublicForm(Table $table, array $attachments): bool
    {
        foreach ($attachments as $attachment) {
            if (! str_starts_with($attachment['key'], $table->key().'/'.Attachments::FORM_UPLOADS.'/')) {
                return false;
            }
        }

        return true;
    }
}
