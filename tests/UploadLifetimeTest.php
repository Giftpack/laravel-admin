<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class UploadLifetimeRecord extends Model
{
    protected $table = 'upload_lifetime_records';
    protected $guarded = [];
    protected $casts = ['many' => 'array', 'pictures' => 'array'];
    public $timestamps = false;
    public function children() { return $this->hasMany(UploadChildRecord::class,'parent_id'); }
}
class UploadChildRecord extends Model
{
    protected $table='upload_children'; protected $guarded=[]; public $timestamps=false;
}
class GridAvatarFixture extends Administrator {}
class GridAvatarSoftFixture extends GridAvatarFixture { use SoftDeletes; }
class UploadLifetimeSoftRecord extends UploadLifetimeRecord { use SoftDeletes; }

/** Synthetic adapter boundary only; the backing store is disposable local storage. */
class UploadFaultDisk
{
    public array $fail = [];
    public array $calls = [];
    public function __construct(public $disk) {}
    public function __call($method, $arguments)
    {
        $this->calls[] = $method;
        if (isset($this->fail[$method]) && (!is_int($this->fail[$method]) || count(array_filter($this->calls, fn ($call) => $call===$method))===$this->fail[$method])) {
            if ($this->fail[$method] === 'throw') throw new RuntimeException('fixture adapter private detail');
            return false;
        }
        return $this->disk->$method(...$arguments);
    }
    public function url($path) { return 'https://fake-storage.example.test/bucket/'.$path; }
}

class UploadCaptureLogger extends Psr\Log\AbstractLogger
{
    public array $records=[];
    public function log($level, string|Stringable $message, array $context=[]): void { $this->records[]=[$level,(string)$message,$context]; }
}

class UploadLifetimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->be(Administrator::first(), 'admin');
        Schema::create('upload_lifetime_records', function ($t) {
            $t->increments('id'); $t->string('title')->nullable();
            $t->string('document')->nullable(); $t->string('picture')->nullable();
            $t->json('many')->nullable(); $t->json('pictures')->nullable(); $t->softDeletes();
        });
        config(['filesystems.disks.upload-fixture' => ['driver'=>'local', 'root'=>storage_path('upload-lifetime'), 'url'=>'https://uploads.example.test/root'],
            'admin.upload.disk'=>'upload-fixture', 'admin.upload.directory.file'=>'documents', 'admin.upload.directory.image'=>'pictures']);
        Storage::forgetDisk('upload-fixture');
        Storage::disk('upload-fixture')->deleteDirectory('');
        view()->share('errors', new Illuminate\Support\ViewErrorBag());
        $this->requestData([]);
    }
    private function requestData(array $data, string $method = 'POST', array $files = []): void
    {
        $request = Request::create('/admin/upload-records', $method, $data, [], $files, ['HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest']);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request); Illuminate\Support\Facades\Request::swap($request);
    }
    private function png(string $name = 'source.png', int $width = 4, int $height = 2): UploadedFile
    {
        $path = tempnam(storage_path(), 'pixels');
        $im = imagecreatetruecolor($width, $height);
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, 0xff0000);
        imagesetpixel($im, 0, 0, 0x00ff00); imagesetpixel($im, $width - 1, $height - 1, 0x0000ff);
        imagepng($im, $path); imagedestroy($im);
        return new UploadedFile($path, $name, 'image/png', null, true);
    }
    private function form(string $field = 'file', string $column = 'document', $model = null): Form
    {
        $form = new Form($model ?? new UploadLifetimeRecord());
        $form->text('title')->rules('required');
        $form->$field($column)->removable();
        return $form;
    }
    private function pixels(string $path): array
    {
        $im = imagecreatefromstring(Storage::disk('upload-fixture')->get($path));
        return [imagesx($im), imagesy($im), imagecolorat($im,0,0) & 0xffffff, imagecolorat($im,imagesx($im)-1,imagesy($im)-1) & 0xffffff];
    }
    private function original(bool $image = false): UploadLifetimeRecord
    {
        $this->requestData(['title'=>'before'], 'POST', [$image ? 'picture' : 'document'=>$this->png('old.png')]);
        $form = $this->form($image ? 'image' : 'file', $image ? 'picture' : 'document');
        if ($image) $form->fields()->last()->thumbnail('small', 6, 6);
        $this->assertTrue($form->store()->getData(true)['status']);
        return UploadLifetimeRecord::firstOrFail();
    }
    public function testFourFieldsHaveRealLifecycleValidationRenderingAssetsAndUrls(): void
    {
        foreach (['file'=>'document','image'=>'picture','multipleFile'=>'many','multipleImage'=>'pictures'] as $type=>$column) {
            $multiple = str_starts_with($type, 'multiple');
            $form = new Form(new UploadLifetimeRecord()); $field = $form->$type($column, 'Upload label');
            $default = $multiple ? ['documents/default.png'] : 'documents/default.png';
            $field->default($default); $this->assertSame($default, $field->value(), $type.' default');
            $field->fill([$column=>$default]); $this->assertSame($default, $field->value(), $type.' fill');
            $field->setOriginal([$column=>$default]);
            $html = (string)$field->render();
            $this->assertStringContainsString('type="file"', $html);
            $this->assertStringContainsString($column.($multiple ? '[]' : ''), $html);
            $this->assertStringContainsString('https://uploads.example.test/root/documents/default.png', str_replace('\\/', '/', $html.$field->getScript()));
            $this->assertStringContainsString('.fileinput(', $field->getScript());
            foreach (array_merge(...array_values($field::getAssets())) as $asset) {
                $this->assertFileExists(__DIR__.'/../resources/assets/'.explode('?', substr($asset,strlen('/vendor/laravel-admin/')))[0]);
            }
            $this->assertSame('https://external.example.test/x.png', $field->objectUrl('https://external.example.test/x.png'));
            $this->assertTrue($field->getValidator([$column=>$multiple ? [$this->png()] : $this->png()])->passes());
            $file = new UploadedFile(__FILE__, 'bad.txt', 'text/plain', null, true);
            $field->rules('mimes:png');
            $this->assertFalse($field->getValidator([$column=>$multiple ? [$file] : $file])->passes());
            $this->requestData([]);
            $prepared = $field->prepare($multiple ? [$this->png($type.'.png')] : $this->png($type.'.png'));
            $path = $multiple ? end($prepared) : $prepared;
            $this->assertTrue(Storage::disk('upload-fixture')->exists($path));
            $this->assertStringStartsWith(in_array($type,['image','multipleImage']) ? 'pictures/' : 'documents/', $path);
        }
    }
    public function testRootRelativeAndAbsolutePreviewUrlsAreNotPrefixedTwice(): void
    {
        foreach (['/uploads/', 'https://uploads.example.test/root/'] as $baseUrl) {
            config(['filesystems.disks.preview-fixture'=>['driver'=>'local','root'=>storage_path('preview-fixture'),'url'=>$baseUrl]]);
            Storage::forgetDisk('preview-fixture');
            foreach (['file','image','multipleFile','multipleImage'] as $type) {
                $field=(new Form(new UploadLifetimeRecord()))->$type('picture')->disk('preview-fixture');
                $this->assertSame(rtrim($baseUrl,'/').'/images/owned.png',$field->objectUrl('images/owned.png'));
                $this->assertSame('/uploads/images/owned.png',$field->objectUrl('/uploads/images/owned.png'));
                $this->assertSame('https://other.example.test/images/owned.png',$field->objectUrl('https://other.example.test/images/owned.png'));
                $this->assertSame('//cdn.example.test/images/owned.png',$field->objectUrl('//cdn.example.test/images/owned.png'));
                $this->assertSame(storage_path('preview-fixture'),config('filesystems.disks.preview-fixture.root'));
            }
        }
    }
    public function testUploadRemovalUsesExplicitFormActionAndOrdinaryEditResource(): void
    {
        $record=UploadLifetimeRecord::create(['title'=>'existing','picture'=>'pictures/owned.png']);
        session()->regenerateToken();
        foreach ([null,'/admin/auth/setting'] as $action) {
            $form=$this->form('image','picture');$form->edit($record->id);
            if ($action!==null) $form->setAction($action);
            $field=$form->fields()->last();$field->render();
            $this->assertSame(1,preg_match('/\.fileinput\((\{[^\r\n]*\})\);/',$field->getScript(),$matches));
            $options=json_decode($matches[1],true,512,JSON_THROW_ON_ERROR);
            $this->assertSame($action??$form->resource().'/'.$record->id,$options['deleteUrl']);
            $this->assertSame('PUT',$options['deleteExtraData']['_method']);
            $this->assertSame(csrf_token(),$options['deleteExtraData']['_token']);
            $this->assertSame('_file_del_',$options['deleteExtraData']['picture']);
            $this->assertSame('',$options['deleteExtraData']['_file_del_']);
            $this->assertSame(0,$options['initialPreviewConfig'][0]['key']);
        }
    }
    public function testDefaultGridActionUsesKnownAvatarOwnershipModelConnectionAndCommitBoundary(): void
    {
        config(['database.connections.upload_admin'=>config('database.connections.'.config('database.default')),
            'admin.database.connection'=>'upload_admin','admin.database.users_model'=>GridAvatarFixture::class]);
        $disk=Storage::disk('upload-fixture');$disk->put('pictures/grid.png','owned');
        $record=GridAvatarFixture::create(['username'=>'grid-avatar','name'=>'Grid avatar','password'=>'unused','avatar'=>'pictures/grid.png']);
        $connection=$record->getConnection();$cancel=false;
        GridAvatarFixture::deleting(function () use (&$cancel) {return $cancel?false:null;});
        try {
            $cancel=true;$this->assertFalse((new Encore\Admin\Grid\Actions\Delete())->handle($record)->status);
            $this->assertNotNull($record->fresh());$this->assertTrue($disk->exists('pictures/grid.png'));
            $cancel=false;$connection->beginTransaction();
            $this->assertTrue((new Encore\Admin\Grid\Actions\Delete())->handle($record->fresh())->status);
            $this->assertTrue($disk->exists('pictures/grid.png'));$this->assertNull($record->newQuery()->find($record->id));
            $connection->rollBack();$this->assertNotNull($record->fresh());$this->assertTrue($disk->exists('pictures/grid.png'));
            $connection->beginTransaction();
            $this->assertTrue((new Encore\Admin\Grid\Actions\Delete())->handle($record->fresh())->status);
            $this->assertTrue($disk->exists('pictures/grid.png'));$connection->commit();
            $this->assertFalse($disk->exists('pictures/grid.png'));$this->assertNull($record->fresh());
        } finally {GridAvatarFixture::flushEventListeners();}
    }
    public function testDefaultGridActionRetainsSoftDeletedAndUnrelatedHostAvatarsAndHidesFailures(): void
    {
        Schema::table(config('admin.database.users_table'),fn ($t) => $t->softDeletes());
        config(['admin.database.users_model'=>GridAvatarSoftFixture::class]);
        $disk=Storage::disk('upload-fixture');$disk->put('pictures/soft-grid.png','owned');
        $soft=GridAvatarSoftFixture::create(['username'=>'soft-grid','name'=>'Soft grid','password'=>'unused','avatar'=>'pictures/soft-grid.png']);
        $this->assertTrue((new Encore\Admin\Grid\Actions\Delete())->handle($soft)->status);
        $this->assertTrue($soft->fresh()->trashed());$this->assertTrue($disk->exists('pictures/soft-grid.png'));
        Schema::table('upload_lifetime_records',fn ($t) => $t->string('avatar')->nullable());
        $disk->put('pictures/host-grid.png','host-owned');$host=UploadLifetimeRecord::create(['avatar'=>'pictures/host-grid.png']);
        $this->assertTrue((new Encore\Admin\Grid\Actions\Delete())->handle($host)->status);
        $this->assertNull($host->fresh());$this->assertTrue($disk->exists('pictures/host-grid.png'));
        $failed=UploadLifetimeRecord::create(['title'=>'failed']);
        UploadLifetimeRecord::deleting(fn () => throw new Error('private SQL/storage credential detail'));
        try {
            $result=(new Encore\Admin\Grid\Actions\Delete())->handle($failed);
            $this->assertFalse($result->status);$json=$result->send()->getContent();
            $this->assertStringNotContainsString('private SQL',$json);$this->assertStringNotContainsString('credential',$json);
            $this->assertNotNull($failed->fresh());
        } finally {UploadLifetimeRecord::flushEventListeners();}
    }
    public function testCustomUniqueSequenceNamesMoveRootVisibilityAndCollisionPreserveExisting(): void
    {
        $root = config('filesystems.disks.upload-fixture.root');
        $field = (new Form(new UploadLifetimeRecord()))->file('document')->move('custom', 'fixed.png')->storagePermission('private');
        $this->assertSame('custom/fixed.png',$field->prepare($this->png()));
        $this->assertSame('private',Storage::disk('upload-fixture')->getVisibility('custom/fixed.png'));
        $this->assertNotSame('custom/fixed.png',$field->prepare($this->png()));
        $this->assertTrue(Storage::disk('upload-fixture')->exists('custom/fixed.png'));
        $field->move('', 'root.png')->storagePermission('public');
        $this->assertSame('root.png',$field->prepare($this->png()));
        $this->assertSame('public',Storage::disk('upload-fixture')->getVisibility('root.png'));
        $field->name(fn ($file) => 'callback.'.$file->getClientOriginalExtension());
        $this->assertSame('callback.png',$field->prepare($this->png()));
        $field->uniqueName(); $first=$field->prepare($this->png()); $second=$field->prepare($this->png());
        $this->assertNotSame($first,$second); $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/',$first);
        $sequence=(new Form(new UploadLifetimeRecord()))->file('document')->sequenceName();
        $this->assertNotSame($sequence->prepare($this->png()),$sequence->prepare($this->png()));
        $this->assertSame($root,config('filesystems.disks.upload-fixture.root'));
        foreach ([['../escape','x.png'],['good','../x.png'],['/absolute','x.png'],['good','nested/x.png']] as [$dir,$name]) {
            try { $field=(new Form(new UploadLifetimeRecord()))->file('document')->move($dir,$name); $field->prepare($this->png()); $this->fail('Unsafe path accepted'); }
            catch (InvalidArgumentException $e) { $this->assertStringContainsString('Upload',$e->getMessage()); }
        }
    }
    public function testMalformedInputsAndForgedDeleteReorderNeverWriteOrBypassOtherValidation(): void
    {
        foreach (['file'=>'document','image'=>'picture','multipleFile'=>'many','multipleImage'=>'pictures'] as $type=>$column) {
            foreach (['forged/path.png', ['bad'], [['bad']], 42] as $bad) {
                $this->requestData(['title'=>'valid',$column=>$bad]);
                $form=$this->form($type,$column);
                $this->assertFalse($form->store()->getData(true)['status'], $type.' malformed');
                $this->assertSame(0,UploadLifetimeRecord::count());
            }
        }
        $record=$this->original(); $old=$record->document;
        $this->requestData(['title'=>'','document'=>'_file_del_','_file_del_'=>'','key'=>0], 'PUT');
        $this->assertFalse($this->form()->update($record->id)->getData(true)['status']);
        $this->assertSame($old,$record->fresh()->document);
        $this->assertTrue(Storage::disk('upload-fixture')->exists($old));
        $this->requestData(['title'=>'valid','document'=>'_file_del_','_file_del_'=>'','key'=>['bad']], 'PUT');
        $this->assertFalse($this->form()->update($record->id)->getData(true)['status']);
        $this->assertSame($old,$record->fresh()->document);
    }
    public function testReplacementRemovalAndRetainOnlyCleanOldFilesAfterSuccessfulWrite(): void
    {
        $record=$this->original(true); $old=$record->picture;
        $this->requestData(['title'=>'after'], 'PUT', ['picture'=>$this->png('new.png')]);
        $form=$this->form('image','picture');$form->fields()->last()->thumbnail('small',6,6);
        $form->update($record->id);$new=$record->fresh()->picture;
        $this->assertNotSame($old,$new);
        foreach ([$old,str_replace('.png','-small.png',$old)] as $path) $this->assertFalse(Storage::disk('upload-fixture')->exists($path));
        foreach ([$new,str_replace('.png','-small.png',$new)] as $path) $this->assertTrue(Storage::disk('upload-fixture')->exists($path));
        $this->requestData(['picture'=>'_file_del_','_file_del_'=>'','key'=>0], 'PUT');
        $form=$this->form('image','picture'); $form->fields()->last()->thumbnail('small',6,6)->retainable();
        $form->update($record->id);
        $this->assertNull($record->fresh()->picture);$this->assertTrue(Storage::disk('upload-fixture')->exists($new));
        $this->assertTrue(Storage::disk('upload-fixture')->exists(str_replace('.png','-small.png',$new)));
        $record->update(['picture'=>$new]);
        $this->requestData(['picture'=>'_file_del_','_file_del_'=>'','key'=>0], 'PUT');
        $form=$this->form('image','picture');$form->fields()->last()->thumbnail('small',6,6);$form->update($record->id);
        $this->assertNull($record->fresh()->picture);$this->assertFalse(Storage::disk('upload-fixture')->exists($new));
    }
    public function testValidationAndDatabaseFailurePreserveOriginalAndThumbnailsAndCleanNewWrites(): void
    {
        $record=$this->original(true); $old=$record->picture; $before=Storage::disk('upload-fixture')->allFiles(); sort($before);
        foreach (['validation','database','remove-database'] as $failure) {
            $data=['title'=>$failure==='validation'?'':'after']; $files=['picture'=>$this->png('new.png')];
            if ($failure==='remove-database') {$data+=['picture'=>'_file_del_','_file_del_'=>'','key'=>0];$files=[];}
            $this->requestData($data,'PUT',$files);
            $form=$this->form('image','picture');$form->fields()->last()->thumbnail('small',6,6);
            if ($failure!=='validation') UploadLifetimeRecord::saved(fn () => throw new RuntimeException('fixture database failure'));
            try {
                $res=$form->update($record->id);
                $this->assertSame('validation',$failure);$this->assertFalse($res->getData(true)['status']);
            } catch (RuntimeException $e) { $this->assertSame('fixture database failure',$e->getMessage()); }
            finally { UploadLifetimeRecord::flushEventListeners(); }
            $this->assertSame($old,$record->fresh()->picture);
            $after=Storage::disk('upload-fixture')->allFiles();sort($after);$this->assertSame($before,$after);
        }
    }
    public function testCreateDatabaseFailureCleansAllOwnedImageAndThumbnailWrites(): void
    {
        $this->requestData(['title'=>'new'],'POST',['picture'=>$this->png('create.png')]);
        $form=$this->form('image','picture');$form->fields()->last()->thumbnail('small',6,6);
        UploadLifetimeRecord::saved(fn () => throw new RuntimeException('create database failure'));
        try {$form->store();$this->fail('Expected create failure');}
        catch (RuntimeException $e) {$this->assertSame('create database failure',$e->getMessage());}
        finally {UploadLifetimeRecord::flushEventListeners();}
        $this->assertSame(0,UploadLifetimeRecord::count());$this->assertSame([],Storage::disk('upload-fixture')->allFiles());
    }
    public function testCancelledCreateUpdateDeleteAndForceDeletePreserveFilesAndDatabase(): void
    {
        foreach (['create','update'] as $operation) {
            $record=$operation==='update'?$this->original(true):null;
            $disk=Storage::disk('upload-fixture');$before=$disk->allFiles();sort($before);
            $this->requestData(['title'=>'after'],$record?'PUT':'POST',['picture'=>$this->png('cancelled.png')]);
            $form=$this->form('image','picture');$form->fields()->last()->thumbnail('small',6,6);
            UploadLifetimeRecord::saving(fn () => false);
            try {$record?$form->update($record->id):$form->store();$this->fail('Expected cancellation');}
            catch (RuntimeException $e) {$this->assertSame('Record could not be saved.',$e->getMessage());}
            finally {UploadLifetimeRecord::flushEventListeners();}
            $after=$disk->allFiles();sort($after);$this->assertSame($before,$after);
            $this->assertSame($record?1:0,UploadLifetimeRecord::count());
            if ($record) $this->assertSame('before',$record->fresh()->title);
        }
        $old=$record->picture;
        foreach (['delete','forceDelete'] as $operation) {
            if ($operation==='forceDelete') UploadLifetimeSoftRecord::find($record->id)->delete();
            $class=$operation==='delete'?UploadLifetimeRecord::class:UploadLifetimeSoftRecord::class;
            $class::{$operation==='delete'?'deleting':'forceDeleting'}(fn () => false);
            $this->requestData([],'DELETE');$form=$this->form('image','picture',new $class());$form->fields()->last()->thumbnail('small',6,6);
            try {$this->assertFalse($form->destroy((string)$record->id)->getData(true)['status']);}
            finally {$class::flushEventListeners();}
            $this->assertNotNull($operation==='forceDelete'?$class::withTrashed()->find($record->id):$class::find($record->id));
            $this->assertTrue($disk->exists($old));$this->assertTrue($disk->exists(str_replace('.png','-small.png',$old)));
        }
    }
    public function testEnclosingTransactionCommitAndRollbackOwnFileLifetime(): void
    {
        $record=$this->original(true);$old=$record->picture;$connection=$record->getConnection();
        foreach ([false,true] as $commit) {
            $connection->beginTransaction();
            $this->requestData(['title'=>'after'],'PUT',['picture'=>$this->png('new.png')]);
            $form=$this->form('image','picture');$form->fields()->last()->thumbnail('small',6,6);$form->update($record->id);
            $new=$record->fresh()->picture;
            $this->assertTrue(Storage::disk('upload-fixture')->exists($old));
            $commit ? $connection->commit() : $connection->rollBack();
            $this->assertSame($commit?$new:$old,$record->fresh()->picture);
            $this->assertSame(!$commit,Storage::disk('upload-fixture')->exists($old));
            $this->assertSame($commit,Storage::disk('upload-fixture')->exists($new));
        }
    }
    public function testMultipleAppendReorderSubsetRemovalAndRecordDeletionIncludeThumbnails(): void
    {
        $this->requestData(['title'=>'multi'],'POST',['pictures'=>[$this->png('one.png'),$this->png('two.png')]]);
        $form=$this->form('multipleImage','pictures');$form->fields()->last()->thumbnail('small',6,6);$form->store();
        $record=UploadLifetimeRecord::first(); $old=$record->pictures;
        $this->requestData([], 'PUT',['pictures'=>[$this->png('three.png')]]);
        $form=$this->form('multipleImage','pictures');$form->fields()->last()->thumbnail('small',6,6);$form->update($record->id);
        $paths=$record->fresh()->pictures;$this->assertCount(3,$paths);
        foreach ($old as $path) $this->assertTrue(Storage::disk('upload-fixture')->exists(str_replace('.png','-small.png',$path)));
        foreach (['0,0,1','0','0,1,9', ['bad']] as $bad) {
            $this->requestData(['_file_sort_'=>['pictures'=>$bad]],'PUT');
            $this->assertFalse($this->form('multipleImage','pictures')->update($record->id)->getData(true)['status']);
            $this->assertSame($paths,$record->fresh()->pictures);
        }
        $this->requestData(['_file_sort_'=>['pictures'=>'2,0,1']],'PUT');$this->form('multipleImage','pictures')->update($record->id);
        $this->assertSame([$paths[2],$paths[0],$paths[1]],$record->fresh()->pictures);
        $this->requestData(['pictures'=>'_file_del_','_file_del_'=>'','key'=>1],'PUT');
        $form=$this->form('multipleImage','pictures');$form->fields()->last()->thumbnail('small',6,6);$form->update($record->id);
        $this->assertCount(2,$record->fresh()->pictures);$this->assertFalse(Storage::disk('upload-fixture')->exists($paths[0]));
        $this->requestData([], 'DELETE');$form=$this->form('multipleImage','pictures');$form->fields()->last()->thumbnail('small',6,6);
        $this->assertTrue($form->destroy((string)$record->id)->getData(true)['status']);
        $this->assertSame([],Storage::disk('upload-fixture')->allFiles());
    }
    public function testSoftHardRetainedAndFailedBatchDeletion(): void
    {
        $record=$this->original();$old=$record->document;
        $this->requestData([], 'DELETE');
        $this->assertFalse($this->form()->destroy($record->id.',404')->getData(true)['status']);
        $this->assertNotNull($record->fresh());$this->assertTrue(Storage::disk('upload-fixture')->exists($old));
        $form=$this->form('file','document',new UploadLifetimeSoftRecord());
        $this->assertTrue($form->destroy((string)$record->id)->getData(true)['status']);
        $this->assertTrue(Storage::disk('upload-fixture')->exists($old));
        $response=$form->destroy((string)$record->id);$this->assertTrue($response->getData(true)['status'],$response->getContent());
        $this->assertFalse(Storage::disk('upload-fixture')->exists($old));
        $record=$this->original();$old=$record->document;$this->requestData([], 'DELETE');
        $form=$this->form();$form->fields()->last()->retainable();$form->destroy((string)$record->id);
        $this->assertTrue(Storage::disk('upload-fixture')->exists($old));
    }
    public function testAdapterReadWriteThumbnailAndPostCommitDeleteFailuresHaveControlledOwnership(): void
    {
        $record=$this->original(true);$old=$record->picture;
        $disk=Storage::disk('upload-fixture');$fake=new UploadFaultDisk($disk);Storage::set('fake-remote',$fake);
        foreach ([['exists'=>'throw'],['putFileAs'=>false],['putFileAs'=>'throw'],['put'=>false],['put'=>'throw']] as $fault) {
            $fake->fail=$fault;
            $this->requestData(['title'=>'after'],'PUT',['picture'=>$this->png('new.png')]);
            $form=$this->form('image','picture');$field=$form->fields()->last()->disk('fake-remote')->thumbnail('small',6,6);
            $this->assertSame('https://fake-storage.example.test/bucket/'.$old,$field->objectUrl($old));
            try {$form->update($record->id);$this->fail('Expected controlled storage failure');}
            catch (Encore\Admin\Form\UploadException $e) {
                $res=$e->render(request());$this->assertSame(503,$res->getStatusCode());
                $this->assertStringNotContainsString('private detail',$res->getContent());
            }
            $this->assertSame($old,$record->fresh()->picture);
            $paths=$disk->allFiles();sort($paths);$expected=[$old,str_replace('.png','-small.png',$old)];sort($expected);$this->assertSame($expected,$paths);
        }
        $fake->fail=['delete'=>false];$this->requestData(['title'=>'after'],'PUT',['picture'=>$this->png('new.png')]);
        $form=$this->form('image','picture');$form->fields()->last()->disk('fake-remote')->thumbnail('small',6,6);
        $this->assertTrue($form->update($record->id)->getData(true)['status']);
        $this->assertNotSame($old,$record->fresh()->picture);$this->assertTrue($disk->exists($old));
        $this->assertTrue($disk->exists($record->fresh()->picture));
        $this->assertContains('delete',$fake->calls);
    }
    public function testMultipleCustomNamesAndLaterWriteFailurePreserveOriginalList(): void
    {
        foreach (['multipleFile','multipleImage'] as $type) {
            $field=(new Form(new UploadLifetimeRecord()))->$type('many')->move($type)->name(fn ($file) => 'custom-'.$file->getClientOriginalName());
            if ($type==='multipleImage') $field->thumbnail('small',6,6);
            $paths=$field->prepare([$this->png('one.png'),$this->png('two.png')]);
            $this->assertSame([$type.'/custom-one.png',$type.'/custom-two.png'],$paths);
        }
        Storage::disk('upload-fixture')->put('documents/old.png','old contents');
        $record=UploadLifetimeRecord::create(['title'=>'before','many'=>['documents/old.png']]);
        $disk=Storage::disk('upload-fixture');$before=$disk->allFiles();sort($before);
        $fake=new UploadFaultDisk($disk);$fake->fail=['putFileAs'=>2];Storage::set('fake-remote',$fake);
        $this->requestData(['title'=>'after'],'PUT',['many'=>[$this->png('new-one.png'),$this->png('new-two.png')]]);
        $form=$this->form('multipleFile','many');$form->fields()->last()->disk('fake-remote');
        try {$form->update($record->id);$this->fail('Expected second write failure');}
        catch (Encore\Admin\Form\UploadException $e) {}
        $after=$disk->allFiles();sort($after);$this->assertSame($before,$after);
        $this->assertSame(['documents/old.png'],$record->fresh()->many);$this->assertSame('before',$record->fresh()->title);
    }
    public function testNestedChildUploadRollbackUsesParentConnectionAndKeepsExistingChildImage(): void
    {
        Schema::create('upload_children',function ($t) {$t->increments('id');$t->integer('parent_id');$t->string('picture')->nullable();});
        $parent=UploadLifetimeRecord::create(['title'=>'before']);
        $disk=Storage::disk('upload-fixture');$disk->put('pictures/child.png','existing child');
        $disk->put('pictures/child-small.png','existing thumbnail');
        $child=UploadChildRecord::create(['parent_id'=>$parent->id,'picture'=>'pictures/child.png']);
        $before=$disk->allFiles();sort($before);
        $make=function () {
            $f=$this->form();$f->hasMany('children',fn ($n) => $n->image('picture')->thumbnail('small',6,6));return $f;
        };
        $this->requestData(['title'=>'after','children'=>['row'=>['id'=>$child->id]]],'PUT',
            ['document'=>$this->png('parent.png'),'children'=>['row'=>['picture'=>$this->png('replacement.png')]]]);
        UploadChildRecord::saved(fn () => throw new RuntimeException('nested SQL failure'));
        try {$make()->update($parent->id);$this->fail('Expected nested failure');}
        catch (RuntimeException $e) {$this->assertSame('nested SQL failure',$e->getMessage());}
        finally {UploadChildRecord::flushEventListeners();}
        $after=$disk->allFiles();sort($after);$this->assertSame($before,$after);
        $this->assertSame('before',$parent->fresh()->title);$this->assertSame('pictures/child.png',$child->fresh()->picture);
        $this->assertTrue($make()->update($parent->id)->getData(true)['status']);
        $this->assertNotSame('pictures/child.png',$child->fresh()->picture);
        $this->assertFalse($disk->exists('pictures/child.png'));$this->assertFalse($disk->exists('pictures/child-small.png'));
    }
    public function testNestedOriginalLookupUsesScopedHiddenIdentityOrOmittedEmptyFallback(): void
    {
        Schema::create('upload_children',function ($t) {$t->increments('id');$t->integer('parent_id');$t->string('picture')->nullable();});
        $parent=UploadLifetimeRecord::create(['title'=>'before']);$disk=Storage::disk('upload-fixture');
        foreach (['present','omitted','empty'] as $mode) {
            $path='pictures/old-'.$mode.'.png';$disk->put($path,'old');
            $child=UploadChildRecord::create(['parent_id'=>$parent->id,'picture'=>$path]);
            $key=$mode==='present'?'ui-row':$child->id;
            $row=$mode==='present'?['id'=>$child->id]:($mode==='empty'?['id'=>'']:[]);
            $this->requestData(['children'=>[$key=>$row]],'PUT',['children'=>[$key=>['picture'=>$this->png($mode.'.png')]]]);
            $form=$this->form();$form->hasMany('children',fn ($n) => $n->image('picture'));
            $this->assertTrue($form->update($parent->id)->getData(true)['status']);
            $this->assertFalse($disk->exists($path),$mode.' original cleanup');
            $this->assertTrue($disk->exists($child->fresh()->picture));
        }
    }
    public function testRollbackCleanupFailureRetainsOldDataAndLogsOwnedNewPath(): void
    {
        $record=$this->original();$old=$record->document;$disk=Storage::disk('upload-fixture');
        $fake=new UploadFaultDisk($disk);$fake->fail=['delete'=>'throw'];Storage::set('fake-remote',$fake);
        $originalLogger=app('log');$logger=new UploadCaptureLogger();app()->instance('log',$logger);
        $this->requestData(['title'=>'after'],'PUT',['document'=>$this->png('failed-new.png')]);
        $form=$this->form();$form->fields()->last()->disk('fake-remote');
        UploadLifetimeRecord::saved(fn () => throw new RuntimeException('database original failure'));
        try {$form->update($record->id);$this->fail('Expected database failure');}
        catch (RuntimeException $e) {$this->assertSame('database original failure',$e->getMessage());}
        finally {UploadLifetimeRecord::flushEventListeners();app()->instance('log',$originalLogger);}
        $this->assertSame($old,$record->fresh()->document);$this->assertTrue($disk->exists($old));
        $this->assertTrue($disk->exists('documents/failed-new.png'));
        $this->assertCount(1,$logger->records);
        [$level,$message,$context]=$logger->records[0];
        $this->assertSame('warning',$level);$this->assertStringContainsString('cleanup failed',$message);
        $this->assertSame('fake-remote',$context['disk']);$this->assertSame('documents/failed-new.png',$context['path']);

    }
    public function testRelatedMultipleUploadsCreateAppendRemoveAndRollbackOnConfiguredConnection(): void
    {
        Schema::create('upload_children',function ($t) {$t->increments('id');$t->integer('parent_id');$t->string('picture')->nullable();});
        config(['database.connections.uploads'=>config('database.connections.'.config('database.default'))]);
        foreach (['multipleFile','multipleImage'] as $type) {
            $make=function () use ($type) {
                $f=new Form((new UploadLifetimeRecord())->setConnection('uploads'));$f->text('title');
                $field=$f->$type('children')->pathColumn('picture')->removable();
                if ($type==='multipleImage') $field->thumbnail('small',6,6);
                return $f;
            };
            $this->requestData(['title'=>$type],'POST',['children'=>[$this->png($type.'-first.png')]]);
            $response=$make()->store();$this->assertTrue($response->getData(true)['status']);
            $parent=UploadLifetimeRecord::where('title',$type)->firstOrFail();$first=$parent->children()->firstOrFail();
            $this->assertTrue(Storage::disk('upload-fixture')->exists($first->picture));
            $this->requestData([],'PUT',['children'=>[$this->png($type.'-second.png')]]);
            $this->assertTrue($make()->update($parent->id)->getData(true)['status']);
            $this->assertSame(2,$parent->children()->count());$this->assertSame($first->picture,$first->fresh()->picture);
            $this->assertSame('uploads',$make()->model()->children()->getRelated()->getConnectionName());
            $before=Storage::disk('upload-fixture')->allFiles();sort($before);
            UploadChildRecord::saved(fn () => throw new RuntimeException('related upload database failure'));
            $this->requestData([],'PUT',['children'=>[$this->png($type.'-failed.png')]]);
            try {$make()->update($parent->id);$this->fail('Expected related write failure');}
            catch (RuntimeException $e) {$this->assertSame('related upload database failure',$e->getMessage());}
            finally {UploadChildRecord::flushEventListeners();}
            $after=Storage::disk('upload-fixture')->allFiles();sort($after);$this->assertSame($before,$after);
            $this->assertSame(2,$parent->children()->count());
            $other=UploadLifetimeRecord::create(['title'=>'other']);$foreign=UploadChildRecord::create(['parent_id'=>$other->id,'picture'=>'foreign.png']);
            $this->requestData(['children'=>'_file_del_','_file_del_'=>'','key'=>$foreign->id],'PUT');
            $this->assertFalse($make()->update($parent->id)->getData(true)['status']);$this->assertNotNull($foreign->fresh());
            $this->requestData(['children'=>'_file_del_','_file_del_'=>'','key'=>$first->id],'PUT');
            $this->assertTrue($make()->update($parent->id)->getData(true)['status']);
            $this->assertNull($first->fresh());$this->assertSame(1,$parent->children()->count());
            $this->assertFalse(Storage::disk('upload-fixture')->exists($first->picture));
            if ($type==='multipleImage') $this->assertFalse(Storage::disk('upload-fixture')->exists(str_replace('.png','-small.png',$first->picture)));
            $this->assertNotNull($foreign->fresh());
        }
    }
    public function testRequiredNestedFileAndImagePreserveScopedExistingUploadsButRequireNewRows(): void
    {
        Schema::create('upload_children',function ($t) {$t->increments('id');$t->integer('parent_id');$t->string('picture')->nullable();});
        foreach (['file','image'] as $type) {
            $parent=UploadLifetimeRecord::create(['title'=>'before']);$path='pictures/required-'.$type.'.png';Storage::disk('upload-fixture')->put($path,'old');
            $child=UploadChildRecord::create(['parent_id'=>$parent->id,'picture'=>$path]);
            $other=UploadLifetimeRecord::create(['title'=>'foreign']);$foreign=UploadChildRecord::create(['parent_id'=>$other->id,'picture'=>'foreign.png']);
            $make=function () use ($type) {$f=new Form(new UploadLifetimeRecord());$f->text('title');$f->hasMany('children',fn ($n) => $n->$type('picture')->rules('required'));return $f;};
            foreach (['present','omitted','empty'] as $identity) {
                $key=$identity==='present'?'ui-row':$child->id;$row=$identity==='present'?['id'=>$child->id]:($identity==='empty'?['id'=>'']:[]);
                $this->requestData(['title'=>'after','children'=>[$key=>$row]],'PUT');
                $response=$make()->update($parent->id);$this->assertTrue($response->getData(true)['status'],$response->getContent());
                $this->assertSame($path,$child->fresh()->picture);$this->assertTrue(Storage::disk('upload-fixture')->exists($path));
            }
            foreach ([['new_1'=>[]],['new_1'=>['id'=>'']],['row'=>['id'=>$foreign->id]]] as $rows) {
                $this->requestData(['title'=>'invalid','children'=>$rows],'PUT');
                $this->assertFalse($make()->update($parent->id)->getData(true)['status']);
                $this->assertSame('after',$parent->fresh()->title);$this->assertSame($path,$child->fresh()->picture);
            }
            $this->assertSame(1,$parent->children()->count());$this->assertNotNull($foreign->fresh());
        }
    }
    public function testPixelFlipFlopRotateThumbnailWhiteBackgroundAndEncoding(): void
    {
        foreach (['flip-v'=>['flip',['v'],0xff0000,0xff0000,4,2], 'flip-h'=>['flip',['h'],0xff0000,0xff0000,4,2],
            'flip'=>['flip',[],0xff0000,0xff0000,4,2], 'flop'=>['flop',[],0xff0000,0xff0000,4,2],
            'rotate'=>['rotate',[90],0xff0000,0xff0000,2,4]] as $name=>[$method,$args,$top,$bottom,$w,$h]) {
            $field=(new Form(new UploadLifetimeRecord()))->image('picture')->move('pixels',$name.'.png');$field->$method(...$args);
            $path=$field->prepare($this->png());$pixels=$this->pixels($path);
            $this->assertSame([$w,$h],array_slice($pixels,0,2));
            $this->assertSame($top,$pixels[2]);$this->assertSame($bottom,$pixels[3]);
            $im=imagecreatefromstring(Storage::disk('upload-fixture')->get($path));
            if (in_array($name,['flip','flip-v'])) $this->assertSame(0x00ff00,imagecolorat($im,0,1)&0xffffff);
            if (in_array($name,['flip-h','flop'])) $this->assertSame(0x00ff00,imagecolorat($im,3,0)&0xffffff);
            if ($name==='rotate') $this->assertSame(0x00ff00,imagecolorat($im,0,3)&0xffffff);
        }
        foreach (['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp'] as $ext=>$mime) {
            $field=(new Form(new UploadLifetimeRecord()))->image('picture')->move('encoded','format.'.$ext)->rotate(0)->thumbnail('small',32,32);
            $path=$field->prepare($this->png());$thumbnail=str_replace('.'.$ext,'-small.'.$ext,$path);
            foreach ([$path,$thumbnail] as $file) $this->assertSame($mime,getimagesizefromstring(Storage::disk('upload-fixture')->get($file))['mime']);
            $pixels=$this->pixels($thumbnail);$this->assertSame([32,32],array_slice($pixels,0,2));$this->assertTrue(min(($pixels[2] >> 16)&255,($pixels[2] >> 8)&255,$pixels[2]&255) >= ($ext==='png' ? 255 : 230), 'Thumbnail white background '.$ext);
        }
    }
    public function testExifOrientationIsAppliedBeforeThumbnails(): void
    {
        $png=$this->png();$im=imagecreatefrompng($png->getRealPath());ob_start();imagejpeg($im,null,100);$jpeg=ob_get_clean();
        // Minimal little-endian TIFF: Orientation=6 (90 degrees clockwise).
        $exif="Exif\0\0II\x2a\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0\x06\0\0\0\0\0\0\0";
        $jpeg=substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2);
        $path=tempnam(storage_path(),'oriented');file_put_contents($path,$jpeg);
        $file=new UploadedFile($path,'orientation.jpg','image/jpeg',null,true);
        $field=(new Form(new UploadLifetimeRecord()))->image('picture')->rotate(0)->thumbnail('small',6,6);
        $stored=$field->prepare($file);$this->assertSame([2,4],array_slice($this->pixels($stored),0,2));
        $thumb=$this->pixels(str_replace('.jpg','-small.jpg',$stored));$this->assertSame([6,6],array_slice($thumb,0,2));
    }
}
