<?php

use Encore\Admin\Admin;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Auth\Database\Administrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

if (interface_exists(Spatie\EloquentSortable\Sortable::class)) {
    class OptionalSortableRecord extends Illuminate\Database\Eloquent\Model implements Spatie\EloquentSortable\Sortable
    {
        use Spatie\EloquentSortable\SortableTrait;
        protected $table='optional_sortable';
        protected $guarded=[];
        public $sortable=['order_column_name'=>'order_column','sort_when_creating'=>true];
    }
}
class OptionalIntegrationTest extends TestCase
{
    public function testRealCaptchaChallengeLifecycleAndValidation(): void
    {
        if (!class_exists(Mews\Captcha\Captcha::class)) {
            $this->assertFalse(Composer\InstalledVersions::isInstalled('mews/captcha'));
            return;
        }
        $this->assertSame('3.5.1',Composer\InstalledVersions::getPrettyVersion('mews/captcha'));
        view()->share('errors',new Illuminate\Support\ViewErrorBag());
        // The actual package generates/hashes a deterministic disposable challenge, with real cache expiry.
        config(['captcha.characters'=>['a'],'captcha.default.length'=>5,'captcha.default.width'=>120,'captcha.default.height'=>36,'captcha.default.expire'=>5,'captcha.default.math'=>false,'captcha.default.bgImage'=>false]);
        $form=new Form(new Administrator());$field=$form->captcha('ignored');
        $field->default('default');$this->assertSame('default',$field->value());
        $field->fill(['__captcha__'=>'filled']);$this->assertSame('filled',$field->value());
        $this->assertSame('aaaaa',$field->prepare('aaaaa'));
        $html=(string)$field->render();
        $this->assertStringContainsString('name="__captcha__"',$html);
        $this->assertStringContainsString('id="__captcha__"',$html);
        $this->assertStringContainsString('/captcha/default?',$html);
        $this->assertStringContainsString("split('?')[0]",implode("\n",Admin::$script));
        $first=app('captcha')->create();$this->assertSame(200,$first->getStatusCode());
        $firstHash=session('captcha.key');$firstImage=$first->getContent();
        $this->assertSame(120,getimagesizefromstring($firstImage)[0]);
        $this->assertFalse($field->getValidator(['__captcha__'=>'wrong'])->passes());
        $refresh=app('captcha')->create();$this->assertNotSame($firstHash,session('captcha.key'));
        $this->assertNotSame($firstImage,$refresh->getContent());
        $this->assertTrue($field->getValidator(['__captcha__'=>'aaaaa'])->passes());
        $this->assertFalse(session()->has('captcha'));
        $this->assertFalse($field->getValidator(['__captcha__'=>'aaaaa'])->passes());
        app('captcha')->create();
        try {
            Carbon\Carbon::setTestNow(now()->addSeconds(10));
            $this->assertFalse($field->getValidator(['__captcha__'=>'aaaaa'])->passes());
            $this->assertFalse(session()->has('captcha'));
        } finally {Carbon\Carbon::setTestNow();}
        app('captcha')->create();
        $form->text('username');$form->text('name');$form->password('password');
        request()->replace(['__captcha__'=>'aaaaa','username'=>'captcha-user','name'=>'Captcha user','password'=>'fixture']);
        $form->store();
        $this->assertSame('Captcha user',Administrator::where('username','captcha-user')->value('name'));
        $this->assertNotContains('__captcha__',Schema::getColumnListing(config('admin.database.users_table')));
    }

    public function testActualSpatieOrderingAndRenderedControls(): void
    {
        if (!interface_exists(Spatie\EloquentSortable\Sortable::class)) {
            $grid=new Grid(new Administrator());$grid->column('name')->orderable();
            $this->assertStringContainsString('spatie/eloquent-sortable',$grid->render());
            return;
        }
        Schema::create('optional_sortable',function(Blueprint $table){$table->increments('id');$table->string('name');$table->integer('order_column');$table->timestamps();});
        $a=OptionalSortableRecord::create(['name'=>'A']);$b=OptionalSortableRecord::create(['name'=>'B']);$c=OptionalSortableRecord::create(['name'=>'C']);
        $this->assertSame([1,2,3],OptionalSortableRecord::orderBy('id')->pluck('order_column')->all());
        $form=new Form(new OptionalSortableRecord());
        $form->update($b->id,['_orderable'=>1]);
        $this->assertSame([$b->id,$a->id,$c->id],OptionalSortableRecord::ordered()->pluck('id')->all());
        $form->update($b->id,['_orderable'=>0]);
        $this->assertSame([$a->id,$b->id,$c->id],OptionalSortableRecord::ordered()->pluck('id')->all());
        $grid=new Grid(new OptionalSortableRecord());$grid->column('name')->orderable();
        $this->assertStringContainsString('data-direction="1"',$grid->render());
    }

    public function testActualMinificationManifestExclusionsExternalUrlsRerunAndClear(): void
    {
        if (!class_exists(MatthiasMullie\Minify\Minify::class)) {
            $this->assertSame(1,$this->artisan('admin:minify'));return;
        }
        $css=Admin::$css;$js=Admin::$js;
        try {
            file_put_contents(public_path('extension.css'),'/* custom comment */ .extension { color: red; }');
            file_put_contents(public_path('extension.js'),'/* custom comment */ var extensionValue = 42;');
            Admin::css(['extension.css?version=1','excluded.css','https://example.invalid/external.css','//example.invalid/protocol.css']);
            Admin::js(['extension.js?version=1','excluded.js','https://example.invalid/external.js']);
            config(['admin.minify_assets.excepts'=>['excluded.css','excluded.js']]);
            $this->assertSame(0,$this->artisan('admin:minify'));
            $manifest=json_decode(file_get_contents(public_path(Admin::$manifest)),true,512,JSON_THROW_ON_ERROR);
            $this->assertContains('excluded.css',$manifest['css']);$this->assertContains('excluded.js',$manifest['js']);
            $this->assertContains('https://example.invalid/external.css',$manifest['css']);
            $this->assertContains('//example.invalid/protocol.css',$manifest['css']);
            $this->assertContains('https://example.invalid/external.js',$manifest['js']);
            $this->assertStringContainsString('.extension{color:red}',file_get_contents(public_path(Admin::$min['css'])));
            $this->assertStringContainsString('extensionValue',file_get_contents(public_path(Admin::$min['js'])));
            $this->assertStringNotContainsString('custom comment',file_get_contents(public_path(Admin::$min['js'])));
            $this->assertSame(0,$this->artisan('admin:minify'));
            $this->assertSame($manifest,json_decode(file_get_contents(public_path(Admin::$manifest)),true));
            $this->assertSame(0,$this->artisan('admin:minify',['--clear'=>true]));
            foreach ([Admin::$manifest,...array_values(Admin::$min)] as $path) $this->assertFileDoesNotExist(public_path($path));
        } finally {Admin::$css=$css;Admin::$js=$js;}
    }
}
