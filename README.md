<p align="center">
<a href="https://laravel-admin.org/">
<img src="https://laravel-admin.org/images/logo002.png" alt="laravel-admin">
</a>

<p align="center">⛵<code>laravel-admin</code> is administrative interface builder for laravel which can help you build CRUD backends just with few lines of code.</p>

<p align="center">
<a href="https://laravel-admin.org/docs">Documentation</a> |
<a href="https://laravel-admin.org/docs/zh">中文文档</a> |
<a href="https://demo.laravel-admin.org">Demo</a> |
<a href="https://github.com/z-song/demo.laravel-admin.org">Demo source code</a> |
<a href="#extensions">Extensions</a>
</p>

<p align="center">
    <a href="https://styleci.io/repos/48796179">
        <img src="https://styleci.io/repos/48796179/shield" alt="StyleCI">
    </a>
    <a href="https://packagist.org/packages/encore/laravel-admin">
        <img src="https://img.shields.io/packagist/l/encore/laravel-admin.svg?maxAge=2592000&&style=flat-square" alt="Packagist">
    </a>
    <a href="https://packagist.org/packages/encore/laravel-admin">
        <img src="https://img.shields.io/packagist/dt/encore/laravel-admin.svg?style=flat-square" alt="Total Downloads">
    </a>
    <a href="https://github.com/z-song/laravel-admin">
        <img src="https://img.shields.io/badge/Awesome-Laravel-brightgreen.svg?style=flat-square" alt="Awesome Laravel">
    </a>
    <a href="#backers" alt="sponsors on Open Collective">
        <img src="https://opencollective.com/laravel-admin/backers/badge.svg?style=flat-square" />
    </a> 
    <a href="https://www.paypal.me/zousong" alt="Paypal donate">
        <img src="https://img.shields.io/badge/Donate-Paypal-green.svg?style=flat-square" />
    </a> 
</div>

<p align="center">
    Inspired by <a href="https://github.com/sleeping-owl/admin" target="_blank">SleepingOwlAdmin</a> and <a href="https://github.com/zofe/rapyd-laravel" target="_blank">rapyd-laravel</a>.
</p>

Sponsor
------------

<a href="https://ter.li/32ifxj">
<img src="https://user-images.githubusercontent.com/1479100/102449272-dc356880-406e-11eb-9079-169c8c2af81c.png" alt="laravel-admin" width="200px;">
</a>


Requirements and candidate status
------------
This unpublished 2.0 candidate targets PHP `~8.5.13`, Laravel `^13.40` and the Symfony 7.4 component floors in composer.json. Executed versions: PHP8.5.13, Laravel13.40.0/13.43.0, PostgreSQL15.14/MySQL8.4.13. No other framework/Symfony/database line is certified. Fileinfo, mbstring, DOM/XML and the chosen PDO driver are required; image transforms and XLSX need their optional packages/extensions.

Installation and existing-host upgrade
------------
Follow [English installation](docs/en/installation.md) / [中文安裝](docs/zh/installation.md). The candidate has not been tagged or published; `^2.0` examples apply after approved publication, and local review uses a path repository. Fresh installs publish config, migrations, assets and languages before `admin:install`.

Existing Laravel12 hosts must follow [English upgrade and rollback](docs/en/upgrade.md) / [中文升級與回復](docs/zh/upgrade.md). Preserve IDs/data/customizations; do not regenerate/reseed existing installations. Mandatory migrations include POST+CSRF logout, signed generic handler contexts with explicit policies, selective hash-aware asset publication including the new jQuery path, and cache/CDN invalidation. Custom Excel4 subclasses and CSV consumers need the documented API/wire changes. Same-install PHP8.4.28/Laravel12.25→PHP8.5.13/Laravel13.43 and actual backup rollback are rehearsed on both engines.

Optional tested packages: Intervention Image3.11.0/3.11.10, Excel4.0.7/PhpSpreadsheet5.11.0, MewsCaptcha3.5.1, SpatieSortable5.0.1 and Minify1.3.76. Development BrowserKit7.3/PHPUnit12.5/Mockery1.6.15 are separate from runtime requirements. See [test/CI instructions](tests/README.md) and [changelog](CHANGELOG.md). Local workflow-equivalent commands are executed; remote CI is unrun until an authorized push.

Configurations
------------
The file `config/admin.php` contains an array of configurations, you can find the default configurations in there.

Right to left support
------------
Configure the base CSS list in the host admin bootstrap with `Admin::baseCss(...)`, replacing the Bootstrap and AdminLTE entries with their distributed RTL variants. Keep the remaining configured styles and host overrides, and generate/publish the matching RTL minified bundle when used. The tested Arabic RTL path retains local fonts; see [upgrade instructions](docs/en/upgrade.md).

## Extensions

The following is a historical third-party catalog, with its original compatibility labels. These external extensions have **not** been certified for this candidate; each requires an explicit consumer compatibility review. Live cloud providers and historical MongoDB integration are likewise unresolved prerequisites.

| Extension                                        | Description                              | laravel-admin                              |
| ------------------------------------------------ | ---------------------------------------- |---------------------------------------- |
| [helpers](https://github.com/laravel-admin-extensions/helpers)             | Several tools to help you in development | ~1.5 |
| [media-manager](https://github.com/laravel-admin-extensions/media-manager) | Provides a web interface to manage local files          | ~1.5 |
| [api-tester](https://github.com/laravel-admin-extensions/api-tester) | Help you to test the local laravel APIs          |~1.5 |
| [scheduling](https://github.com/laravel-admin-extensions/scheduling) | Scheduling task manager for laravel-admin          |~1.5 |
| [redis-manager](https://github.com/laravel-admin-extensions/redis-manager) | Redis manager for laravel-admin          |~1.5 |
| [backup](https://github.com/laravel-admin-extensions/backup) | An admin interface for managing backups          |~1.5 |
| [log-viewer](https://github.com/laravel-admin-extensions/log-viewer) | Log viewer for laravel           |~1.5 |
| [config](https://github.com/laravel-admin-extensions/config) | Config manager for laravel-admin          |~1.5 |
| [reporter](https://github.com/laravel-admin-extensions/reporter) | Provides a developer-friendly web interface to view the exception          |~1.5 |
| [wangEditor](https://github.com/laravel-admin-extensions/wangEditor) | A rich text editor based on [wangeditor](http://www.wangeditor.com/)         |~1.6 |
| [summernote](https://github.com/laravel-admin-extensions/summernote) | A rich text editor based on [summernote](https://summernote.org/)          |~1.6 |
| [china-distpicker](https://github.com/laravel-admin-extensions/china-distpicker) | 一个基于[distpicker](https://github.com/fengyuanchen/distpicker)的中国省市区选择器          |~1.6 |
| [simplemde](https://github.com/laravel-admin-extensions/simplemde) | A markdown editor based on [simplemde](https://github.com/sparksuite/simplemde-markdown-editor)          |~1.6 |
| [phpinfo](https://github.com/laravel-admin-extensions/phpinfo) | Integrate the `phpinfo` page into laravel-admin          |~1.6 |
| [php-editor](https://github.com/laravel-admin-extensions/php-editor) <br/> [python-editor](https://github.com/laravel-admin-extensions/python-editor) <br/> [js-editor](https://github.com/laravel-admin-extensions/js-editor)<br/> [css-editor](https://github.com/laravel-admin-extensions/css-editor)<br/> [clike-editor](https://github.com/laravel-admin-extensions/clike-editor)| Several programing language editor extensions based on code-mirror          |~1.6 |
| [star-rating](https://github.com/laravel-admin-extensions/star-rating) | Star Rating extension for laravel-admin          |~1.6 |
| [json-editor](https://github.com/laravel-admin-extensions/json-editor) | JSON Editor for Laravel-admin          |~1.6 |
| [grid-lightbox](https://github.com/laravel-admin-extensions/grid-lightbox) | Turn your grid into a lightbox & gallery          |~1.6 |
| [daterangepicker](https://github.com/laravel-admin-extensions/daterangepicker) | Integrates daterangepicker into laravel-admin          |~1.6 |
| [material-ui](https://github.com/laravel-admin-extensions/material-ui) | Material-UI extension for laravel-admin          |~1.6 |
| [sparkline](https://github.com/laravel-admin-extensions/sparkline) | Integrates jQuery sparkline into laravel-admin          |~1.6 |
| [chartjs](https://github.com/laravel-admin-extensions/chartjs) | Use Chartjs in laravel-admin          |~1.6 |
| [echarts](https://github.com/laravel-admin-extensions/echarts) | Use Echarts in laravel-admin          |~1.6 |
| [simditor](https://github.com/laravel-admin-extensions/simditor) | Integrates simditor full-rich editor into laravel-admin          |~1.6 |
| [cropper](https://github.com/laravel-admin-extensions/cropper) | A simple jQuery image cropping plugin.          |~1.6 |
| [composer-viewer](https://github.com/laravel-admin-extensions/composer-viewer) | A web interface of composer packages in laravel.          |~1.6 |
| [data-table](https://github.com/laravel-admin-extensions/data-table) | Advanced table widget for laravel-admin |~1.6 |
| [watermark](https://github.com/laravel-admin-extensions/watermark) | Text watermark for laravel-admin |~1.6 |
| [google-authenticator](https://github.com/ylic/laravel-admin-google-authenticator) | Google authenticator |~1.6 |



## Contributors
 This project exists thanks to all the people who contribute. [[Contribute](CONTRIBUTING.md)].
<a href="graphs/contributors"><img src="https://opencollective.com/laravel-admin/contributors.svg?width=890&button=false" /></a>
 ## Backers
 Thank you to all our backers! 🙏 [[Become a backer](https://opencollective.com/laravel-admin#backer)]
 <a href="https://opencollective.com/laravel-admin#backers" target="_blank"><img src="https://opencollective.com/laravel-admin/backers.svg?width=890"></a>
 ## Sponsors
 Support this project by becoming a sponsor. Your logo will show up here with a link to your website. [[Become a sponsor](https://opencollective.com/laravel-admin#sponsor)]
 <a href="https://opencollective.com/laravel-admin/sponsor/0/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/0/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/1/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/1/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/2/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/2/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/3/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/3/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/4/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/4/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/5/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/5/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/6/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/6/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/7/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/7/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/8/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/8/avatar.svg"></a>
<a href="https://opencollective.com/laravel-admin/sponsor/9/website" target="_blank"><img src="https://opencollective.com/laravel-admin/sponsor/9/avatar.svg"></a>

Other
------------
`laravel-admin` based on following plugins or services:

+ [Laravel](https://laravel.com/)
+ [AdminLTE](https://adminlte.io/)
+ [Datetimepicker](http://eonasdan.github.io/bootstrap-datetimepicker/)
+ [font-awesome](http://fontawesome.io)
+ [moment](http://momentjs.com/)
+ [Google map](https://www.google.com/maps)
+ [Tencent map](http://lbs.qq.com/)
+ [bootstrap-fileinput](https://github.com/kartik-v/bootstrap-fileinput)
+ [jquery-pjax](https://github.com/defunkt/jquery-pjax)
+ [Nestable](http://dbushell.github.io/Nestable/)
+ [toastr](http://codeseven.github.io/toastr/)
+ [X-editable](http://github.com/vitalets/x-editable)
+ [bootstrap-number-input](https://github.com/wpic/bootstrap-number-input)
+ [fontawesome-iconpicker](https://github.com/itsjavi/fontawesome-iconpicker)
+ [sweetalert2](https://github.com/sweetalert2/sweetalert2)

License
------------
`laravel-admin` is licensed under [The MIT License (MIT)](LICENSE).
