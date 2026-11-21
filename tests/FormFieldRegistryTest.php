<?php

use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Encore\Admin\Auth\Database\Administrator;

class RegistryControlledField extends Field\Text
{
    protected static $css = ['/controlled.css'];
    protected static $js = ['/controlled.js'];
    public function prepare($value) { return strtoupper($value); }
}

class FormFieldRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        view()->share('errors',new Illuminate\Support\ViewErrorBag());
    }
    public function testEveryRegistryEntryDispatchesWithExactCaseAndControlledExtensionLifecycle(): void
    {
        $original = Form::$availableFields; $aliases = Form::$fieldAlias;
        try {
            $this->assertCount(55,$original);
            foreach ($original as $name=>$class) {
                $this->assertSame($class,Form::findFieldClass($name),$name);
                // Exercise the common dispatcher independently of downstream constructors.
                Form::extend($name,RegistryControlledField::class);
                $dispatched=(new Form(new Administrator()))->$name('controlled','Controlled');
                $this->assertInstanceOf(RegistryControlledField::class,$dispatched,$name.' dispatch');
                $dispatched->fill(['controlled'=>'input']);
                $this->assertSame('INPUT',$dispatched->prepare($dispatched->value()),$name.' common lifecycle');
                Form::extend($name,$class);
                if (strtoupper($name) !== $name) $this->assertFalse(Form::findFieldClass(strtoupper($name)),$name.' case');
            }
            $this->assertSame(Form::findFieldClass('datetimeRange'),Form::findFieldClass('dateTimeRange'));
            $this->assertFalse(Form::findFieldClass('datemultiple'));
            Form::collectFieldAssets();
            Form::extend('controlled',RegistryControlledField::class);
            Form::alias('controlled','controlAlias');
            $form = new Form(new Administrator());
            $field = $form->controlAlias('name','Controlled')->default('default')->rules('required');
            $this->assertInstanceOf(RegistryControlledField::class,$field);
            $this->assertSame('default',$field->value());
            $field->fill(['name'=>'filled']);
            $this->assertSame('filled',$field->value());
            $this->assertSame('PREPARED',$field->prepare('prepared'));
            $this->assertFalse($field->getValidator(['name'=>''])->passes());
            $this->assertStringContainsString('value="filled"',(string)$field->render());
            $assets = Form::collectFieldAssets();
            $this->assertContains('/controlled.css',$assets['css']);
            $this->assertContains('/controlled.js',$assets['js']);
            Form::forget('controlled');
            $this->assertFalse(Form::findFieldClass('controlAlias'));
            $this->assertNotContains('/controlled.js',Form::collectFieldAssets()['js']);
        } finally { Form::$availableFields = $original; Form::$fieldAlias = $aliases; Form::extend('text',Field\Text::class); }
    }
    public function testSimpleScalarFieldsFillPrepareValidationRenderAndAssets(): void
    {
        $cases = [
            'text'=>'<script>value</script>', 'textarea'=>'<script>value</script>', 'password'=>'test-password',
            'hidden'=>'hidden-value', 'email'=>'valid@example.test', 'url'=>'https://example.test',
            'ip'=>'127.0.0.1', 'mobile'=>'0912345678', 'color'=>'#ffffff', 'icon'=>'fa-user',
            'number'=>'0', 'decimal'=>'1.25', 'currency'=>'12.50', 'rate'=>'25', 'slider'=>'0',
            'date'=>'2026-11-21', 'DateMultiple'=>'2026-11-20,2026-11-21', 'datetime'=>'2026-11-21 12:30:00',
            'time'=>'12:30:00', 'month'=>'2026-11', 'year'=>'2026', 'timezone'=>'Asia/Taipei',
            'select'=>'0','radio'=>'0','radioButton'=>'0','radioCard'=>'0',
        ];
        foreach ($cases as $name=>$value) {
            $form = new Form(new Administrator());
            $field = $form->$name('value','Label <b>unsafe</b>');
            if ($field instanceof Field\Select || $field instanceof Field\Radio) $field->options(['0'=>'Zero <script>option</script>','1'=>'One']);
            $field->default('fallback');
            $this->assertSame('fallback',$field->value(),$name.' default');
            $field->fill(['value'=>$value]);
            $this->assertSame($value,$field->value(),$name.' fill');
            $this->assertSame($name==='currency' ? 12.5 : $value,$field->prepare($value),$name.' prepare');
            $field->rules('required');
            $this->assertFalse($field->getValidator(['value'=>''])->passes(),$name.' invalid');
            $this->assertTrue($field->getValidator(['value'=>$value])->passes(),$name.' valid');
            $html = (string)$field->render();
            $this->assertStringContainsString('value',$html,$name.' output');
            $this->assertStringNotContainsString('<script>',$html,$name.' escaped');
            if ($name !== 'hidden') $this->assertStringContainsString('Label &lt;b&gt;unsafe&lt;/b&gt;',$html,$name.' label');
            $this->assertSame($html,(string)$field->render(),$name.' repeated render');
            $assets = $field::getAssets();
            foreach (array_merge($assets['css'] ?? [],$assets['js'] ?? []) as $asset) $this->assertFileExists(__DIR__.'/../resources/assets/'.substr($asset,strlen('/vendor/laravel-admin/')),$name.' asset');
            if (!empty($assets['js'])) {
                $needle = match (true) {
                    $field instanceof Field\RadioButton => 'radio-group-toggle',
                    $field instanceof Field\Radio => 'iCheck',
                    $field instanceof Field\Select => 'select2',
                    $field instanceof Field\DateMultiple => 'flatpickr',
                    $field instanceof Field\Date => 'datetimepicker',
                    $field instanceof Field\Number => 'bootstrapNumber',
                    $field instanceof Field\Currency, $field instanceof Field\Decimal, $field instanceof Field\Mobile, $field instanceof Field\Ip => 'inputmask',
                    $field instanceof Field\Slider => 'ionRangeSlider',
                    $field instanceof Field\Color => 'colorpicker',
                    $field instanceof Field\Icon => 'iconpicker',
                    default => null,
                };
                $this->assertNotNull($needle,$name.' expected initializer');
                $this->assertStringContainsString($needle,$field->getScript().(string) Encore\Admin\Admin::script(),$name.' initializer');
            }
        }
    }
    public function testChoicesZeroTagsCollectionsAndRanges(): void
    {
        foreach (['checkbox','checkboxButton','checkboxCard','multipleSelect','listbox'] as $name) {
            $form = new Form(new Administrator());
            $field=$form->$name('choices','Choices')->options(['0'=>'Zero','1'=>'One'])->default(['0']);
            $this->assertSame(['0'],$field->value(),$name.' default');
            $field->fill(['choices'=>['0','1']]);
            $this->assertSame(['0','1'],$field->value(),$name.' fill');
            $this->assertSame([0=>'0',1=>'1'],$field->prepare(['0','1',null,'']),$name.' prepare zero');
            $field->rules('required');
            $this->assertTrue($field->getValidator(['choices'=>['0']])->passes(),$name.' zero required');
            $this->assertFalse($field->getValidator(['choices'=>['']])->passes(),$name.' required');
            $html=(string)$field->render();
            $this->assertStringContainsString('value="0"',$html,$name);
            $this->assertMatchesRegularExpression('/value="0"[^>]*(selected|checked)/',$html,$name.' zero selected');
            $this->assertSame($html,(string)$field->render(),$name.' repeat');
            $this->assertStringContainsString($field instanceof Field\CheckboxButton ? 'checkbox-group-toggle' : ($name==='listbox' ? 'bootstrapDualListbox' : ($name==='multipleSelect' ? 'select2' : 'iCheck')), $field->getScript().(string) Encore\Admin\Admin::script(), $name.' script');
        }
        $form=new Form(new Administrator());
        $tags=$form->tags('tags','Tags')->default(['0']);
        $this->assertSame(['0'],$tags->value());
        $tags->fill(['tags'=>'0,one']);
        $this->assertSame(['0','one'],$tags->value());
        $this->assertSame('0,one',$tags->prepare(['0','one',null,'']));
        $this->assertStringContainsString('value="0"',(string)$tags->render());
        $this->assertNotSame('',$tags->getScript());
        foreach (['dateRange','dateTimeRange','datetimeRange','timeRange'] as $name) {
            $field=$form->$name('start','end','Range')->default(['start'=>'default-start','end'=>'default-end']);
            $this->assertSame(['start'=>'default-start','end'=>'default-end'],$field->value(),$name.' default');
            $field->fill(['start'=>'2026-11-20','end'=>'2026-11-21']);
            $this->assertSame(['start'=>'2026-11-20','end'=>'2026-11-21'],$field->value());
            $this->assertNull($field->prepare(''));
            $this->assertSame('0',$field->prepare('0'));
            $field->rules('required');
            $this->assertFalse($field->getValidator(['start'=>'','end'=>''])->passes());
            $this->assertSame(['start','end'],array_keys($field->getValidator(['start'=>'','end'=>''])->errors()->toArray()));
            $html=(string)$field->render();
            $this->assertStringContainsString('name="start"',$html);
            $this->assertStringContainsString('name="end"',$html);
            $this->assertSame($html,(string)$field->render());
            $this->assertStringContainsString('datetimepicker',$field->getScript());
            view()->share('errors',(new Illuminate\Support\ViewErrorBag())->put('default',new Illuminate\Support\MessageBag(['start'=>'Start feedback','end'=>'End feedback'])));
            $errorHtml=(string)$field->render();
            $this->assertStringContainsString('has-error',$errorHtml);
            $this->assertStringContainsString('Start feedback',$errorHtml);
            $this->assertStringContainsString('End feedback',$errorHtml);
            view()->share('errors',new Illuminate\Support\ViewErrorBag());
        }
        $key=$form->keyValue('mapping','Mapping')->rules('required')->default(['default'=>'0']);
        $this->assertSame(['default'=>'0'],$key->getDefault());
        $this->assertSame([''=>''],$key->value(),'Legacy keyValue initial blank row precedes defaults');
        $key->fill(['mapping'=>['zero'=>'0']]);
        $this->assertSame(['zero'=>'0'],$key->value());
        $this->assertSame(['zero'=>'0'],$key->prepare(['keys'=>['zero'],'values'=>['0']]));
        $this->assertFalse($key->getValidator(['mapping'=>['keys'=>['a','a'],'values'=>['','x']]])->passes());
        $this->assertStringContainsString('value="zero"',(string)$key->render());
        $list=$form->list('items','Items')->rules('required')->min(1)->max(2)->default(['0']);
        $this->assertSame(['0'],$list->getDefault());
        $this->assertSame([''],$list->value(),'Legacy list initial blank row precedes defaults');
        $list->fill(['items'=>['0','one']]);
        $this->assertSame(['0','one'],$list->value());
        $this->assertSame(['0','one'],$list->prepare(['values'=>[2=>'0',5=>'one']]));
        $this->assertFalse($list->getValidator(['items'=>['values'=>['']]])->passes());
        $this->assertStringContainsString('value="0"',(string)$list->render());
        foreach ([$key,$list] as $field) {
            $this->assertSame((string)$field->render(),(string)$field->render());
            $this->assertStringContainsString('.on(', $field->getScript());
        }
    }
    public function testDefaultsPresentationAndMalformedValues(): void
    {
        $form=new Form(new Administrator());
        $field=$form->text('value')->default(fn ($received)=>$received===$form ? 'default' : 'wrong');
        foreach ([null,'',0,'0',false] as $value) {
            $field->fill(['value'=>$value]);
            $this->assertSame($value===null ? 'default' : $value,$field->value());
            $this->assertSame($value,$field->prepare($value));
        }
        $switch=$form->switch('enabled','Enabled')->default(false)->rules('required');
        $this->assertFalse($switch->value());
        $this->assertTrue($switch->getValidator(['enabled'=>'off'])->passes());
        $this->assertFalse($switch->getValidator(['enabled'=>''])->passes());
        $switch->fill(['enabled'=>0]);
        $this->assertSame(0,$switch->prepare('off'));
        $this->assertSame(1,$switch->prepare('on'));
        $this->assertStringContainsString('value="off"',(string)$switch->render());
        $this->assertSame((string)$switch->render(),(string)$switch->render());
        $display=$form->display('display','Display'); $display->fill(['display'=>'<script>bad</script>']);
        $this->assertStringNotContainsString('<script>',(string)$display->render());
        $this->assertStringContainsString('&lt;script&gt;',(string)$display->render());
        $id=$form->id('id','ID'); $id->fill(['id'=>0]);
        $this->assertStringContainsString('value="0"',(string)$id->render());
        $this->assertTrue($id->isReadOnly());
        $button=$form->button('button','Click'); $button->on('click','return false;');
        $this->assertStringContainsString('type="button"',(string)$button->render());
        $this->assertStringContainsString('click',$button->getScript());
        $this->assertStringContainsString('&lt;script&gt;',(string)$form->divider('<script>')->render());
        $this->assertSame('<b>Trusted</b>',$form->html('<b>Trusted</b>')->plain()->render());
        $this->assertStringContainsString('Trusted',$form->html(fn()=>'<b>Trusted</b>','HTML')->render());
        foreach (['text'=>['bad'],'currency'=>'bad','multipleSelect'=>'bad','tags'=>[['bad']], 'keyValue'=>['keys'=>['a'],'values'=>[]], 'list'=>['values'=>'bad']] as $type=>$bad) {
            $field=$form->$type('malformed');
            $this->assertSame(['malformed'],$field->inputShapeErrors(['malformed'=>$bad]),$type);
        }
    }
}
