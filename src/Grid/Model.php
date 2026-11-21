<?php

namespace Encore\Admin\Grid;

use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class Model
{
    /**
     * Eloquent model instance of the grid model.
     *
     * @var EloquentModel
     */
    protected $model;

    /**
     * @var EloquentModel
     */
    protected $originalModel;

    /**
     * Array of queries of the eloquent model.
     *
     * @var \Illuminate\Support\Collection
     */
    protected $queries;

    /**
     * Sort parameters of the model.
     *
     * @var array
     */
    protected $sort;

    /**
     * @var array
     */
    protected $data = [];

    protected $dataBuilt = false;

    protected $sortableColumns = [];

    public function registerSortable($column, $cast = null)
    {
        $this->sortableColumns[$column] = $cast;

        return $this;
    }

    /*
     * 20 items per page as default.
     *
     * @var int
     */
    protected $perPage = 20;

    /**
     * If the model use pagination.
     *
     * @var bool
     */
    protected $usePaginate = true;

    /**
     * The query string variable used to store the per-page.
     *
     * @var string
     */
    protected $perPageName = 'per_page';

    /**
     * The query string variable used to store the sort.
     *
     * @var string
     */
    protected $sortName = '_sort';

    /**
     * Collection callback.
     *
     * @var \Closure
     */
    protected $collectionCallback;

    /**
     * @var Grid
     */
    protected $grid;

    /**
     * @var Relation
     */
    protected $relation;

    /**
     * Create a new grid model instance.
     *
     * @param EloquentModel $model
     * @param Grid          $grid
     */
    public function __construct(EloquentModel $model, ?Grid $grid = null)
    {
        $this->model = $model;

        $this->originalModel = $model;

        $this->grid = $grid;

        $this->queries = collect();
    }

    /**
     * @return EloquentModel
     */
    public function getOriginalModel()
    {
        return $this->originalModel;
    }

    /**
     * Get the eloquent model of the grid model.
     *
     * @return EloquentModel
     */
    public function eloquent()
    {
        return $this->model;
    }

    /**
     * Enable or disable pagination.
     *
     * @param bool $use
     */
    public function usePaginate($use = true)
    {
        $this->usePaginate = $use;
    }

    /**
     * Get the query string variable used to store the per-page.
     *
     * @return string
     */
    public function getPerPageName()
    {
        return $this->perPageName;
    }

    /**
     * Set the query string variable used to store the per-page.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setPerPageName($name)
    {
        $this->perPageName = $name;

        return $this;
    }

    /**
     * Get per-page number.
     *
     * @return int
     */
    public function getPerPage()
    {
        return $this->perPage;
    }

    /**
     * Set per-page number.
     *
     * @param int $perPage
     *
     * @return $this
     */
    public function setPerPage($perPage)
    {
        $this->perPage = $perPage;

        $this->__call('paginate', [$perPage]);

        return $this;
    }

    /**
     * Get the query string variable used to store the sort.
     *
     * @return string
     */
    public function getSortName()
    {
        return $this->sortName;
    }

    /**
     * Set the query string variable used to store the sort.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setSortName($name)
    {
        $this->sortName = $name;

        return $this;
    }

    /**
     * Set parent grid instance.
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
     * Get parent gird instance.
     *
     * @return Grid
     */
    public function getGrid()
    {
        return $this->grid;
    }

    /**
     * @param Relation $relation
     *
     * @return $this
     */
    public function setRelation(Relation $relation)
    {
        $this->relation = $relation;

        return $this;
    }

    /**
     * @return Relation
     */
    public function getRelation()
    {
        return $this->relation;
    }

    /**
     * Get constraints.
     *
     * @return array|bool
     */
    public function getConstraints()
    {
        if ($this->relation instanceof HasMany) {
            return [
                $this->relation->getForeignKeyName() => $this->relation->getParentKey(),
            ];
        }

        return false;
    }

    /**
     * Set collection callback.
     *
     * @param \Closure $callback
     *
     * @return $this
     */
    public function collection(?\Closure $callback = null)
    {
        $this->collectionCallback = $callback;

        return $this;
    }

    /**
     * Build.
     *
     * @param bool $toArray
     *
     * @return array|Collection|mixed
     */
    public function buildData($toArray = true)
    {
        if (!$this->dataBuilt) {
            $collection = $this->get();

            $collection = $this->applyCollectionCallback($collection);

            if ($toArray) {
                $this->data = $collection->toArray();
            } else {
                $this->data = $collection;
            }
        }

        $this->dataBuilt = true;

        return $this->data;
    }

    /** Apply the server-defined collection transformation to a retrieved export/page. */
    public function applyCollectionCallback($collection)
    {
        return $this->collectionCallback ? call_user_func($this->collectionCallback, $collection) : $collection;
    }

    /**
     * @param callable $callback
     * @param int      $count
     *
     * @return bool
     */
    public function chunk($callback, $count = 100)
    {
        if ($this->usePaginate) {
            return $this->buildData(false)->chunk($count)->each($callback);
        }

        if ($this->relation) {
            $this->model = clone $this->relation;
        }

        $this->setSort();

        $this->queries->reject(function ($query) {
            return $query['method'] == 'paginate';
        })->each(function ($query) {
            $this->model = $this->model->{$query['method']}(...$query['arguments']);
        });

        return $this->model->chunk($count, $callback);
    }

    /**
     * Add conditions to grid model.
     *
     * @param array $conditions
     *
     * @return $this
     */
    public function addConditions(array $conditions)
    {
        foreach ($conditions as $condition) {
            call_user_func_array([$this, key($condition)], current($condition));
        }

        return $this;
    }

    /**
     * Get table of the model.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->model->getTable();
    }

    /**
     * @throws \Exception
     *
     * @return Collection
     */
    protected function get()
    {
        if ($this->model instanceof LengthAwarePaginator) {
            return $this->model;
        }

        if ($this->relation) {
            $this->model = clone $this->relation;
        }

        $this->setSort();
        $this->setPaginate();

        $this->queries->unique()->each(function ($query) {
            $this->model = call_user_func_array([$this->model, $query['method']], $query['arguments']);
        });

        if ($this->model instanceof Collection) {
            return $this->model;
        }

        if ($this->model instanceof LengthAwarePaginator) {
            $this->handleInvalidPage($this->model);

            return $this->model->getCollection();
        }

        throw new \Exception('Grid query error');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder|EloquentModel
     */
    public function getQueryBuilder()
    {
        $this->setSort();

        $queryBuilder = $this->relation ? clone $this->relation->getQuery() : $this->originalModel;

        $this->queries->reject(function ($query) {
            return in_array($query['method'], ['get', 'paginate']);
        })->each(function ($query) use (&$queryBuilder) {
            $queryBuilder = $queryBuilder->{$query['method']}(...$query['arguments']);
        });

        return $queryBuilder;
    }

    /**
     * If current page is greater than last page, then redirect to last page.
     *
     * @param LengthAwarePaginator $paginator
     *
     * @return void
     */
    protected function handleInvalidPage(LengthAwarePaginator $paginator)
    {
        if ($paginator->lastPage() && $paginator->currentPage() > $paginator->lastPage()) {
            $lastPageUrl = Request::fullUrlWithQuery([
                $paginator->getPageName() => $paginator->lastPage(),
            ]);

            throw new \Illuminate\Http\Exceptions\HttpResponseException(redirect($lastPageUrl));
        }
    }

    /**
     * Set the grid paginate.
     *
     * @return void
     */
    protected function setPaginate()
    {
        $paginate = $this->findQueryByMethod('paginate');

        $this->queries = $this->queries->reject(function ($query) {
            return $query['method'] == 'paginate';
        });

        if (!$this->usePaginate) {
            $query = [
                'method'    => 'get',
                'arguments' => [],
            ];
        } else {
            $query = [
                'method'    => 'paginate',
                'arguments' => $this->resolvePerPage($paginate),
            ];
        }

        $this->queries->push($query);
    }

    /**
     * Resolve perPage for pagination.
     *
     * @param array|null $paginate
     *
     * @return array
     */
    protected function resolvePerPage($paginate)
    {
        $maximum = max(1, (int) config('admin.grid.max_per_page', 1000), ...array_map('intval', $this->grid?->perPages ?? []));
        $perPage = filter_var(request($this->perPageName), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => $maximum],
        ]);

        $arguments = $paginate['arguments'] ?? [$this->perPage];
        if ($perPage !== false && $perPage !== null) {
            $arguments[0] = $perPage;
        }
        $this->perPage = $arguments[0];

        if ($name = $this->grid?->getName()) {
            $arguments[1] ??= ['*'];
            $arguments[2] ??= "{$name}_page";
        }

        return $arguments;
    }

    /**
     * Find query by method name.
     *
     * @param $method
     *
     * @return static
     */
    protected function findQueryByMethod($method)
    {
        return $this->queries->first(function ($query) use ($method) {
            return $query['method'] == $method;
        });
    }

    /**
     * Set the grid sort.
     *
     * @return void
     */
    protected function setSort()
    {
        $this->sort = \request($this->sortName, []);
        if (!is_array($this->sort)) {
            return;
        }

        $columnName = $this->sort['column'] ?? null;
        $direction = $this->sort['type'] ?? null;
        if (!is_string($columnName)
            || !array_key_exists($columnName, $this->sortableColumns)
            || !is_string($direction) || !in_array(strtolower($direction), ['asc', 'desc'], true)) {
            return;
        }
        $this->sort['type'] = strtolower($direction);
        $relationName = Str::camel(Str::before($columnName, '.'));
        $hasRelation = $this->queries->contains(function ($query) use ($relationName) {
            if ($query['method'] !== 'with') {
                return false;
            }
            foreach ($query['arguments'] as $argument) {
                if ($argument === $relationName || (is_array($argument) && (array_key_exists($relationName, $argument) || in_array($relationName, $argument, true)))) {
                    return true;
                }
            }
            return false;
        });
        if (str_contains($columnName, '.') && $hasRelation) {
            $this->setRelationSort($columnName);
            return;
        }

        // Let the active grammar compile JSON paths and quote identifiers on each engine.
        $column = str_replace('.', '->', $columnName);
        $cast = $this->sortableColumns[$columnName];
        $expression = null;
        if ($cast !== null && $cast !== '') {
            $driver = $this->originalModel->getConnection()->getDriverName();
            $casts = $driver === 'pgsql'
                ? ['SIGNED' => 'BIGINT', 'UNSIGNED' => 'BIGINT', 'INTEGER' => 'BIGINT', 'DECIMAL' => 'DECIMAL', 'CHAR' => 'TEXT']
                : ['SIGNED' => 'SIGNED', 'UNSIGNED' => 'UNSIGNED', 'INTEGER' => 'SIGNED', 'DECIMAL' => 'DECIMAL', 'CHAR' => 'CHAR'];
            if (!is_string($cast)) {
                throw new \InvalidArgumentException('Sortable cast must be a SQL type string.');
            }
            $type = strtoupper(trim($cast));
            if (isset($casts[$type])) {
                $type = $casts[$type];
            } elseif (preg_match('/^(DECIMAL|NUMERIC)\(\d{1,2},\d{1,2}\)$/D', $type)) {
                // MySQL accepts DECIMAL(p,s), but not its NUMERIC alias in CAST.
                if ($driver === 'mysql') {
                    $type = preg_replace('/^NUMERIC/', 'DECIMAL', $type);
                }
                // Precision and scale are server configuration, never request SQL.
            } elseif (in_array($type, ['DATE', 'TIME', 'JSON'], true)) {
                // Shared SQL type names.
            } elseif ($type === 'DATETIME') {
                $type = $driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME';
            } elseif ($driver === 'pgsql' && in_array($type, ['BIGINT', 'INT', 'TEXT', 'TIMESTAMP', 'BOOLEAN', 'DOUBLE PRECISION'], true)) {
                // Explicit PostgreSQL server types.
            } elseif ($driver === 'mysql' && $type === 'BINARY') {
                // Explicit MySQL server type.
            } else {
                throw new \InvalidArgumentException('Unsupported sortable cast for the configured database.');
            }
            $wrapped = $this->originalModel->getConnection()->getQueryGrammar()->wrap($column);
            $expression = new Expression('CAST('.$wrapped.' AS '.$type.')');
        }
        $this->resetOrderBy();
        $this->queries->push(['method' => 'orderBy', 'arguments' => [$expression ?? $column, $this->sort['type']]]);
    }

    /** Sort by a scoped scalar subquery, preserving rows without a related model. */
    protected function setRelationSort($column)
    {
        [$name, $attribute] = explode('.', $column, 2);
        if (str_contains($attribute, '.')) {
            throw new \InvalidArgumentException('Nested relation sorting is not supported.');
        }
        $relation = Relation::noConstraints(fn () => $this->originalModel->{Str::camel($name)}());
        $query = $relation->getQuery();
        if ($relation instanceof BelongsTo) {
            $query->whereColumn($relation->getQualifiedOwnerKeyName(), $relation->getQualifiedForeignKeyName());
        } elseif ($relation instanceof HasOne) {
            $query->whereColumn($relation->getQualifiedForeignKeyName(), $relation->getQualifiedParentKeyName());
        } else {
            throw new \InvalidArgumentException('Related sortable only support `HasOne` and `BelongsTo` relation.');
        }
        $query->select($relation->getRelated()->qualifyColumn($attribute))->limit(1);
        if (empty($query->getQuery()->orders)) {
            $query->orderBy($relation->getRelated()->getQualifiedKeyName());
        }
        $this->resetOrderBy();
        $this->queries->push(['method' => 'orderBy', 'arguments' => [$query, $this->sort['type']]]);
    }

    /**
     * Reset orderBy query.
     *
     * @return void
     */
    public function resetOrderBy()
    {
        $this->queries = $this->queries->reject(function ($query) {
            return in_array($query['method'], ['orderBy', 'orderByDesc', 'orderByRaw'], true);
        });
    }

    /**
     * Build join parameters for related model.
     *
     * `HasOne` and `BelongsTo` relation has different join parameters.
     *
     * @param Relation $relation
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function joinParameters(Relation $relation)
    {
        $relatedTable = $relation->getRelated()->getTable();

        if ($relation instanceof BelongsTo) {
            $foreignKeyMethod = version_compare(app()->version(), '5.8.0', '<') ? 'getForeignKey' : 'getForeignKeyName';

            return [
                $relatedTable,
                $relation->{$foreignKeyMethod}(),
                '=',
                $relatedTable.'.'.$relation->getRelated()->getKeyName(),
            ];
        }

        if ($relation instanceof HasOne) {
            return [
                $relatedTable,
                $relation->getQualifiedParentKeyName(),
                '=',
                $relation->getQualifiedForeignKeyName(),
            ];
        }

        throw new \Exception('Related sortable only support `HasOne` and `BelongsTo` relation.');
    }

    /**
     * @param string $method
     * @param array  $arguments
     *
     * @return $this
     */
    public function __call($method, $arguments)
    {
        $this->queries->push([
            'method'    => $method,
            'arguments' => $arguments,
        ]);

        return $this;
    }

    /**
     * @param $key
     *
     * @return mixed
     */
    public function __get($key)
    {
        $data = $this->buildData();

        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
    }
}
