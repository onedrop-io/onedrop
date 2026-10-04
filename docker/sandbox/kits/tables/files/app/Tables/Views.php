<?php

namespace App\Tables;

use App\Models\TableView;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A table's saved views: listing, creating the ones it starts with, changing them, and keeping their config valid.
 */
final class Views
{
    /**
     * @var list<string>
     */
    public const TYPES = ['grid', 'board', 'calendar', 'gallery', 'timeline', 'form'];

    /**
     * @var list<string>
     */
    public const TIMELINE_SCALES = ['day', 'week', 'month', 'quarter'];

    /**
     * @var list<string>
     */
    public const OPERATORS = [
        'contains', 'notContains', 'is', 'isNot', 'isEmpty', 'isNotEmpty', 'eq', 'neq', 'lt', 'lte', 'gt', 'gte',
        'isBefore', 'isAfter', 'isOnOrBefore', 'isOnOrAfter', 'isWithin', 'isAnyOf', 'isNoneOf', 'hasAnyOf',
        'hasAllOf', 'hasNoneOf', 'isMe',
    ];

    /**
     * @var list<string>
     */
    public const SUMMARIES = [
        'none', 'filled', 'empty', 'unique', 'percentFilled', 'sum', 'average', 'min', 'max', 'earliest', 'latest',
        'checked', 'unchecked',
    ];

    /**
     * @var list<string>
     */
    public const ROW_HEIGHTS = ['short', 'medium', 'tall', 'extraTall'];

    /**
     * The views the person sees: shared ones and their own. Creates the table's starting views the first time.
     *
     * @return list<array<string, mixed>>
     */
    public static function forTable(Table $table, ?User $user): array
    {
        self::ensureDefaults($table);

        return TableView::query()->visibleTo($table->key(), $user?->getKey())->get()
            ->map(fn (TableView $view) => self::data($table, $view, $user))
            ->all();
    }

    /**
     * Store the views from Table::views() when the table has none.
     */
    public static function ensureDefaults(Table $table): void
    {
        if (TableView::query()->where('table', $table->key())->exists()) {
            return;
        }

        DB::transaction(function () use ($table) {
            $views = $table->views() ?: [View::grid('Grid view')];

            foreach (array_values($views) as $position => $view) {
                $config = self::withDefaultFields($table, $view->type, self::normalize($table, $view->config(), $view->type, $view->name));

                TableView::query()->create([
                    'table' => $table->key(),
                    'name' => $view->name,
                    'type' => $view->type,
                    'config' => $config,
                    'position' => $position,
                    'public_token' => ($config['form']['public'] ?? false) ? self::newToken() : null,
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function create(Table $table, ?User $user, array $input): TableView
    {
        $personal = (bool) ($input['personal'] ?? false);
        $table->authorize($user, $personal ? 'view' : 'manageViews');
        // A table's starting views come first, even when a view is added before the table was ever opened.
        self::ensureDefaults($table);

        $config = $input['config'] ?? [];

        if (isset($input['duplicate'])) {
            $source = self::findOrFail($table, $user, (int) $input['duplicate']);
            $config = $input['config'] ?? $source->config ?? [];
            $input['type'] ??= $source->type;
        }

        $type = (string) ($input['type'] ?? 'grid');
        $config = self::withDefaultFields($table, $type, self::validated($table, $config, $type, (string) $input['name']));

        if ($config['form']['public'] ?? false) {
            $table->authorize($user, 'create');
        }

        return TableView::query()->create([
            'table' => $table->key(),
            'user_id' => $personal ? $user?->getKey() : null,
            'name' => $input['name'],
            'type' => $type,
            'config' => $config,
            'position' => (int) TableView::query()->where('table', $table->key())->max('position') + 1,
            'public_token' => ($config['form']['public'] ?? false) ? self::newToken() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function update(Table $table, ?User $user, TableView $view, array $input): TableView
    {
        self::authorizeChange($table, $user, $view);

        if (array_key_exists('name', $input)) {
            $view->name = $input['name'];
        }

        if (array_key_exists('config', $input)) {
            $old = $view->config ?? [];
            $new = $input['config'] ?? [];

            if (is_array($new['form'] ?? null) && is_array($old['form'] ?? null)) {
                $new['form'] = [...$old['form'], ...$new['form']];
            }

            $wasPublic = (bool) ($old['form']['public'] ?? false);
            $view->config = self::validated($table, [...$old, ...$new], $view->type, $view->name);

            if (! $wasPublic && ($view->config['form']['public'] ?? false)) {
                $table->authorize($user, 'create');
                $view->public_token ??= self::newToken();
            }
        }

        $view->save();

        return $view;
    }

    public static function delete(Table $table, ?User $user, TableView $view): void
    {
        self::authorizeChange($table, $user, $view);

        if (! $view->isPersonal() && TableView::query()->where('table', $table->key())->whereNull('user_id')->count() <= 1) {
            throw ValidationException::withMessages(['view' => "The table's last shared view can't be deleted."]);
        }

        $view->delete();
    }

    /**
     * Give a form view a new public link; the old one stops working.
     */
    public static function replaceFormLink(Table $table, ?User $user, TableView $view): TableView
    {
        self::authorizeChange($table, $user, $view);
        abort_unless($view->type === 'form', 404);

        $view->update(['public_token' => self::newToken()]);

        return $view;
    }

    private static function newToken(): string
    {
        return Str::random(40);
    }

    /**
     * Put the views in this order. Shared views move only for people who may manage views.
     *
     * @param  list<int>  $ids
     */
    public static function order(Table $table, ?User $user, array $ids): void
    {
        $canManage = $table->can($user, 'manageViews');
        $views = TableView::query()->visibleTo($table->key(), $user?->getKey())->get()->keyBy('id');

        DB::transaction(function () use ($ids, $views, $canManage) {
            foreach (array_values($ids) as $position => $id) {
                $view = $views->get($id);

                if ($view !== null && ($view->isPersonal() || $canManage)) {
                    $view->update(['position' => $position]);
                }
            }
        });
    }

    /**
     * A view the person can see, or a 404.
     */
    public static function findOrFail(Table $table, ?User $user, int $id): TableView
    {
        return TableView::query()->visibleTo($table->key(), $user?->getKey())->whereKey($id)->firstOrFail();
    }

    /**
     * Take a deleted field out of every view of the table.
     */
    public static function forgetField(Table $table, string $key): void
    {
        TableView::query()->where('table', $table->key())->each(function (TableView $view) use ($table, $key) {
            $config = $view->config ?? [];

            foreach (['hidden', 'order'] as $list) {
                $config[$list] = array_values(array_diff($config[$list] ?? [], [$key]));
            }

            foreach (['widths', 'summaries'] as $map) {
                unset($config[$map][$key]);
            }

            foreach (['sorts', 'groups'] as $rules) {
                $config[$rules] = array_values(array_filter($config[$rules] ?? [], fn (array $rule) => ($rule['field'] ?? null) !== $key));
            }

            $config['filters']['conditions'] = array_values(array_filter(
                $config['filters']['conditions'] ?? [],
                fn (array $condition) => ($condition['field'] ?? null) !== $key,
            ));

            if (is_array($config['form']['fields'] ?? null)) {
                $config['form']['fields'] = array_values(array_filter(
                    $config['form']['fields'],
                    fn (mixed $formField) => ! is_array($formField) || ($formField['key'] ?? null) !== $key,
                ));
            }

            foreach (['stackBy', 'dateField', 'coverField', 'endField'] as $single) {
                if (($config[$single] ?? null) === $key) {
                    $config[$single] = null;
                }
            }

            $view->update(['config' => self::normalize($table, $config, $view->type, $view->name)]);
        });
    }

    /**
     * The view as the browser gets it.
     *
     * Form views also get their page's path (formUrl) and, while public, for people who may change the view,
     * the public link's path (publicFormUrl).
     *
     * @return array{id: int, name: string, type: string, personal: bool, config: array<string, mixed>, formUrl?: string, publicFormUrl?: string|null}
     */
    public static function data(Table $table, TableView $view, ?User $user = null): array
    {
        $config = self::normalize($table, $view->config ?? [], $view->type, $view->name);
        $config['widths'] = (object) $config['widths'];
        $config['summaries'] = (object) $config['summaries'];

        $data = [
            'id' => $view->id,
            'name' => $view->name,
            'type' => $view->type,
            'personal' => $view->isPersonal(),
            'config' => $config,
        ];

        if ($view->type === 'form') {
            $mayShare = $view->isPersonal()
                ? $user !== null && (int) $view->user_id === (int) $user->getKey()
                : $table->can($user, 'manageViews');

            $data['formUrl'] = route('tables.forms.show', ['table' => $table->key(), 'view' => $view->id], absolute: false);
            $data['publicFormUrl'] = $mayShare && $config['form']['public'] && $view->public_token !== null
                ? route('tables.public-forms.show', ['token' => $view->public_token], absolute: false)
                : null;
        }

        return $data;
    }

    /**
     * The config, checked, with every key filled in.
     *
     * @return array<string, mixed>
     */
    public static function validated(Table $table, mixed $config, string $type = 'grid', string $name = ''): array
    {
        $validator = Validator::make(['config' => $config], [
            'config' => ['array'],
            'config.filters' => ['sometimes', 'array'],
            'config.filters.conjunction' => ['sometimes', Rule::in(['and', 'or'])],
            'config.filters.conditions' => ['sometimes', 'array'],
            'config.filters.conditions.*' => ['array'],
            'config.filters.conditions.*.field' => ['required', 'string'],
            'config.filters.conditions.*.operator' => ['required', Rule::in(self::OPERATORS)],
            'config.sorts' => ['sometimes', 'array'],
            'config.sorts.*.field' => ['required', 'string'],
            'config.sorts.*.direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'config.groups' => ['sometimes', 'array', 'max:3'],
            'config.groups.*.field' => ['required', 'string'],
            'config.groups.*.direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'config.hidden' => ['sometimes', 'array'],
            'config.hidden.*' => ['string'],
            'config.order' => ['sometimes', 'array'],
            'config.order.*' => ['string'],
            'config.widths' => ['sometimes', 'array'],
            'config.widths.*' => ['integer', 'min:20', 'max:2000'],
            'config.rowHeight' => ['sometimes', Rule::in(self::ROW_HEIGHTS)],
            'config.summaries' => ['sometimes', 'array'],
            'config.summaries.*' => [Rule::in(self::SUMMARIES)],
            'config.stackBy' => ['sometimes', 'nullable', 'string'],
            'config.dateField' => ['sometimes', 'nullable', 'string'],
            'config.coverField' => ['sometimes', 'nullable', 'string'],
            'config.endField' => ['sometimes', 'nullable', 'string'],
            'config.timelineScale' => ['sometimes', Rule::in(self::TIMELINE_SCALES)],
            'config.form' => ['sometimes', 'nullable', 'array'],
            'config.form.title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'config.form.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'config.form.fields' => ['sometimes', 'array'],
            'config.form.fields.*' => ['array'],
            'config.form.fields.*.key' => ['required', 'string'],
            'config.form.fields.*.required' => ['sometimes', 'boolean'],
            'config.form.fields.*.help' => ['sometimes', 'nullable', 'string', 'max:500'],
            'config.form.submitLabel' => ['sometimes', 'nullable', 'string', 'max:100'],
            'config.form.thankYou' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'config.form.allowAnother' => ['sometimes', 'boolean'],
            'config.form.public' => ['sometimes', 'boolean'],
        ], [
            'config.groups.max' => 'Views can be grouped by up to three fields.',
        ]);

        $validator->validate();

        return self::normalize($table, $config, $type, $name);
    }

    /**
     * Fill in every ViewConfig key, drop unknown keys and fields that don't exist.
     * $type and $name (the view's) fill in a form view's form; other views' form is null.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function normalize(Table $table, array $config, string $type = 'grid', string $name = ''): array
    {
        $exists = fn (mixed $key) => is_string($key) && $table->field($key) !== null;
        $rules = fn (mixed $rules) => array_values(array_map(
            fn (array $rule) => ['field' => $rule['field'], 'direction' => ($rule['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc'],
            array_filter(is_array($rules) ? $rules : [], fn (mixed $rule) => is_array($rule) && $exists($rule['field'] ?? null)),
        ));
        $map = fn (mixed $values) => array_filter(is_array($values) ? $values : [], fn (mixed $value, mixed $key) => $exists($key), ARRAY_FILTER_USE_BOTH);
        $single = fn (string $key) => $exists($config[$key] ?? null) ? $config[$key] : null;

        $conditions = [];

        foreach ($config['filters']['conditions'] ?? [] as $condition) {
            if (! is_array($condition) || ! $exists($condition['field'] ?? null) || ! in_array($condition['operator'] ?? null, self::OPERATORS, true)) {
                continue;
            }

            $conditions[] = array_filter([
                'id' => (string) ($condition['id'] ?? Str::random(8)),
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'value' => $condition['value'] ?? null,
            ], fn (mixed $value, string $key) => $key !== 'value' || $value !== null, ARRAY_FILTER_USE_BOTH);
        }

        return [
            'filters' => [
                'conjunction' => ($config['filters']['conjunction'] ?? 'and') === 'or' ? 'or' : 'and',
                'conditions' => $conditions,
            ],
            'sorts' => $rules($config['sorts'] ?? []),
            'groups' => array_slice($rules($config['groups'] ?? []), 0, 3),
            'hidden' => array_values(array_unique(array_filter($config['hidden'] ?? [], $exists))),
            'order' => array_values(array_unique(array_filter($config['order'] ?? [], $exists))),
            'widths' => array_map('intval', $map($config['widths'] ?? [])),
            'rowHeight' => in_array($config['rowHeight'] ?? null, self::ROW_HEIGHTS, true) ? $config['rowHeight'] : 'short',
            'summaries' => array_filter($map($config['summaries'] ?? []), fn (mixed $function) => in_array($function, self::SUMMARIES, true)),
            'stackBy' => $single('stackBy'),
            'dateField' => $single('dateField'),
            'coverField' => $single('coverField'),
            'endField' => self::isDateField($table, $config['endField'] ?? null) ? $config['endField'] : null,
            'timelineScale' => in_array($config['timelineScale'] ?? null, self::TIMELINE_SCALES, true) ? $config['timelineScale'] : 'week',
            'form' => $type === 'form' ? self::form($table, is_array($config['form'] ?? null) ? $config['form'] : [], $name) : null,
        ];
    }

    /**
     * A form view's FormConfig, filled in. Only fields people can enter values in are kept, once each;
     * without a list of fields the form asks for all of them, the primary one required.
     *
     * @param  array<string, mixed>  $form
     * @return array{title: string, description: string, fields: list<array{key: string, required: bool, help: string}>, submitLabel: string, thankYou: string, allowAnother: bool, public: bool}
     */
    private static function form(Table $table, array $form, string $name): array
    {
        $text = fn (string $key, string $default) => is_string($form[$key] ?? null) && trim($form[$key]) !== '' ? trim($form[$key]) : $default;
        $primary = $table->primaryField()?->key;

        if (is_array($form['fields'] ?? null)) {
            $fields = [];

            foreach ($form['fields'] as $formField) {
                $key = is_array($formField) ? ($formField['key'] ?? null) : null;

                if (! is_string($key) || isset($fields[$key]) || ! self::isFormField($table, $key)) {
                    continue;
                }

                $fields[$key] = [
                    'key' => $key,
                    'required' => (bool) ($formField['required'] ?? false),
                    'help' => is_string($formField['help'] ?? null) ? trim($formField['help']) : '',
                ];
            }

            $fields = array_values($fields);
        } else {
            $fields = array_values(array_map(
                fn (Field $field) => ['key' => $field->key, 'required' => $field->key === $primary, 'help' => ''],
                array_filter($table->allFields(), fn (Field $field) => self::isFormField($table, $field->key)),
            ));
        }

        return [
            'title' => $text('title', $name),
            'description' => is_string($form['description'] ?? null) ? trim($form['description']) : '',
            'fields' => $fields,
            'submitLabel' => $text('submitLabel', 'Send'),
            'thankYou' => $text('thankYou', 'Thanks! Your response was sent.'),
            'allowAnother' => (bool) ($form['allowAnother'] ?? true),
            'public' => (bool) ($form['public'] ?? false),
        ];
    }

    /**
     * Whether a form can ask for the field: people can enter its values (not worked out or locked).
     */
    public static function isFormField(Table $table, string $key): bool
    {
        $field = $table->field($key);

        return $field !== null && $field->readOnlyReason() === null;
    }

    /**
     * Whether the field holds dates: a date, created or modified time, or a formula or rollup shown as a date.
     */
    private static function isDateField(Table $table, mixed $key): bool
    {
        $field = is_string($key) ? $table->field($key) : null;

        return $field !== null && (
            in_array($field->type, ['date', 'createdAt', 'updatedAt'], true)
            || (in_array($field->type, ['formula', 'rollup'], true) && $field->option('format') === 'date')
        );
    }

    /**
     * Boards stack by the first select field and calendars and timelines use the first date field, unless they say.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withDefaultFields(Table $table, string $type, array $config): array
    {
        $first = function (array $types) use ($table): ?string {
            foreach ($table->allFields() as $field) {
                if (in_array($field->type, $types, true)) {
                    return $field->key;
                }
            }

            return null;
        };

        if ($type === 'board' && $config['stackBy'] === null) {
            $config['stackBy'] = $first(['select']);
        }

        if (in_array($type, ['calendar', 'timeline'], true) && $config['dateField'] === null) {
            $config['dateField'] = $first(['date']);
        }

        return $config;
    }

    private static function authorizeChange(Table $table, ?User $user, TableView $view): void
    {
        if ($view->isPersonal()) {
            abort_unless($user !== null && (int) $view->user_id === (int) $user->getKey(), 404);

            return;
        }

        $table->authorize($user, 'manageViews');
    }
}
