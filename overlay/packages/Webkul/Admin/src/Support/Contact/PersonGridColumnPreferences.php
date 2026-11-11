<?php

namespace Webkul\Admin\Support\Contact;

use Illuminate\Support\Facades\DB;
use Webkul\Contact\Models\Person;

final class PersonGridColumnPreferences
{
    public const CONFIG_CODE = 'txn.admin.contacts.persons.visible_columns';

    public const BASE_COLUMNS = [
        'id',
        'person_name',
        'emails',
        'contact_numbers',
        'job_title',
        'sales_owner',
        'organization',
    ];

    public const DEFAULT_COLUMNS = [
        'id',
        'person_name',
        'emails',
        'contact_numbers',
    ];

    public static function customAttributes(): array
    {
        return DB::table('attributes')
            ->where('entity_type', 'persons')
            ->where('is_user_defined', true)
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'type'])
            ->filter(fn ($attribute) => isset(Person::$attributeTypeFields[$attribute->type]))
            ->values()
            ->all();
    }

    public static function attributeColumn(int $attributeId): string
    {
        return 'attribute_'.$attributeId;
    }

    public static function availableColumns(): array
    {
        return array_merge(
            self::BASE_COLUMNS,
            array_map(
                fn ($attribute) => self::attributeColumn((int) $attribute->id),
                self::customAttributes()
            )
        );
    }

    public static function visibleColumns(): array
    {
        $value = DB::table('core_config')
            ->where('code', self::CONFIG_CODE)
            ->value('value');

        if (! is_string($value) || $value === '') {
            return self::DEFAULT_COLUMNS;
        }

        $columns = json_decode($value, true);

        if (! is_array($columns)) {
            return self::DEFAULT_COLUMNS;
        }

        $columns = array_values(array_unique(array_filter(
            $columns,
            fn ($column) => is_string($column) && in_array($column, self::availableColumns(), true)
        )));

        return $columns ?: self::DEFAULT_COLUMNS;
    }

    public static function save(array $columns): void
    {
        $now = now();
        $existing = DB::table('core_config')
            ->where('code', self::CONFIG_CODE)
            ->first(['id']);

        if ($existing) {
            DB::table('core_config')
                ->where('id', $existing->id)
                ->update([
                    'value' => json_encode($columns, JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ]);

            return;
        }

        DB::table('core_config')->insert([
            'code' => self::CONFIG_CODE,
            'value' => json_encode($columns, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
