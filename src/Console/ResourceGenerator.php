<?php

namespace Encore\Admin\Console;

use Illuminate\Database\Eloquent\Model;

class ResourceGenerator
{
    protected $model;

    protected $columns;

    protected $fieldTypeMapping = [
        'ip' => 'ip', 'email' => 'email|mail', 'password' => 'password|pwd',
        'url' => 'url|link|src|href', 'mobile' => 'mobile|phone', 'color' => 'color|rgb',
        'image' => 'image|img|avatar|pic|picture|cover', 'file' => 'file|attachment',
    ];

    public function __construct($model)
    {
        $this->model = $this->getModel($model);
    }

    protected function getModel($model)
    {
        if ($model instanceof Model) {
            return $model;
        }

        if (!is_string($model) || !class_exists($model) || !is_subclass_of($model, Model::class)
            || !(new \ReflectionClass($model))->isInstantiable()) {
            throw new \InvalidArgumentException('The resource model must be an instantiable Eloquent model class.');
        }

        return new $model();
    }

    public function generateForm()
    {
        $output = '';
        foreach ($this->getTableColumns() as $column) {
            if (in_array($column['name'], $this->getReservedColumns(), true)
                || $column['auto_increment'] || $column['generated']) {
                continue;
            }

            $field = $this->fieldType($column);
            $output .= sprintf('$form->%s(%s, __(%s))', $field,
                var_export($column['name'], true), var_export($this->formatLabel($column['name']), true));
            [$hasDefault, $default] = $this->literalDefault($column);
            if ($hasDefault) {
                $output .= '->default('.var_export($default, true).')';
            }
            $output .= ";\r\n";
        }

        return $output;
    }

    public function generateShow()
    {
        return $this->generateColumns('$show->field');
    }

    public function generateGrid()
    {
        return $this->generateColumns('$grid->column');
    }

    protected function generateColumns($method)
    {
        $output = '';
        foreach ($this->getTableColumns() as $column) {
            $output .= sprintf('%s(%s, __(%s));', $method,
                var_export($column['name'], true), var_export($this->formatLabel($column['name']), true))."\r\n";
        }

        return $output;
    }

    protected function getReservedColumns()
    {
        $columns = [$this->model->getKeyName(), 'deleted_at'];
        if ($this->model->usesTimestamps()) {
            $columns[] = $this->model->getCreatedAtColumn();
            $columns[] = $this->model->getUpdatedAtColumn();
        }

        return $columns;
    }

    /** Native schema metadata belongs to the model's connection; Builder applies its prefix once. */
    protected function getTableColumns()
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $connection = $this->model->getConnection();
        $columns = $connection->getSchemaBuilder()->getColumns($this->model->getTable());
        if ($columns === []) {
            throw new \InvalidArgumentException('The resource model table does not exist or has no visible columns.');
        }

        return $this->columns = array_map(function (array $column) use ($connection) {
            return [
                'name' => $column['name'],
                'type' => strtolower($column['type_name']),
                'definition' => strtolower($column['type']),
                'default' => $column['default'],
                'nullable' => $column['nullable'],
                'auto_increment' => $column['auto_increment'],
                'generated' => !empty($column['generation']),
                'driver' => $connection->getDriverName(),
            ];
        }, $columns);
    }

    protected function fieldType(array $column)
    {
        $type = $column['type'];
        if (in_array($type, ['bool', 'boolean'], true)
            || ($type === 'tinyint' && preg_match('/^tinyint\(1\)(?: unsigned)?$/', $column['definition']))) {
            return 'switch';
        }
        if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8', 'serial', 'bigserial'], true)) {
            return 'number';
        }
        if (in_array($type, ['decimal', 'numeric', 'float', 'double', 'float4', 'float8', 'real', 'money'], true)) {
            return 'decimal';
        }
        if (in_array($type, ['datetime', 'timestamp', 'timestamptz'], true)) {
            return 'datetime';
        }
        if ($type === 'date') {
            return 'date';
        }
        if (in_array($type, ['time', 'timetz'], true)) {
            return 'time';
        }
        if (in_array($type, ['text', 'tinytext', 'mediumtext', 'longtext', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea'], true)) {
            return 'textarea';
        }
        if (in_array($type, ['char', 'varchar', 'bpchar', 'character', 'string'], true)) {
            foreach ($this->fieldTypeMapping as $field => $pattern) {
                if (preg_match('/^('.$pattern.')$/i', $column['name'])) {
                    return $field;
                }
            }
        }

        // JSON, enum, UUID, arrays and unknown/vendor types use a conservative text field.
        return 'text';
    }

    /** Decode SQL literals only. Database expressions remain database-owned, never emitted as PHP. */
    protected function literalDefault(array $column)
    {
        $value = $column['default'];
        if ($value === null) {
            return [$column['nullable'], null];
        }
        if ($column['driver'] === 'pgsql') {
            $value = trim((string) $value);
            if (preg_match("/^'((?:[^']|'')*)'(?:\\s*::[\\w\\s\\[\\].\"]+(?:\\(\\d+(?:,\\d+)?\\))?)?$/s", $value, $match)) {
                $value = str_replace("''", "'", $match[1]);
            } elseif (!preg_match('/^(?:true|false|[+-]?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)$/', $value)) {
                return [false, null];
            }
        } elseif (preg_match('/^\(.*\)$/s', (string) $value)
            || preg_match('/^[a-z_][a-z_0-9]*\s*\(.*\)$/is', (string) $value)
            || (in_array($this->fieldType($column), ['datetime', 'date', 'time'], true)
                && preg_match('/^(?:current_timestamp|current_date|current_time)$/i', (string) $value))) {
            return [false, null];
        }
        if ($this->fieldType($column) === 'switch') {
            return [true, in_array(strtolower((string) $value), ['1', 'true', 't'], true)];
        }
        if ($this->fieldType($column) === 'number' && preg_match('/^[+-]?\d+$/', (string) $value)) {
            // Keep numbers outside PHP's integer range as strings instead of losing precision.
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            return [true, $integer === false ? (string) $value : $integer];
        }

        return [true, $value];
    }

    protected function formatLabel($value)
    {
        return ucfirst(str_replace(['-', '_'], ' ', $value));
    }
}
