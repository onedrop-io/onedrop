<?php

namespace Tests\Feature\Tables\Fixtures;

use App\Models\User;
use App\Tables\Field;
use App\Tables\Table;
use App\Tables\View;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DealsTable extends Table
{
    /**
     * Abilities refused, for testing permissions.
     *
     * @var list<string>
     */
    public static array $denied = [];

    /**
     * Narrows the records people see, for testing query().
     */
    public static ?Closure $scope = null;

    /**
     * Records saved, for testing the saving() hook.
     *
     * @var list<string>
     */
    public static array $saved = [];

    /**
     * The views the table starts with, when a test sets them.
     *
     * @var list<View>|null
     */
    public static ?array $startViews = null;

    protected string $model = Deal::class;

    public function name(): string
    {
        return 'Deals';
    }

    public function fields(): array
    {
        return [
            Field::text('name', 'Name')->primary(),
            Field::link('company_id', 'Company', 'companies'),
            Field::link('contacts', 'Contacts', 'contacts')->relation('contacts'),
            Field::currency('value', 'Value')->symbol('$')->precision(0),
            Field::percent('probability', 'Probability'),
            Field::select('stage', 'Stage', ['Lead' => 'gray', 'Qualified' => 'blue', 'Won' => 'green', 'Lost' => 'red']),
            Field::multiSelect('tags', 'Tags', ['Hot' => 'red', 'Renewal' => 'blue']),
            Field::date('close_date', 'Close date'),
            Field::user('owner_id', 'Owner'),
            Field::checkbox('signed', 'Signed'),
            Field::rating('fit', 'Fit')->max(5),
            Field::attachments('files', 'Files'),
            Field::longText('notes', 'Notes'),
            Field::url('website', 'Website'),
            Field::email('email', 'Email'),
            Field::phone('phone', 'Phone'),
            Field::number('seats', 'Seats')->precision(0),
            Field::text('code', 'Code')->readOnly(),
            Field::formula('weighted', 'Weighted value', '{value} * {probability}')->format('currency')->symbol('$'),
            Field::lookup('company_city', 'Company city', link: 'company_id', field: 'city'),
            Field::createdAt('created_at', 'Created'),
            Field::updatedAt('updated_at', 'Updated'),
        ];
    }

    public function views(): array
    {
        return self::$startViews ?? [
            View::grid('All deals')->sort('close_date')->hide('notes'),
            View::board('Pipeline')->cover('files'),
            View::calendar('Close dates'),
        ];
    }

    public function can(?User $user, string $ability, ?Model $record = null): bool
    {
        return $user !== null && ! in_array($ability, self::$denied, true);
    }

    public function query(?User $user): Builder
    {
        $query = parent::query($user);

        return self::$scope === null ? $query : (self::$scope)($query, $user);
    }

    public function defaults(?User $user): array
    {
        return ['stage' => 'Lead', 'owner_id' => $user?->id];
    }

    public function saving(Model $record, ?User $user): void
    {
        self::$saved[] = (string) $record->getAttribute('name');

        if ($record->getAttribute('name') === 'shout') {
            $record->setAttribute('name', 'SHOUT');
        }
    }

    public static function reset(): void
    {
        self::$denied = [];
        self::$scope = null;
        self::$saved = [];
        self::$startViews = null;
    }
}
