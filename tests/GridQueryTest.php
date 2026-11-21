<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Filter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class GridRecord extends Model
{
    use SoftDeletes;
    protected $table = 'grid_records';
    protected $primaryKey = 'record_key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $guarded = [];
    protected function casts(): array { return ['payload' => 'array']; }
    public function detail() { return $this->hasOne(GridDetail::class, 'record_key', 'record_key'); }
    public function details() { return $this->hasMany(GridDetail::class, 'record_key', 'record_key'); }
    public function owner() { return $this->belongsTo(GridDetail::class, 'owner_key'); }
    public function scopeAvailable($query) { return $query->where('quantity', '>=', 0); }
}
class GridDetail extends Model
{
    protected $table = 'grid_details';
    public $timestamps = false;
    protected static function booted(): void { static::addGlobalScope('visible', fn ($query) => $query->where('label', '!=', 'Invisible')); }
}

class GridQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->be(Administrator::first(), 'admin');
        Schema::create('grid_records', function (Blueprint $table) {
            $table->string('record_key')->primary();
            $table->string('title');
            $table->integer('quantity');
            $table->integer('owner_key')->nullable();
            $table->json('payload');
            $table->timestamp('happened_at');
            $table->softDeletes();
        });
        Schema::create('grid_details', function (Blueprint $table) {
            $table->increments('id');
            $table->string('record_key');
            $table->string('label');
        });
        foreach ([['0', 'Alpha0', 0, 2, '2026-01-02'], ['a', 'Beta', 10, 10, '2026-02-03'], ['b', 'Gamma', 20, 3, '2025-01-04'], ['c', 'Hidden', -1, 1, '2026-01-05']] as [$key, $title, $quantity, $rank, $date]) {
            GridRecord::create(['record_key' => $key, 'title' => $title, 'quantity' => $quantity, 'payload' => ['rank' => $rank], 'happened_at' => $date.' 12:30:00']);
        }
        DB::table('grid_details')->insert([['record_key' => '0', 'label' => 'Zulu'], ['record_key' => 'a', 'label' => 'Alpha'], ['record_key' => 'a', 'label' => 'Duplicate'], ['record_key' => 'b', 'label' => 'Invisible']]);
        GridRecord::whereKey('0')->update(['owner_key' => 1]);
        GridRecord::whereKey('a')->update(['owner_key' => 2]);
        GridRecord::whereKey('c')->delete();
    }

    private function input(array $query): void
    {
        $request = Request::create('/admin/query-fixture', 'GET', $query);
        $this->app->instance('request', $request);
        Illuminate\Support\Facades\Request::swap($request);
    }
    private function grid(array $input = []): Grid
    {
        $this->input($input);
        $grid = new Grid(new GridRecord());
        $grid->column('record_key')->sortable();
        $grid->column('quantity')->sortable();
        $grid->column('payload->rank')->sortable('SIGNED');
        $grid->column('detail.label')->sortable();
        $grid->column('owner.label')->sortable();
        return $grid;
    }
    private function keys($data): array { return collect($data)->pluck('record_key')->all(); }
    private function filtered(string $name, $value, string $column = 'quantity'): array
    {
        $grid = $this->grid();
        $filter = new Filter($grid->model());
        $filter->disableIdFilter();
        $field = $filter->$name($column);
        $condition = $field->condition([$column => $value]);
        if ($condition) { $grid->model()->addConditions([$condition]); }
        return $this->keys($grid->model()->orderBy('record_key')->buildData());
    }

    public function testScopedRelationsCollectionsAndTotalsOwnTheSameQueryWithoutNPlusOne()
    {
        $grid = $this->grid(['_sort' => ['column' => 'detail.label', 'type' => 'asc']]);
        $grid->model()->available()->with(['detail'])->collection(function ($rows) {
            foreach ($rows as $row) { $row->setAttribute('decorated', $row->detail?->label); }
            return $rows;
        });
        DB::enableQueryLog(); DB::flushQueryLog();
        $data = $grid->model()->buildData();
        $this->assertCount(3, $data);
        $this->assertEqualsCanonicalizing(['0', 'a', 'b'], $this->keys($data));
        $nonNull = array_values(array_filter($data, fn ($row) => $row['decorated'] !== null));
        $this->assertSame(['a', '0'], $this->keys($nonNull));
        $this->assertCount(4, DB::getQueryLog());
        $this->assertSame(3, $grid->model()->eloquent()->total());
        $this->assertSame(30, (int) $grid->model()->getQueryBuilder()->sum('quantity'));
        $this->assertCount(3, $grid->model()->buildData());
        $this->assertSame(['0'], $this->filtered('equal', 'Zulu', 'detail.label'));
        $owner = $this->grid(['_sort' => ['column' => 'owner.label', 'type' => 'desc']]);
        $owner->model()->with('owner');
        $this->assertCount(3, $owner->model()->buildData());
        $this->assertSame(4, (new Grid(new GridRecord()))->model()->withTrashed()->buildData(false)->count());
    }

    public function testJsonSortUsesServerCastAndRejectsForgedRequestAuthority()
    {
        $grid = $this->grid(['_sort' => ['column' => 'payload.rank', 'type' => 'asc', 'cast' => 'CHAR) DESC; SELECT bad']]);
        $this->assertSame(['0', 'b', 'a'], $this->keys($grid->model()->buildData()));
        foreach ([['column' => ['x'], 'type' => 'asc'], ['column' => 'title', 'type' => 'asc'], ['column' => 'quantity', 'type' => 'asc, sleep(1)'], ['column' => 'payload.rank', 'type' => ['x']]] as $sort) {
            $grid = $this->grid(['_sort' => $sort]);
            $this->assertSame(['b', 'a', '0'], $this->keys($grid->model()->orderBy('quantity', 'desc')->buildData()));
        }
    }

    public function testPaginationRejectsMalformedSizesAndCachesEmptyResults()
    {
        foreach ([null, 0, -2, 'oops', ['2'], 100000] as $value) {
            $grid = $this->grid(['per_page' => $value]);
            $grid->model()->buildData();
            $this->assertSame(20, $grid->model()->eloquent()->perPage());
        }
        $grid = $this->grid(['per_page' => 2, 'page' => 2]);
        $grid->model()->orderBy('quantity')->buildData();
        $this->assertSame(3, $grid->model()->eloquent()->total());
        $this->assertSame(['b'], $this->keys($grid->model()->eloquent()->items()));
        $grid = $this->grid(); $grid->model()->where('quantity', 999);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame([], $grid->model()->buildData());
        $count = count(DB::getQueryLog());
        $this->assertSame([], $grid->model()->buildData());
        $this->assertCount($count, DB::getQueryLog());
    }

    public function testAllRegisteredScalarAndSetFiltersRetainZeroAndRejectMalformedValues()
    {
        $cases = [
            ['equal', 0, ['0']], ['notEqual', 0, ['a', 'b']], ['gt', 10, ['a', 'b']], ['lt', 10, ['0', 'a']],
            ['in', [0, 20], ['0', 'b']], ['notIn', [0, 20], ['a']], ['between', ['start' => 0, 'end' => 10], ['0', 'a']],
            ['like', '0', ['0'], 'title'], ['ilike', 'alpha', ['0'], 'title'], ['contains', '0', ['0'], 'title'],
            ['startsWith', 'Alpha', ['0'], 'title'], ['endsWith', '0', ['0'], 'title'],
            ['date', '2026-02-03', ['a'], 'happened_at'], ['day', 3, ['a'], 'happened_at'],
            ['month', 2, ['a'], 'happened_at'], ['year', 2025, ['b'], 'happened_at'],
        ];
        $registered = (new ReflectionClass(Filter::class))->getStaticPropertyValue('supports');
        $this->assertEqualsCanonicalizing(array_merge(array_column($cases, 0), ['group','where','hidden']), array_keys($registered));
        foreach ($cases as [$name, $value, $expected]) {
            $column = in_array($name, ['like','ilike','contains','startsWith','endsWith']) ? 'title' : (in_array($name, ['date','day','month','year']) ? 'happened_at' : 'quantity');
            $this->assertSame($expected, $this->filtered($name, $value, $column), $name);
            foreach ([null, ['nested' => ['bad']]] as $invalid) {
                if (in_array($name, ['in', 'notIn', 'between'])) { continue; }
                $this->assertSame(['0','a','b'], $this->filtered($name, $invalid, $column), $name.' invalid');
            }
        }
        foreach (['equal'=>['0'], 'notEqual'=>['a','b'], 'gt'=>['0','a','b'], 'lt'=>['0'], 'in'=>['0'], 'notIn'=>['a','b'], 'between'=>['0','a','b']] as $name => $expected) {
            foreach ([0, false] as $value) { $this->assertSame($expected, $this->filtered($name, $value), $name.' zero/false'); }
        }
        foreach (['like'=>['0'], 'ilike'=>['0'], 'contains'=>['0'], 'startsWith'=>[], 'endsWith'=>['0']] as $name => $expected) {
            $this->assertSame($expected, $this->filtered($name, 0, 'title'));
            $this->assertSame(['0','a','b'], $this->filtered($name, false, 'title'));
        }
        foreach (['date','day','month','year'] as $name) {
            foreach ([0, false] as $value) { $this->assertSame(['0','a','b'], $this->filtered($name, $value, 'happened_at')); }
        }
        $this->assertSame(['0'], $this->filtered('equal', false));
        $this->assertSame(['0'], $this->filtered('in', 0));
        $this->assertSame(['0','a'], $this->filtered('between', ['end' => 10]));
        $this->assertSame(['a','b'], $this->filtered('between', ['start' => 10]));
        $this->assertSame(['0','a','b'], $this->filtered('between', 'bad'));
    }

    public function testGroupedWhereHiddenAndPresenterLedger()
    {
        $grid = $this->grid(); $filter = new Filter($grid->model());
        $group = $filter->group('quantity', function ($group) {
            $group->equal()->gt()->where('custom', function ($query) { $query->where('quantity', $this->input); });
        });
        foreach ([0 => ['0'], 1 => ['a','b'], 2 => ['0']] as $operator => $expected) {
            $model = new Grid\Model(new GridRecord());
            $model->addConditions([$group->condition(['quantity' => 0, 'quantity_group' => $operator])])->orderBy('record_key');
            $this->assertSame($expected, $this->keys($model->buildData()));
        }
        $this->input(['quantity' => 0, 'quantity_group' => 1]);
        $group->condition(['quantity' => 0, 'quantity_group' => 1]);
        $this->assertMatchesRegularExpression('/name="quantity_group"[^>]*value="1"/', $group->render()->render());
        $this->assertNull($group->condition(['quantity' => 0, 'quantity_group' => ['bad']]));
        $where = $filter->where(function ($query) { $query->where('quantity', $this->input); }, 'Custom', 'custom');
        $this->assertSame(['0'], $this->keys((new Grid\Model(new GridRecord()))->addConditions([$where->condition(['custom' => false])])->buildData()));
        $hidden = $filter->hidden('unsafe\'name', '\'><script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $hidden->render());
        $this->assertNull($hidden->condition(['unsafe' => 0]));
        foreach (['select','multipleSelect','radio','checkbox','datetime'] as $presenter) {
            $field = $filter->equal('quantity')->default(10);
            if ($presenter === 'datetime') { $field->datetime(); }
            else { $field->$presenter([0 => 'Zero', 10 => 'Ten']); }
            $field->condition(['quantity' => 0]);
            $html = $field->render()->render();
            $this->assertStringContainsString('quantity', $html, $presenter);
            if (in_array($presenter, ['select','multipleSelect'])) { $this->assertMatchesRegularExpression('/value="0"[^>]*selected/', $html); }
            if (in_array($presenter, ['radio','checkbox'])) { $this->assertMatchesRegularExpression('/value="0"[^>]*checked/', $html); }
        }
        $text = $filter->equal('quantity')->default(10); $text->condition(['quantity' => false]);
        $this->assertStringContainsString('value=""', $text->render()->render());
    }
    public function testHttpPaginationSearchSelectorsTotalsAndPjaxKeepRestrictionsAndState()
    {
        Route::middleware(['web', 'admin'])->get('admin/query-fixture', function (Encore\Admin\Layout\Content $content) {
            $grid = new Grid(new GridRecord());
            $grid->column('record_key')->sortable();
            $grid->column('title')->sortable();
            $grid->column('quantity')->totalRow();
            $grid->quickSearch('title');
            $grid->selector(function ($selector) { $selector->select('record_key', ['0' => 'Zero', 'a' => 'A', 'b' => 'B']); });
            $grid->filter(function ($filter) { $filter->disableIdFilter(); $filter->gt('quantity'); $filter->between('happened_at')->datetime(); });
            return $content->title('Query fixture')->body($grid);
        });
        $params = ['per_page' => 1, 'quantity' => 0, '_sort' => ['column' => 'title', 'type' => 'asc'], '_selector' => ['record_key' => '0,a'], '__search__' => 'a'];
        $response = $this->call('GET', 'admin/query-fixture', $params);
        $this->assertSame(200, $response->getStatusCode());
        $html = $response->getContent();
        $this->assertStringContainsString('Alpha0', $html);
        $this->assertStringNotContainsString('Gamma', $html);
        $this->assertStringContainsString('column-quantity', $html);
        $crawler = new Symfony\Component\DomCrawler\Crawler($html);
        $this->assertSame('10', trim($crawler->filter('tfoot td.column-quantity')->text()));
        $links = $crawler->filter('.pagination a')->each(fn ($node) => $node->attr('href'));
        $this->assertNotEmpty($links);
        foreach ($links as $url) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            foreach ($params as $key => $value) { $this->assertEquals($value, $query[$key]); }
        }
        $response = $this->call('GET', 'admin/query-fixture', $params + ['page' => 99]);
        $this->assertSame(302, $response->getStatusCode());
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $redirect);
        $this->assertEquals(2, $redirect['page']);
        $this->assertEquals('0,a', $redirect['_selector']['record_key']);
        $response = $this->call('GET', 'admin/query-fixture', $params + ['page' => 99], [], [], ['HTTP_X_PJAX' => 'true', 'HTTP_X_PJAX_CONTAINER' => '#pjax-container']);
        $this->assertSame(302, $response->getStatusCode());
        $response = $this->call('GET', 'admin/query-fixture', array_replace($params, ['page' => 2]), [], [], ['HTTP_X_PJAX' => 'true', 'HTTP_X_PJAX_CONTAINER' => '#pjax-container']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Beta', $response->getContent());
        $this->assertStringNotContainsString('<html', $response->getContent());
        foreach ([['per_page' => ['bad'], 'quantity' => ['bad'], '__search__' => ['bad'], '_selector' => ['record_key' => ['bad']], 'happened_at' => ['start' => ['bad']]], ['per_page' => -2, '_sort' => ['column' => ['bad'], 'type' => ['bad']]]] as $malformed) {
            $response = $this->call('GET', 'admin/query-fixture', $malformed);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('Alpha0', $response->getContent());
            $this->assertStringNotContainsString('TypeError', $response->getContent());
        }
        // Separate requests must not inherit the first request's static selector cache.
        $response = $this->call('GET', 'admin/query-fixture', ['_selector' => ['record_key' => 'b']]);
        $this->assertStringContainsString('Gamma', $response->getContent());
        $this->assertStringNotContainsString('Alpha0', $response->getContent());
        $response = $this->call('GET', 'admin/query-fixture', ['__search__' => '0']);
        $this->assertStringContainsString('Alpha0', $response->getContent());
        $this->assertStringNotContainsString('Gamma', $response->getContent());
        $response = $this->call('GET', 'admin/query-fixture', ['quantity' => 999, 'page' => 1]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Gamma', $response->getContent());
    }

    public function testRedirectUnwindsOnlyOwnedContentBuffersAndQueryFailureHidesBindings()
    {
        ob_start(); echo 'host-buffer'; $level = ob_get_level();
        try {
            $content = new Encore\Admin\Layout\Content();
            $grid = $this->grid(['per_page' => 1, 'page' => 99]);
            $content->body($grid);
            try { $content->build(); $this->fail('Expected redirect'); }
            catch (Illuminate\Http\Exceptions\HttpResponseException $exception) { $this->assertSame(302, $exception->getResponse()->getStatusCode()); }
            $this->assertSame($level, ob_get_level());
            $this->assertSame('host-buffer', ob_get_contents());
        } finally { ob_end_clean(); }
        $grid = $this->grid();
        $grid->model()->where('missing_secret_column', 'synthetic-query-secret');
        $html = $grid->render();
        $this->assertStringNotContainsString('synthetic-query-secret', $html);
        $this->assertStringNotContainsString('missing_secret_column', $html);
        $this->assertStringContainsString('alert-danger', $html);
    }

    public function testQualifiedSchemaPrefixNativeInspectionAndGridQueryRemainSeparateFromFrameworkFind()
    {
        $config = config('database.connections.'.config('database.default')); $config['prefix'] = 'grid_';
        config(['database.connections.grid_qualified' => $config]); DB::purge('grid_qualified');
        $connection = DB::connection('grid_qualified');
        $table = ($connection->getDriverName() === 'pgsql' ? 'public' : $config['database']).'.qualified_records';
        $connection->getSchemaBuilder()->create($table, function (Blueprint $schema) { $schema->string('record_key')->primary(); $schema->string('title'); });
        $connection->table($table)->insert(['record_key' => '0', 'title' => 'qualified fixture']);
        $record = new class extends Model { public $timestamps = false; protected $primaryKey = 'record_key'; protected $keyType = 'string'; public $incrementing = false; };
        $record->setConnection('grid_qualified')->setTable($table);
        $this->assertContains('title', $connection->getSchemaBuilder()->getColumnListing($table));
        $this->input([]);
        $grid = new Grid($record);
        $this->assertSame(['0'], $this->keys($grid->model()->where('record_key', '0')->buildData()));
        try {
            $found = $record->newQuery()->find('0');
            $this->assertSame('qualified fixture', $found?->title, 'If framework qualification is fixed, retain this success as evidence.');
            $qualification = ['find' => 'supported'];
        } catch (Illuminate\Database\QueryException $exception) {
            $qualification = ['find' => 'framework-limitation', 'sql' => $exception->getSql(), 'sqlstate' => $exception->errorInfo[0] ?? null];
            $this->assertStringContainsString('qualified_records', $exception->getSql());
            $this->assertStringContainsString('grid_', $exception->getSql());
        } finally { DB::disconnect('grid_qualified'); }
        file_put_contents('/results/qualified-key-'.app()->version().'-'.config('database.default').'.json', json_encode(['native_introspection' => true, 'grid_unqualified_key_query' => true] + $qualification, JSON_PRETTY_PRINT)."\n");
    }

    public function testRelationOwnedTotalsDateRangesAndConfiguredCasts()
    {
        $this->input([]);
        $model = new Grid\Model(new GridDetail());
        $model->setRelation(GridRecord::findOrFail('a')->details())->where('label', 'Alpha');
        $this->assertCount(1, $model->buildData());
        $this->assertSame(1, $model->getQueryBuilder()->count());
        $grid = $this->grid(['_sort' => ['column' => 'quantity', 'type' => 'asc', 'cast' => 'CHAR']]);
        $grid->column('quantity')->sortable('DECIMAL(10,2)');
        $this->assertSame(['0','a','b'], $this->keys($grid->model()->buildData()));
        $grid = $this->grid(['_sort' => ['column' => 'quantity', 'type' => 'asc']]);
        $grid->column('quantity')->sortable()->cast('SIGNED');
        $this->assertSame(['0','a','b'], $this->keys($grid->model()->buildData()));
        $filter = new Filter(new Grid\Model(new GridRecord()));
        $range = $filter->between('happened_at')->datetime();
        $condition = $range->condition(['happened_at' => ['start'=>'2026-01-01', 'end'=>'2026-01-31']]);
        $this->assertSame(['0'], $this->keys((new Grid\Model(new GridRecord()))->addConditions([$condition])->buildData()));
        $this->assertNull($range->condition(['happened_at' => ['start'=>'invalid-synthetic-date']]));
        foreach (['date','day','month','year'] as $kind) { $this->assertSame(['0','a','b'], $this->filtered($kind, 'bad-date', 'happened_at')); }
        $field = $filter->equal('record_key'); $field->select()->model(GridRecord::class, 'record_key', 'title');
        $field->condition(['record_key'=>'0']);
        $this->assertStringContainsString('Alpha0', $field->render()->render());
        $field = $filter->in('record_key'); $field->multipleSelect()->model(GridRecord::class, 'record_key', 'title');
        $field->condition(['record_key'=>['0','a']]);
        $html = $field->render()->render(); $this->assertStringContainsString('Alpha0', $html); $this->assertStringContainsString('Beta', $html);
        foreach (['ilike','match'] as $kind) {
            $group = $filter->group('title', function ($group) use ($kind) { $group->$kind(); });
            $condition = $group->condition(['title' => $kind === 'ilike' ? 'alpha' : '^Alpha', 'title_group' => 0]);
            $this->assertSame(['0'], $this->keys((new Grid\Model(new GridRecord()))->addConditions([$condition])->buildData()));
        }
    }

    public function testNamedPaginationFilterScopesAndSelectorClearState()
    {
        $grid = $this->grid(['named_per_page' => 1, 'named_page' => 2, 'named_quantity' => ['start' => 0, 'end' => 20], '_scope_' => 'positive']);
        $grid->paginate(2);
        $grid->setName('named');
        $filter = $grid->getFilter(); $filter->disableIdFilter();
        $range = $filter->between('quantity');
        $filter->scope('positive', 'Positive')->where('quantity', '>', 0);
        $grid->model()->orderBy('quantity');
        $rows = $filter->execute();
        $this->assertSame(['b'], $this->keys($rows));
        $this->assertSame(2, $grid->model()->eloquent()->total());
        $this->assertSame('named_page', $grid->model()->eloquent()->getPageName());
        $this->assertStringContainsString('name="named_quantity[start]"', $range->render()->render());
        $this->input(['_selector' => ['record_key' => '0,a'], 'page' => 2, 'quantity' => 0]);
        parse_str(parse_url(Grid\Tools\Selector::url('record_key'), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('_selector', $query);
        $this->assertSame('0', $query['quantity']);
        $this->assertSame('2', $query['page']);
        $this->assertSame([], $this->filtered('in', []));
        $this->assertSame(['0','a','b'], $this->filtered('notIn', []));
    }

    public function testLaterRelationPageKeepsUnpaginatedScopedAggregateAndExportQueries()
    {
        $this->input(['per_page' => 1, 'page' => 2]);
        DB::table('grid_details')->insert(['record_key' => 'a', 'label' => 'Invisible']);
        $relation = GridRecord::findOrFail('a')->details()->where('id', '>=', 2);
        $model = new Grid\Model(new GridDetail());
        $model->setRelation($relation)->where('label', '!=', 'excluded')->orderBy('id');
        $this->assertSame(['Duplicate'], collect($model->buildData())->pluck('label')->all());
        $this->assertSame(2, $model->eloquent()->total());
        foreach ([1, 2] as $read) {
            $query = $model->getQueryBuilder();
            $this->assertSame(2, $query->count());
            $this->assertSame(5, (int) $query->sum('id'));
            $this->assertSame(['Alpha', 'Duplicate'], $query->pluck('label')->all());
            $this->assertNull($query->getQuery()->limit);
            $this->assertNull($query->getQuery()->offset);
        }
        $this->assertSame(2, $relation->count());
        $this->assertNull($relation->getQuery()->getQuery()->offset);
        $export = new Grid\Model(new GridDetail());
        $export->setRelation($relation)->where('label', '!=', 'excluded')->orderBy('id');
        $export->usePaginate(false);
        $labels = [];
        $export->chunk(function ($rows) use (&$labels) { array_push($labels, ...$rows->pluck('label')->all()); }, 1);
        $this->assertSame(['Alpha', 'Duplicate'], $labels);
        $this->assertSame(2, $export->getQueryBuilder()->count());
    }

    public function testRegisteredQuotedAndUnicodeNamesUseGrammarAndNumericCastUsesEngineType()
    {
        Schema::table('grid_records', function (Blueprint $table) {
            $table->integer('unit-price')->default(0);
            $table->integer('售價')->default(0);
        });
        foreach (['0'=>2, 'a'=>10, 'b'=>3] as $key => $rank) {
            GridRecord::where('record_key', (string) $key)->update(['unit-price'=>$rank, '售價'=>$rank, 'payload'=>['rank$value'=>$rank, 'rank-value'=>$rank, '售價'=>$rank]]);
        }
        foreach (['unit-price','售價','payload->rank$value','payload->rank-value','payload->售價'] as $name) {
            $requestName = str_replace('->', '.', $name);
            $this->input(['_sort'=>['column'=>$requestName, 'type'=>'asc', 'cast'=>'CHAR) DESC; invalid']]);
            $grid = new Grid(new GridRecord());
            $grid->column($name)->sortable(str_contains($name, '->') ? 'SIGNED' : null);
            $this->assertSame(['0','b','a'], $this->keys($grid->model()->orderBy('quantity', 'desc')->buildData()), $name);
        }
        foreach (['asc'=>['0','a','b'], 'desc'=>['b','a','0']] as $direction => $expected) {
            $this->input(['_sort'=>['column'=>'quantity', 'type'=>$direction, 'cast'=>'CHAR']]);
            $grid = new Grid(new GridRecord());
            $grid->column('quantity')->sortable('NUMERIC(10,2)');
            $this->assertSame($expected, $this->keys($grid->model()->buildData()));
        }
        foreach (['0'=>2, 'a'=>10, 'b'=>3] as $key => $rank) { GridRecord::where('record_key', (string) $key)->update(['payload'=>[$rank]]); }
        $this->input(['_sort'=>['column'=>'payload.[0]', 'type'=>'asc']]);
        $grid = new Grid(new GridRecord()); $grid->column('payload->[0]')->sortable('SIGNED');
        $this->assertSame(['0','b','a'], $this->keys($grid->model()->buildData()));
        $this->input(['_sort'=>['column'=>'unit-price DESC; invalid', 'type'=>'asc']]);
        $grid = new Grid(new GridRecord()); $grid->column('unit-price')->sortable();
        $this->assertSame(['b','a','0'], $this->keys($grid->model()->orderBy('quantity','desc')->buildData()));
    }

}
