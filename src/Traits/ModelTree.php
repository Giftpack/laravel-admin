<?php

namespace Encore\Admin\Traits;

use Encore\Admin\Tree;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Request;

trait ModelTree
{
    /**
     * @var array
     */
    protected static $branchOrder = [];

    /**
     * @var string
     */
    protected $parentColumn = 'parent_id';

    /**
     * @var string
     */
    protected $titleColumn = 'title';

    /**
     * @var string
     */
    protected $orderColumn = 'order';

    /**
     * @var \Closure
     */
    protected $queryCallback;

    /**
     * Get children of current node.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function children()
    {
        return $this->hasMany(static::class, $this->parentColumn);
    }

    /**
     * Get parent of current node.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(static::class, $this->parentColumn);
    }

    /**
     * @return string
     */
    public function getParentColumn()
    {
        return $this->parentColumn;
    }

    /**
     * Set parent column.
     *
     * @param string $column
     */
    public function setParentColumn($column)
    {
        $this->parentColumn = $column;
    }

    /**
     * Get title column.
     *
     * @return string
     */
    public function getTitleColumn()
    {
        return $this->titleColumn;
    }

    /**
     * Set title column.
     *
     * @param string $column
     */
    public function setTitleColumn($column)
    {
        $this->titleColumn = $column;
    }

    /**
     * Get order column name.
     *
     * @return string
     */
    public function getOrderColumn()
    {
        return $this->orderColumn;
    }

    /**
     * Set order column.
     *
     * @param string $column
     */
    public function setOrderColumn($column)
    {
        $this->orderColumn = $column;
    }

    /**
     * Set query callback to model.
     *
     * @param \Closure|null $query
     *
     * @return $this
     */
    public function withQuery(?\Closure $query = null)
    {
        $this->queryCallback = $query;

        return $this;
    }

    /**
     * Format data to tree like array.
     *
     * @return array
     */
    public function toTree()
    {
        return $this->buildNestedArray();
    }

    /**
     * Build Nested array.
     *
     * @param array $nodes
     * @param int   $parentId
     *
     * @return array
     */
    protected function buildNestedArray(array $nodes = [], $parentId = 0)
    {
        $branch = [];

        if (empty($nodes)) {
            $nodes = $this->allNodes();
        }

        foreach ($nodes as $node) {
            if ($node[$this->parentColumn] == $parentId) {
                $children = $this->buildNestedArray($nodes, $node[$this->getKeyName()]);

                if ($children) {
                    $node['children'] = $children;
                }

                $branch[] = $node;
            }
        }

        return $branch;
    }

    /**
     * Get all elements.
     *
     * @return mixed
     */
    public function allNodes()
    {
        $orderColumn = $this->getConnection()->getQueryGrammar()->wrap($this->orderColumn);
        $byOrder = $orderColumn.' = 0,'.$orderColumn;

        $self = clone $this;

        if ($this->queryCallback instanceof \Closure) {
            $self = call_user_func($this->queryCallback, $self);
        }

        return $self->orderByRaw($byOrder)->get()->toArray();
    }

    /**
     * Set the order of branches in the tree.
     *
     * @param array $order
     *
     * @return void
     */
    protected static function setBranchOrder(array $order)
    {
        static::$branchOrder = array_flip(Arr::flatten($order));

        static::$branchOrder = array_map(function ($item) {
            return ++$item;
        }, static::$branchOrder);
    }

    /**
     * Save tree order from a tree like array.
     *
     * @param array $tree
     * @param int   $parentId
     */
    public static function saveOrder($tree = [], $parentId = 0)
    {
        (new static())->saveTreeOrder($tree, $parentId);
    }

    /**
     * Validate the entire proposal before writing on this model's connection.
     * Static saveOrder remains available for existing callers.
     */
    public function saveTreeOrder($tree = [], $parentId = 0)
    {
        $proposal = [];
        $walk = function ($branches, $parent) use (&$walk, &$proposal) {
            if (!is_array($branches) || !array_is_list($branches)) {
                throw new \InvalidArgumentException('Tree order must be a list of branches.');
            }
            foreach ($branches as $branch) {
                if (!is_array($branch) || !array_key_exists('id', $branch)
                    || array_diff(array_keys($branch), ['id', 'children'])
                    || !(is_int($branch['id']) || is_string($branch['id']))
                    || (string) $branch['id'] === '' || (string) $branch['id'] === '0') {
                    throw new \InvalidArgumentException('Invalid tree branch.');
                }
                $id = (string) $branch['id'];
                if (isset($proposal[$id]) || $id === (string) $parent) {
                    throw new \InvalidArgumentException('Duplicate or self-parent tree branch.');
                }
                $proposal[$id] = ['parent' => $parent, 'order' => count($proposal) + 1];
                if (array_key_exists('children', $branch)) {
                    $walk($branch['children'], $branch['id']);
                }
            }
        };
        if (!(is_int($parentId) || is_string($parentId)) || (string) $parentId === '') {
            throw new \InvalidArgumentException('Invalid tree parent.');
        }
        $walk($tree, $parentId);

        $this->getConnection()->transaction(function () use ($proposal, $parentId) {
            $query = $this->newQuery();
            if ($this->queryCallback instanceof \Closure) {
                $query = call_user_func($this->queryCallback, clone $this);
            }
            $nodes = $query->lockForUpdate()->get()->keyBy($this->getKeyName());
            $hierarchy = $this->queryCallback instanceof \Closure
                ? $this->newQuery()->lockForUpdate()->get()->keyBy($this->getKeyName())
                : $nodes;
            if ((string) $parentId !== '0' && !$nodes->has($parentId)) {
                throw new \InvalidArgumentException('Tree parent does not exist in this tree.');
            }
            foreach ($proposal as $id => $change) {
                if (!$nodes->has($id)) {
                    throw new \InvalidArgumentException('Tree branch does not exist in this tree.');
                }
                // Include retained ancestors: a partial reorder cannot create a cycle.
                $seen = [(string) $id => true];
                $parent = $change['parent'];
                while ((string) $parent !== '0' && $hierarchy->has($parent)) {
                    if (isset($seen[(string) $parent])) {
                        throw new \InvalidArgumentException('Tree order would create a cycle.');
                    }
                    $seen[(string) $parent] = true;
                    $parent = $proposal[(string) $parent]['parent'] ?? $hierarchy->get($parent)->getAttribute($this->parentColumn);
                }
            }
            foreach ($proposal as $id => $change) {
                $node = $nodes->get($id);
                $node->setAttribute($this->parentColumn, $change['parent']);
                $node->setAttribute($this->orderColumn, $change['order']);
                if ($node->save() === false) {
                    throw new \RuntimeException('Tree branch could not be saved.');
                }
            }
        });
    }

    /**
     * Get options for Select field in form.
     *
     * @param \Closure|null $closure
     * @param string        $rootText
     *
     * @return array
     */
    public static function selectOptions(?\Closure $closure = null, $rootText = 'ROOT')
    {
        $options = (new static())->withQuery($closure)->buildSelectOptions();

        return collect($options)->prepend($rootText, 0)->all();
    }

    /**
     * Build options of select field in form.
     *
     * @param array  $nodes
     * @param int    $parentId
     * @param string $prefix
     * @param string $space
     *
     * @return array
     */
    protected function buildSelectOptions(array $nodes = [], $parentId = 0, $prefix = '', $space = '&nbsp;')
    {
        $prefix = $prefix ?: '┝'.$space;

        $options = [];

        if (empty($nodes)) {
            $nodes = $this->allNodes();
        }

        foreach ($nodes as $index => $node) {
            if ($node[$this->parentColumn] == $parentId) {
                $node[$this->titleColumn] = $prefix.$space.$node[$this->titleColumn];

                $childrenPrefix = str_replace('┝', str_repeat($space, 6), $prefix).'┝'.str_replace(['┝', $space], '', $prefix);

                $children = $this->buildSelectOptions($nodes, $node[$this->getKeyName()], $childrenPrefix);

                $options[$node[$this->getKeyName()]] = $node[$this->titleColumn];

                if ($children) {
                    $options += $children;
                }
            }
        }

        return $options;
    }

    /**
     * {@inheritdoc}
     */
    public function delete()
    {
        $existed = $this->exists;
        $cancelled = new \RuntimeException('Tree deletion was cancelled.');

        try {
            return $this->getConnection()->transaction(function () use ($cancelled) {
                $result = parent::delete();
                if ($result === false) {
                    // Earlier deleting listeners may already have changed related rows.
                    throw $cancelled;
                }
                if ($result) {
                    $this->deleteTreeChildren();
                }

                return $result;
            });
        } catch (\Throwable $exception) {
            $this->exists = $existed;

            if ($exception === $cancelled) {
                return false;
            }

            throw $exception;
        }
    }

    /** Delete direct children only; bulk deletion intentionally retains deeper descendants. */
    protected function deleteTreeChildren()
    {
        $this->newQuery()->where($this->parentColumn, $this->getKey())->delete();
    }

    /**
     * {@inheritdoc}
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function (Model $branch) {
            $parentColumn = $branch->getParentColumn();

            if ($branch->getKey() !== null && (string) $branch->getAttribute($parentColumn) === (string) $branch->getKey()) {
                throw new \Exception(trans('admin.parent_select_error'));
            }

            if (Request::has('_order')) {
                $order = Request::input('_order');

                Request::offsetUnset('_order');

                (new Tree($branch))->saveOrder($order);

                return false;
            }

            return null;
        });
    }
}
