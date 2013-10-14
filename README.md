PackageCompressor
=================

A Javascript/CSS compressor based on Yii's package system.


# Requirements

 * A Java runtime engine must be installed for the YUI compressor

# Features

 * Javascript and CSS compression of [Yii clientscript packages](http://www.yiiframework.com/doc/api/1.1/CClientScript#packages-detail)
    (using YUI compressor)
 * Does not interfere with scripts and CSS registered outside of packages
 * Automatic or manually triggered compression
 * [Locking](http://www.yiiframework.com/extension/mutex) mechanism to prevent multiple concurrent compressions
 * Workaround for the thundering herd problem
 * Command-line maintenance script

You probably wonder,
[why](http://www.yiiframework.com/extension/minscript)
[yet](http://www.yiiframework.com/extension/escriptboost)
[another](http://www.yiiframework.com/extension/clientscriptpacker)
[compression](http://www.yiiframework.com/extension/dynamicres/)
[extension](http://www.yiiframework.com/extension/extendedclientscript)
[for](http://www.yiiframework.com/extension/assetcompiler)
[Yii](http://www.yiiframework.com/extension/eclientscript)?
The key difference is, that none of the existing solutions uses Yii's integrated
[package system](http://www.yiiframework.com/doc/api/1.1/CClientScript#packages-detail)
for clientscripts. With this extension you can organize all your CSS and
Javascript in packages and even define dependencies among them. This may not be
very useful for smaller sites (even though you still can use the compressor there, too).
But it proved to be extremely helpful when you have to deal with many Javascript files
and want to cluster them into a couple of minified package files.

> Note: You can even include Yii's [core scripts](https://github.com/yiisoft/yii/blob/1.1.13/framework/web/js/packages.php)
> in a package. Unfortunately some Zii widgets like `CListView` and `CGridView` still don't
> use the package system packages. But this will hopefully [be fixed](https://github.com/yiisoft/yii/issues/1033)
> in Yii 1.1.15.

# Basic example

Packages are set up in your config file. Javascript and CSS packages must be separate.
Here's an example:

```php
<?php
return array(
    // ...
    'clientScript' => array(
        'class'                 => 'ext.packagecompressor.PackageCompressor'
        'coreScriptPosition'    => 2,   // == POS_END
        'packages' => array(

            'forum-js' => array(
                'baseUrl' => 'js',
                'depends' => array('jQuery', 'maskedinput'),
                'js' => array(
                    'modules/main.js',
                    'modules/editor.js',
                    'modules/forum.post.js',
                    'modules/forum.notify.js',
                ),
            ),
            'forum-css' => array(
                // requires write permission for this directory
                'baseUrl' => 'css',
                'css' => array(
                    'main.css',
                    'forum.css',
                ),
            ),
        ),
    ),
    // ...
);
```

With the packages defined above you can now for example register the forum
packages in the forum section of your site:

    Yii::app()->clientScript->registerPackage('forum-js');
    Yii::app()->clientScript->registerPackage('forum-css');

Your users will receive single js and CSS files until you reset a package with

    ./yiic packages reset


# How does it work?

Whenever a package is registered through `registerPackage()` a single compressed
file is served to the user. If there is no compressed file yet, all files from the
package get combined and compressed, then this single file is published by the asset manager.
The extension uses the application state (which gets cached automatically) to store
information about a compressed package. So after the initial delay during compression
all subsequent requests will get the compressed file delivered lightning fast.

There's also a command line tool to create the compressed file for a package manually,
e.g. at deployment time and to reset a package.

The compressed file name will contain a hash to make sure that no visitor ever gets
and outdated version of a compressed package.


# Installation

Extract the package in `protected/extensions` into a directory called `packagecompressor`
(remove the `-x.y.z` suffix). Then configure the component to replace Yii's
CClientScript component:


*protected/config/main.php*

```php
<?php
return array(
    // ...
    'components' => array(

        'clientScript' => array(
            'class' => 'ext.packagecompressor.PackageCompressor'
        ),
        // ...
    ),
    // ...
);
```

If you want to user the command-line utility, you should make the bundled command
available in your console configuration:

*protected/config/console.php*

```php
<?php
return array(
    // ...
    'commandMap' => array(
        'packages' => array(
            'class' => 'ext.packagecompressor.PackagesCommand',
        ),
        // ...
    ),
    // ...
);
```

# Configuration

Besides the usual `CClientScript` properties the packager adds these other
configuration options, which you can set in your `main.php` config file:

 *  `enableCompression`: Wether compression should be enabled at all. Default is `true`.
    It's recommended to turn this off during development.
*  `enableCssImageFingerPrinting`: Whether to enable automatic fingerprinting on CSS images, e.g. to add `?acd4gd3sz` based on the md5 hash of the image file.
 *  `combineOnly`: Wether all files should only be combined but not compressed. Default is `false`.
    This is very useful to debug packaging issues.
 *  `blockDuringCompression`: Wether other requests should pause during compression.
    Default is `true`. This could sometimes be problematic on websites with heavy load,
    because the mass of paused processes could eat up all server ressources. As a workaround
    you can set this to `false`. In this case during compression any concurrent
    requests will be served the unminified single files instead. If you also don't want
    that, you can still create the minified version from the command line before you deploy.
 *  `javaBin`: The path to your java binary. The default is `java` which assumes that
    the JRE binary is available in your OS' search path (which it usually is on linux systems).


# Command-line maintenance

This component comes with a maintenace command for yiic. It can be used to compress packages,
reset packages or output details about compressed packages from the command line.

> **Note:** It's important that the package configuration from your web configuration is
> also available in your console config. That means, you need have the same `clientScript`
> configuration in your `console.php`. You may want to use a shared include file to do so.
> If you want to compress from the command-line you also need to configure an asset manager
> and an alias for `webroot`. You also need to fix a problem with the `request` component
> which returns '.' as baseUrl on console:
>
> ```php
> <?php
>   'aliases' => array(
>       'webroot' => realpath(__DIR_.'/../..'),
>   ),
>   'components' =>
>       'assetManager'=>array(
>           'class'     =>'CAssetManager',
>           'basePath'  =>realpath(__DIR__.'/../../assets'),
>           'baseUrl'   =>'/assets',
>       ),
>       'request' => array(
>           'baseUrl' => '',
>       ),
>       //...
> ```

> **Note 2:** You need write permissions from the command line to the `state.bin` file in
> your `protected/runtime` directory, if you want to reset packages.

Compress a package:

    ./yiic packages compress --name=<packagename>

Show meta information about a compressed package:

    ./yiic packages info --name=<packagename>

Reset all compressed packages:

    ./yiic packages reset

Reset specific package:

    ./yiic packages reset --name=<packagename>


# Advanced example: CSS assets

CSS files often contain relative URL references to some asset files (images).
So if the compressed CSS file is published to the assets directory, these
paths will be broken (except if we'd also publish the images, which we don't).
As a workaround you can publish a CSS package into the same folder where the
source files reside. Therefore you have to specify a `baseUrl` in your package.

```php
<?php
return array(
    // ...
    'forum-css' => array(
        'baseUrl' => 'css',
        'css' => array(
            'main.css',
            'forum.css',
        ),
    ),
    // ...
)
```

Now you can register this CSS package with

    Yii::app()->clientScript->registerPackage('forum-css');

> **Note:** Here the web server process must have write permissions to the CSS folder.

# Advanced example: media support for CSS

CSS packages can contain a `media` specification which will be used when the package
is registered.

```php
<?php
return array(
    // ...
    'main-css' => array(
        'baseUrl' => 'css',
        'media' => 'screen',
        'css' => array(
            'main.css',
            'forum.css',
        ),
    ),
    'main-css' => array(
        'baseUrl' => 'css',
        'media' => 'print',
        'css' => array(
            'print.css',
        ),
    ),
    // ...
)
```

# Advanced example: jQUery from CDN

If you want to use a CDN for jQuery you can configure it just as you would without
the compressor. Everything will work when you register such a package:

```php
<?php
return array(
    // ...
    'clientScript' => array(
        'class'                 => 'ext.packagecompressor.PackageCompressor'
        'coreScriptPosition'    => 2,   // == POS_END
        'scriptMap' => array(
            'jquery.js' => 'https://ajax.googleapis.com/ajax/libs/jquery/1.8.3/jquery.js',
        ),
        'packages' => array(

            'forum' => array(
                'depends' => array('jQuery', 'maskedinput'),
                'js' => array(
                    // ...
                ),
            ),

        ),
    ),
    // ...
),
```
# Changelog

### 1.0.4

* Fix external URLs that don't have a protocol like `//ajax.googleapis.com/ajax/libs/jquery/1.8.3/jquery.js`

### 1.0.3

* Add `media` support for CSS.

### 1.0.2

* Add [composer](http://getcomposer.org/) support

### 1.0.0

* Initial version
