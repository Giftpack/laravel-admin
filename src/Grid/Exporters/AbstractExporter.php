<?php

namespace Encore\Admin\Grid\Exporters;

use Encore\Admin\Grid;

abstract class AbstractExporter implements ExporterInterface
{
    /**
     * @var \Encore\Admin\Grid
     */
    protected $grid;

    /**
     * @var int
     */
    protected $page;

    protected $queryPrepared = false;

    /**
     * Create a new exporter instance.
     *
     * @param $grid
     */
    public function __construct(?Grid $grid = null)
    {
        if ($grid) {
            $this->setGrid($grid);
        }
    }

    /**
     * Set grid for exporter.
     *
     * @param Grid $grid
     *
     * @return $this
     */
    public function setGrid(Grid $grid)
    {
        $this->grid = $grid;

        return $this;
    }

    /**
     * Get table of grid.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->grid->model()->getOriginalModel()->getTable();
    }

    /**
     * Get data with export query.
     *
     * @param bool $toArray
     *
     * @return array|\Illuminate\Support\Collection|mixed
     */
    public function getData($toArray = true)
    {
        $data = $this->grid->model()->applyCollectionCallback($this->getQuery()->get());

        return $toArray ? $data->toArray() : $data;
    }

    /**
     * @param callable $callback
     * @param int      $count
     *
     * @return bool
     */
    public function chunk(callable $callback, $count = 100)
    {
        // Current-page exports historically apply the whole-page collection callback.
        if ($this->page) {
            foreach ($this->getData(false)->chunk($count) as $collection) {
                if ($callback($collection) === false) {
                    return false;
                }
            }

            return true;
        }

        return $this->getQuery()->chunk($count, $callback);
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function getCollection()
    {
        return collect($this->getData());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Model
     */
    public function getQuery()
    {
        $model = $this->grid->getFilter()->getModel();
        if (!$this->queryPrepared) {
            $this->grid->applyQuery();
            $filter = $this->grid->getFilter();
            $filter->getExportQuery();
            $this->queryPrepared = true;
        }

        $queryBuilder = clone $model->getQueryBuilder();
        if ($queryBuilder instanceof \Illuminate\Database\Eloquent\Model) {
            $queryBuilder = $queryBuilder->newQuery();
        }
        $queryBuilder->orderBy($model->getOriginalModel()->qualifyColumn($this->grid->getKeyName()));

        // Restrict the already filtered/scoped query, without leaking page offsets into chunking.
        if ($this->page) {
            $keyName = $model->getOriginalModel()->qualifyColumn($this->grid->getKeyName());
            $maximum = max(1, (int) config('admin.grid.max_per_page', 1000), ...array_map('intval', $this->grid->perPages));
            $perPage = filter_var(request($model->getPerPageName()), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $maximum],
            ]) ?: $model->getPerPage();
            if ($this->page > intdiv(PHP_INT_MAX, max(1, (int) $perPage))) {
                abort(422, 'Invalid export page.');
            }
            $scope = (clone $queryBuilder)->select([$keyName])->setEagerLoads([])
                ->forPage($this->page, $perPage)->get();
            $queryBuilder->whereIn($keyName, $scope->modelKeys());
        }

        return $queryBuilder;
    }

    /**
     * Export data with scope.
     *
     * @param string $scope
     *
     * @return $this
     */
    public function withScope($scope)
    {
        if ($scope === Grid\Exporter::SCOPE_ALL) {
            return $this;
        }

        if (!is_string($scope) || !preg_match('/^(page|selected):(.+)$/D', $scope, $matches)) {
            abort(422, 'Invalid export scope.');
        }
        [, $scope, $args] = $matches;
        if ($scope === Grid\Exporter::SCOPE_CURRENT_PAGE) {
            $page = filter_var($args, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($page === false) {
                abort(422, 'Invalid export page.');
            }
            $this->page = $page;
        } else {
            $selected = explode(',', $args);
            if (in_array('', $selected, true)) {
                abort(422, 'Invalid export selection.');
            }
            $this->grid->model()->whereIn($this->grid->getKeyName(), $selected);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    abstract public function export();
}
