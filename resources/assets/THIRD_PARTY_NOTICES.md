# Third-party bundled asset notices

Version and provenance table for every distributed library family. Exact file hashes and original/candidate mapping are in the issue19 asset inventory and `tools/assets/distribution-sha256.json`. Unversioned entries identify the exact historical vendored snapshot rather than claiming an upstream release.

| Library | Distributed version | License | Primary provenance |
| --- | --- | --- | --- |
| Bootstrap RTL | 3.3.7.3 / Bootstrap3.3.7 retained CSS variant | MIT | https://github.com/parsmizban/Bootstrap-RTL |
| Bootstrap | 3.4.1 + Debian CVE2024-6485 and CVE2025-1647 backports | MIT | https://github.com/twbs/bootstrap/releases/tag/v3.4.1 |
| jQuery | 3.8.0 | MIT | https://github.com/jquery/jquery/releases/tag/3.8.0 |
| jQuery slimScroll | 1.3.8 | MIT (chosen dual MIT/GPL) | https://github.com/rochal/jQuery-slimScroll |
| Inputmask | 3.2.8-36 vendored build | MIT | https://github.com/RobinHerbots/Inputmask |
| Ion.RangeSlider | 1.8.2 | MIT | https://github.com/IonDen/ion.rangeSlider |
| Bootstrap Slider | 1.0.1 / legacy CSS snapshot261a97aa | Apache-2.0 | https://github.com/seiyria/bootstrap-slider |
| iCheck (all bundled skins/sprites) | 1.0.1 | MIT | https://github.com/fronteed/icheck |
| Bootstrap Colorpicker | 2.0.0-beta | Apache-2.0 | https://github.com/mjolnic/bootstrap-colorpicker |
| Select2 (all bundled translations; embedded mousewheel adapter) | 4.0.13; embedded mousewheel adapter as distributed | MIT | https://github.com/select2/select2/releases/tag/4.0.13 |
| AdminLTE RTL derivative | 261a97aa snapshot + local-font import correction | MIT | https://github.com/ColorlibHQ/AdminLTE/tree/v2.3.2 |
| AdminLTE (skins and distributed images) | 2.3.2 | MIT | https://github.com/ColorlibHQ/AdminLTE/tree/v2.3.2 |
| Bootstrap Duallistbox | 3.0.6 | Apache-2.0 | https://github.com/istvan-ujjmeszaros/bootstrap-duallistbox |
| JavaScript Canvas to Blob | 2.0.5 | MIT | https://github.com/blueimp/JavaScript-Canvas-to-Blob |
| piexifjs | 1.03 | MIT | https://github.com/hMatoba/piexifjs |
| DOMPurify | 3.4.18 | Apache-2.0 OR MPL-2.0 (choose Apache-2.0) | https://github.com/cure53/DOMPurify/releases/tag/3.4.18 |
| KvSortable fork of SortableJS | 1.7.0 vendored KvSortable snapshot261a97aa | MIT | https://github.com/kartik-v/bootstrap-fileinput/tree/v4.5.2 |
| Bootstrap Fileinput | 4.5.2 | BSD-3-Clause | https://github.com/kartik-v/bootstrap-fileinput/tree/v4.5.2 |
| Bootstrap Switch | 3.3.2 | Apache-2.0 | https://github.com/Bttstrp/bootstrap-switch/tree/v3.3.2 |
| X-editable Bootstrap3 | 1.5.1 | MIT | https://github.com/vitalets/x-editable/tree/1.5.1 |
| Bootstrap DateTimePicker | 4.17.49 | MIT | https://github.com/Eonasdan/bootstrap-datetimepicker/tree/4.17.49 |
| Shortcut Buttons Flatpickr plugin | 261a97aa content-addressed snapshot | MIT | https://github.com/jcsmorais/shortcut-buttons-flatpickr |
| Flatpickr and bundled zh locale | 4.6.13 | MIT | https://github.com/flatpickr/flatpickr/tree/v4.6.13 |
| Font Awesome CSS/fonts | 4.7.0 | CSS MIT; fonts SIL-OFL-1.1 | https://github.com/FortAwesome/Font-Awesome/tree/v4.7.0 |
| Font Awesome Iconpicker with embedded jQuery UI Position | 261a97aa snapshot + jQuery UI1.12.1 offset guard +1.13.0 CVE2021-41184 selector backport | MIT | https://github.com/itsjavi/fontawesome-iconpicker |
| Source Sans Pro fonts/local CSS | 261a97aa content-addressed font variants | SIL-OFL-1.1 | https://github.com/adobe-fonts/source-sans |
| jQuery PJAX | 2.0.1 | MIT | https://github.com/defunkt/jquery-pjax/tree/v2.0.1 |
| Encore Laravel Admin package UI assets | PHP85/Laravel13 candidate | MIT | https://github.com/z-song/laravel-admin |
| Nestable | 261a97aa content-addressed snapshot | MIT (choose dual MIT/BSD) | https://github.com/dbushell/Nestable |
| NProgress | 0.2.0 | MIT | https://github.com/rstacruz/nprogress/tree/v0.2.0 |
| Bootstrap Number Input / bootstrap-spin | 1.0 vendored snapshot261a97aa | Apache-2.0 | https://github.com/wpic/bootstrap-number-input |
| SweetAlert2 | 7.26.12 | MIT | https://github.com/sweetalert2/sweetalert2/tree/v7.26.12 |
| Moment.js and locale bundle | 2.31.0 | MIT | https://github.com/moment/moment/tree/2.31.0 |
| Toastr | 2.1.4 | MIT | https://github.com/CodeSeven/toastr/tree/2.1.4 |

MIT license grants and copyrights remain in original asset banners and named license files. Retained authors include Almsaeed Studio, jQuery Foundation/OpenJS contributors, Twitter/Bootstrap contributors, Chris Wanstrath, David Bushell, Rico Sta. Cruz, Robin Herbots, Damir Sultanov, Denis Ineshin, Stefan Petre, Kyle Kemp/Rohit Kalkur, Javi Aguilar, István Ujj-Mészáros, Kartik Visweswaran, Sebastian Tschan, hMatoba, RubaXa, Mattia Larentis, Vitaliy Potapov, Jonathan Peterson, Flatpickr contributors, João Carlos Morais, Dave Gandy, Adobe, John Papa/Hans Fjällemark/Tim Ferrell, and SweetAlert2/Moment contributors. See original upstream sources and individual file banners for full notices.

The Apache2 license text is in `licenses/Apache-2.0.txt`; BSD3 upload-library notice in `licenses/bootstrap-fileinput.txt`; Source Sans SIL Open Font License in `licenses/Source-Sans-OFL.txt`. Font Awesome CSS is MIT and fonts are SIL OFL1.1. DOMPurify is distributed under its Apache2 option; its original complete dual-license notice is retained. Bootstrap Glyphicons fonts are distributed with the Bootstrap MIT distribution. Existing dual licenses select MIT where permitted.

Bootstrap3.4.1 includes exact Debian CVE2024-6485/CVE2025-1647 backports; jQuery UI Position1.12.1 window/document offset handling and1.13.0 selector-only HTML-of security handling are narrowly backported into the existing Font Awesome4 Iconpicker snapshot. These local corrections do not change upstream ownership or licensing.
