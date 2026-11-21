<?php

namespace Encore\Admin\Grid\Exporters;

use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;

if (!interface_exists(FromQuery::class) || !trait_exists(Exportable::class)) {
    throw new \LogicException('Excel export requires the optional maatwebsite/excel ^4.0 package.');
}

abstract class ExcelExporter extends AbstractExporter implements FromQuery, WithHeadings
{
    use Exportable;

    /**
     * @var array
     */
    protected $headings = [];

    /**
     * @var array
     */
    protected $columns = [];

    /**
     * @return array
     */
    public function headings(): array
    {
        if (!empty($this->columns)) {
            return array_values($this->columns);
        }

        return $this->headings;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model
     */
    public function query(): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation
    {
        if (!empty($this->columns)) {
            $columns = array_keys($this->columns);

            $eagerLoads = array_keys($this->getQuery()->getEagerLoads());

            $columns = collect($columns)->reject(function ($column) use ($eagerLoads) {
                return Str::contains($column, '.') || in_array($column, $eagerLoads);
            });

            return $this->getQuery()->select($columns->toArray());
        }

        return $this->getQuery();
    }

    /**
     * {@inheritdoc}
     */
    public function export()
    {
        $this->download($this->fileName)->prepare(request())->send();

        exit;
    }
}
