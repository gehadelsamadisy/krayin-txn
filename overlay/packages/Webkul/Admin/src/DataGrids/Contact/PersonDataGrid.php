<?php

namespace Webkul\Admin\DataGrids\Contact;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Support\Contact\PersonGridColumnPreferences;
use Webkul\Contact\Models\Person;
use Webkul\Contact\Repositories\OrganizationRepository;
use Webkul\DataGrid\DataGrid;

class PersonDataGrid extends DataGrid
{
    /**
     * Create a new class instance.
     *
     * @return void
     */
    public function __construct(protected OrganizationRepository $organizationRepository) {}

    /**
     * Prepare query builder.
     */
    public function prepareQueryBuilder(): Builder
    {
        $queryBuilder = DB::table('persons')
            ->addSelect(
                'persons.id',
                'persons.name as person_name',
                'persons.emails',
                'persons.contact_numbers',
                'persons.job_title',
                'organizations.name as organization',
                'organizations.id as organization_id',
                'users.name as sales_owner'
            )
            ->leftJoin('organizations', 'persons.organization_id', '=', 'organizations.id');

        $queryBuilder->leftJoin('users', 'persons.user_id', '=', 'users.id');

        foreach (PersonGridColumnPreferences::customAttributes() as $attribute) {
            $attributeId = (int) $attribute->id;
            $valueField = Person::$attributeTypeFields[$attribute->type];
            $valueAlias = 'person_attribute_values_'.$attributeId;
            $columnIndex = PersonGridColumnPreferences::attributeColumn($attributeId);

            $queryBuilder->leftJoin('attribute_values as '.$valueAlias, function ($join) use ($attributeId, $valueAlias) {
                $join->on('persons.id', '=', $valueAlias.'.entity_id')
                    ->where($valueAlias.'.entity_type', '=', 'persons')
                    ->where($valueAlias.'.attribute_id', '=', $attributeId);
            });

            if ($attribute->type === 'select') {
                $optionAlias = 'person_attribute_options_'.$attributeId;

                $queryBuilder->leftJoin('attribute_options as '.$optionAlias, function ($join) use ($attributeId, $optionAlias, $valueAlias) {
                    $join->on($optionAlias.'.id', '=', $valueAlias.'.integer_value')
                        ->where($optionAlias.'.attribute_id', '=', $attributeId);
                });

                $queryBuilder->addSelect($optionAlias.'.name as '.$columnIndex);

                continue;
            }

            $queryBuilder->addSelect($valueAlias.'.'.$valueField.' as '.$columnIndex);
        }

        if ($userIds = bouncer()->getAuthorizedUserIds()) {
            $queryBuilder->whereIn('persons.user_id', $userIds);
        }

        $this->addFilter('id', 'persons.id');
        $this->addFilter('person_name', 'persons.name');
        $this->addFilter('job_title', 'persons.job_title');
        $this->addFilter('sales_owner', 'users.name');
        $this->addFilter('organization', 'organizations.name');

        return $queryBuilder;
    }

    /**
     * Add columns.
     */
    public function prepareColumns(): void
    {
        $visibleColumns = PersonGridColumnPreferences::visibleColumns();

        $this->addColumn([
            'index' => 'id',
            'label' => trans('admin::app.contacts.persons.index.datagrid.id'),
            'type' => 'integer',
            'filterable' => true,
            'sortable' => true,
            'searchable' => true,
            'visibility' => in_array('id', $visibleColumns, true),
        ]);

        $this->addColumn([
            'index' => 'person_name',
            'label' => trans('admin::app.contacts.persons.index.datagrid.name'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
            'searchable' => true,
            'visibility' => in_array('person_name', $visibleColumns, true),
        ]);

        $this->addColumn([
            'index' => 'emails',
            'label' => trans('admin::app.contacts.persons.index.datagrid.emails'),
            'type' => 'string',
            'sortable' => false,
            'filterable' => true,
            'searchable' => true,
            'closure' => fn ($row) => collect(json_decode($row->emails, true) ?? [])->pluck('value')->join(', '),
            'visibility' => in_array('emails', $visibleColumns, true),
        ]);

        $this->addColumn([
            'index' => 'contact_numbers',
            'label' => trans('admin::app.contacts.persons.index.datagrid.contact-numbers'),
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
            'searchable' => true,
            'closure' => fn ($row) => collect(json_decode($row->contact_numbers, true) ?? [])->pluck('value')->join(', '),
            'visibility' => in_array('contact_numbers', $visibleColumns, true),
        ]);

        $this->addColumn([
            'index' => 'job_title',
            'label' => 'Job Title',
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
            'searchable' => true,
            'visibility' => in_array('job_title', $visibleColumns, true),
        ]);

        $this->addColumn([
            'index' => 'sales_owner',
            'label' => 'Sales Owner',
            'type' => 'string',
            'sortable' => true,
            'filterable' => true,
            'searchable' => true,
            'visibility' => in_array('sales_owner', $visibleColumns, true),
        ]);

        $this->addColumn([
            'index' => 'organization',
            'label' => trans('admin::app.contacts.persons.index.datagrid.organization-name'),
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
            'filterable_type' => 'searchable_dropdown',
            'filterable_options' => [
                'repository' => OrganizationRepository::class,
                'column' => [
                    'label' => 'name',
                    'value' => 'name',
                ],
            ],
            'visibility' => in_array('organization', $visibleColumns, true),
        ]);

        foreach (PersonGridColumnPreferences::customAttributes() as $attribute) {
            $columnIndex = PersonGridColumnPreferences::attributeColumn((int) $attribute->id);
            $valueField = Person::$attributeTypeFields[$attribute->type];

            $this->addColumn([
                'index' => $columnIndex,
                'label' => htmlspecialchars($attribute->name, ENT_QUOTES, 'UTF-8'),
                'type' => 'string',
                'sortable' => false,
                'filterable' => false,
                'searchable' => false,
                'closure' => function ($row) use ($columnIndex, $attribute, $valueField) {
                    $value = $row->{$columnIndex};

                    if ($value === null) {
                        return '';
                    }

                    if ($attribute->type === 'boolean') {
                        return $value ? 'Yes' : 'No';
                    }

                    if ($valueField === 'json_value') {
                        $decoded = json_decode((string) $value, true);

                        if (! is_array($decoded)) {
                            return $value;
                        }

                        return collect($decoded)
                            ->map(function ($item) {
                                if (is_array($item)) {
                                    return $item['value'] ?? collect($item)
                                        ->filter(fn ($part) => is_scalar($part))
                                        ->join(' ');
                                }

                                return is_scalar($item) ? (string) $item : '';
                            })
                            ->filter()
                            ->join(', ');
                    }

                    if (in_array($attribute->type, ['multiselect', 'checkbox'], true)) {
                        $decoded = json_decode((string) $value, true);

                        if (is_array($decoded)) {
                            return collect($decoded)
                                ->filter(fn ($item) => is_scalar($item))
                                ->join(', ');
                        }
                    }

                    return $value;
                },
                'visibility' => in_array($columnIndex, $visibleColumns, true),
            ]);
        }
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('contacts.persons.view')) {
            $this->addAction([
                'icon' => 'icon-eye',
                'title' => trans('admin::app.contacts.persons.index.datagrid.view'),
                'method' => 'GET',
                'url' => function ($row) {
                    return route('admin.contacts.persons.view', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('contacts.persons.edit')) {
            $this->addAction([
                'icon' => 'icon-edit',
                'title' => trans('admin::app.contacts.persons.index.datagrid.edit'),
                'method' => 'GET',
                'url' => function ($row) {
                    return route('admin.contacts.persons.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('contacts.persons.delete')) {
            $this->addAction([
                'icon' => 'icon-delete',
                'title' => trans('admin::app.contacts.persons.index.datagrid.delete'),
                'method' => 'DELETE',
                'url' => function ($row) {
                    return route('admin.contacts.persons.delete', $row->id);
                },
            ]);
        }
    }

    /**
     * Prepare mass actions.
     */
    public function prepareMassActions(): void
    {
        if (bouncer()->hasPermission('contacts.persons.delete')) {
            $this->addMassAction([
                'icon' => 'icon-delete',
                'title' => trans('admin::app.contacts.persons.index.datagrid.delete'),
                'method' => 'POST',
                'url' => route('admin.contacts.persons.mass_delete'),
            ]);
        }
    }
}
