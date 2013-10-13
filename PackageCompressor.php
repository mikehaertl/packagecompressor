<?php
/**
 * PackageCompressor
 *
 * A Javascript and CSS compressor based on Yii's package system.
 *
 * @author Michael Härtl <haertl.mike@gmail.com>
 * @version 1.0.4
 */
class PackageCompressor extends CClientScript
{
    /**
     * @var bool wether to enable package compression
     */
    public $enableCompression = true;

    /**
     * @var bool wether to only combine, not compress (useful for debugging)
     */
    public $combineOnly = false;

    /**
     * Only effective when $enableCompression is set to "true"
     *
     * @var bool wheter to rewrite URLs in CSS files to an absolute path before combining
     */
    public $rewriteCssUris = false;

     * Note: Workaround for https://github.com/yiisoft/yii/issues/1033
     */
    public $copyCssImages = false;

    /**
     * If this is enabled, during compression all other requests will wait until the compressing
     * process has completed. If disabled, the uncompressed files will be delivered for these
     * requests. This should prevent the thundering herd problem.
     *
     * @var bool wether other requests should pause during compression. On by default.
     */
    public $blockDuringCompression = true;

    /**
     * @var string name or path of/to the JAVA executable. Default is 'java'.
     */
    public $javaBin = 'java';

    /**
     * @var array names of packages that where registered on current page
     */
    private $_registeredPackages = array();

    /**
     * @var mixed meta information about the compressed packages
     */
    private $_pd;

    /**
     * @var mixed the EMutex component
     */
    private $_mutex;

    // Locking parameter to prevent parallel compression
    const LOCK_ID       = '_PackageCompressor';
    const LOCK_TIMEOUT  = 15;

    // YUI compressor jar name
    const YUI_COMPRESSOR_JAR = 'yuicompressor-2.4.7.jar';

    /**
     * Used internally: Create a compressed version of all package files, publish the
     * compressed file through asset manager and store compressedInfo. Js and CSS will
     * be processed independently.
     *
     * @param string $name of package
     */
    public function compressPackage($name)
    {
        // Backup registered scripts, css and core scripts, as we only want to
        // catch the files contained in the package, not those registered from elsewhere
        $coreScripts        = $this->coreScripts;
        $scriptFiles        = $this->scriptFiles;
        $cssFiles           = $this->cssFiles;

        $this->coreScripts = $this->cssFiles = $this->scriptFiles = array();

        // Now we register only the package and let yii resolve dependencies
        // and expand coreScripts into scriptFiles (usually happens during rendering)
        $this->registerCoreScript($name);
        $this->renderCoreScripts();

        // Copied from CClientScript: process the scriptMap and remove duplicates
        if(!empty($this->scriptMap))
            $this->remapScripts();
        $this->unifyScripts();

        $info       = array();
        $am         = Yii::app()->assetManager;
        $basePath   = realpath(Yii::getPathOfAlias('webroot'));

        // /www/root/sub -> /www/root   (baseUrl=/sub)
        if(($baseUrl = Yii::app()->request->baseUrl)!=='')
            $basePath = substr($basePath,0,-strlen($baseUrl));

        // Process all JS files from the package (if any)
        if(isset($this->scriptFiles[$this->coreScriptPosition]))
        {
            $scripts    = array();
            $urls       = array();
            foreach($this->scriptFiles[$this->coreScriptPosition] as $script)
                if (strtolower(substr($script,0,4))==='http' || substr($script,0,2)==='//') // Exclude external scripts
                    $urls[] = $script;
                else
                    $scripts[] = $basePath.$script;   // '/www/root'.'/sub/js/some.js'

            if ($scripts!==array())
            {
                $fileName = $this->compressFiles($name,'js',$scripts);
                $urls[] = $am->publish($fileName,true);    // URL to compressed file

                // Remove current package name from meta data
                $core = array_keys($this->coreScripts);
                if(($key = array_search($name,$core))!==false)
                    unset($core[$key]);

                $info['js'] = array(
                    'file'          => $am->getPublishedPath($fileName,true), // path to compressed file
                    'files'         => $scripts,
                    'urls'          => $urls,
                    'coreScripts'   => $core,
                );
                unlink($fileName);
            }
        }

        // Process all CSS files from the package (if any)
        if ($this->cssFiles!==array())
        {
            $files  = array();
            $urls   = array();

            foreach(array_keys($this->cssFiles) as $file) {
                if ($this->rewriteCssUris) {
                    $inFile = $basePath.$file;
                    $outFile = $basePath.$file.'-rewrite.css';
                    file_put_contents($outFile, Minify_CSS_UriRewriter::rewrite(file_get_contents($inFile), dirname($inFile), $basePath));
                } else {
                    $outFile = $basePath.$file;
                }
                $files[] = $outFile;
            }

            $fileName = $this->compressFiles($name,'css',$files);
            if(isset($this->packages[$name]['baseUrl']))
            {
                // If a CSS package uses 'baseUrl' we do not use the asset publisher
                // because this could break CSS URLs. Instead we copy to baseUrl:
                $url = '/'.trim($this->packages[$name]['baseUrl'],'/').'/'.basename($fileName);

                // '/www/root'.'/sub/'.'/css/some.css'
                $destFile = $basePath.$baseUrl.$url;

                copy($fileName, $destFile);
                $urls[] = $baseUrl.$url;  // '/sub'.'/css/some.css'
            }
            else
            {
                $urls[] = $am->publish($fileName,true);    // URL to compressed file
                $destFile = $am->getPublishedPath($fileName,true);
            }

            // copy images
            if ($this->copyCssImages) foreach (array_keys($this->cssFiles) as $file) {
                CFileHelper::copyDirectory(
                    dirname($basePath . $file),
                    dirname($destFile),
                    array('fileTypes' => array('jpg', 'png', 'gif'))
                );
            }

            $info['css'] = array(
                'file'  => $destFile,
                'files' => $files,
                'urls'  => $urls,
                'media' => isset($this->packages[$name]['media']) ? $this->packages[$name]['media'] : '',
            );
            unlink($fileName);
        }

        // Store package meta info
        if($info!==array())
            $this->setCompressedInfo($name,$info);

        // Restore original coreScripts, scriptFiles and cssFiles
        $this->coreScripts  = $coreScripts;
        $this->scriptFiles  = $scriptFiles;
        $this->cssFiles     = $cssFiles;
    }

    /**
     * Override CClientScript::registerPackage() to initialize the compression algorithm
     *
     * @param string $name Name of Package to register
     * @return CClientScript the CClientScript object itself
     */
    public function registerPackage($name)
    {
        if ($this->enableCompression && !in_array($name,$this->_registeredPackages))
        {
            $this->hasScripts = true;
            $this->_registeredPackages[$name] = $name;

            // Create compressed package if not done so yet
            if(($info = $this->getCompressedInfo($name))===null)
            {
                $mutex = $this->getMutex();

                // Compresssion must only be performed once, even for several parallel requests
                while(!$mutex->lock(self::LOCK_ID,self::LOCK_TIMEOUT))
                    if($this->blockDuringCompression)
                        sleep(1);
                    else
                        return parent::registerPackage($name);

                // We have a Mutex lock, now check if another process already compressed this package
                if ($this->getCompressedInfo($name,true)!==null) {
                    $mutex->unlock();
                    return $this;
                }

                $this->compressPackage($name);

                $mutex->unlock();

            }
            return $this;
        } else
            return parent::registerPackage($name);
    }

    /**
     * Override CClientScript::render() to add compressed package files if available.
     *
     * @param string $output the existing output that needs to be inserted with script tags
     */
    public function render(&$output)
    {
        if(!$this->hasScripts)
            return;

        $packages = $this->_registeredPackages;
        if($this->enableCompression)
            foreach($packages as $package)
                $this->unregisterPackagedCoreScripts($package);

        $this->renderCoreScripts();

        if(!empty($this->scriptMap))
            $this->remapScripts();

        // Register package files as *first* files always
        if($this->enableCompression)
            foreach(array_reverse($this->_registeredPackages) as $package)
                $this->renderCompressedPackage($package);

        $this->unifyScripts();

        $this->renderHead($output);
        if($this->enableJavaScript)
        {
            $this->renderBodyBegin($output);
            $this->renderBodyEnd($output);
        }

    }

    /**
     * Delete cached package file
     *
     * @param string (optional) $name of package
     */
    public function resetCompressedPackage($name=null)
    {
        if($this->_pd===null)
            $this->loadPackageData();

        if($name===null)
            $packages = $this->_pd;
        elseif(isset($this->_pd[$name]))
            $packages = array($name=>$this->_pd[$name]);
        else
            $packages = array();

        if($packages===array())
            return false;

        foreach($packages as $package => $info)
        {
            if(isset($info['js']['file']))
                @unlink($info['js']['file']);
            if(isset($info['css']['file']))
                @unlink($info['css']['file']);
            unset($this->_pd[$package]);
        }

        $this->savePackageData();

        return true;
    }

    /**
     * If a compressed package is available, will return an array of this format:
     *
     *  array(
     *      'js'=>array(
     *          'file'          =>'/path/to/compressed/file',
     *          'files'         => <list of original file names>
     *          'urls'          => <list of script URLs (incl. external)>
     *          'coreScripts'   => <list of core scripts contained in this package>
     *      ),
     *      'css'=>array(
     *          'file'          =>'/path/to/compressed/file',
     *          'urls'          => <list of script URLs (incl. external)>
     *      ),
     *
     * @param string name of package to load
     * @param bool wether to enforce that package data is read again from global state
     * @return mixed array with compressed package information or null if none
     */
    public function getCompressedInfo($name,$forceRefresh=false)
    {
        if ($this->_pd===null || $forceRefresh)
            $this->loadPackageData($forceRefresh);

        $i=isset($this->_pd[$name]) ? $this->_pd[$name] : null;

        // Safety check: Verify that compressed files exist
        if( isset($i['js']['file']) && !file_exists($i['js']['file']) ||
            isset($i['css']['file']) && !file_exists($i['css']['file']))
        {
            $this->setCompressedInfo($name,null);
            YII_DEBUG && Yii::trace(
                sprintf(
                    "Remove %s:\n%s\n%s",
                    $name,
                    isset($i['js']) ? $i['js']['file'] : '-',
                    isset($i['css']) ? $i['css']['file'] : '-'
                ),
                'application.components.packagecompressor'
            );
            $i=null;
        }

        return $i;
    }

    /**
     * @return array list of compressed package names
     */
    public function getCompressedPackageNames()
    {
        if ($this->_pd===null)
            $this->loadPackageData();

        return array_keys($this->_pd);
    }

    /**
     * @return EMutex the mutex component from the bundled EMutext extension
     */
    public function getMutex()
    {
        if($this->_mutex===null)
            $this->_mutex = Yii::createComponent(array(
                'class'     => 'ext.packagecompressor.EMutex',
                'mutexFile' => Yii::app()->runtimePath.'/packagecompressor_mutex.bin',
            ));

        return $this->_mutex;
    }

    /**
     * @return string unique key per application
     */
    public function getStateKey()
    {
        return '__packageCompressor:'.Yii::app()->getId();
    }

    /**
     * Combine the set of given text files into one file
     *
     * @param string $name of package
     * @param array $files list of files to combine (full path)
     * @param bool $jsSafe wether to append a semicolon after every file
     * @return string full path name of combined file
     */
    private function combineFiles($name,$files,$jsSafe=false)
    {
        $fileName = tempnam(Yii::app()->runtimePath,'combined_'.$name);
        foreach($files as $f)
            if(!file_put_contents($fileName, file_get_contents($f).($jsSafe ? ';':'')."\n", FILE_APPEND))
                throw new CException(sprintf(
                    'Could not combine combine file "%s" into "%s"',
                    $f,
                    $fileName
                ));

        return $fileName;
    }

    /**
     * Create a compressed file using YUI compressor (requires JRE!)
     *
     * @param string $name of package
     * @param string $type of package, either js or css
     * @param array $files list of full file paths to files
     * @return string file name of compressed file
     */
    private function compressFiles($name,$type,$files)
    {
        YII_DEBUG && Yii::trace(sprintf(
            "Compressing %s package %s:\n%s",
            $type,
            $name,
            implode(",\n",$files)
        ),'application.components.packagecompressor');

        $inFile = $this->combineFiles($name,$files,$type==='js');
        $outFile = sprintf(
            '%s/%s_pkg_%s_%s.%s',
            Yii::app()->runtimePath,
            $type,
            $name,
            md5_file($inFile),
            $type
        );

        $jar = Yii::getPathOfAlias('ext.packagecompressor.yuicompressor').DIRECTORY_SEPARATOR.self::YUI_COMPRESSOR_JAR;
        // See http://developer.yahoo.com/yui/compressor/
        $command = sprintf("%s -jar %s --type %s -o %s %s",escapeshellarg($this->javaBin),escapeshellarg($jar),$type,escapeshellarg($outFile),escapeshellarg($inFile));

        if($this->combineOnly)
            copy($inFile,$outFile);
        else
        {
            exec($command,$output,$result);

            if ($result!==0)
                throw new CException(sprintf(
                    "Could not create compressed $type file. Maybe missing a JRE?\nCommand was:\n%s",
                    $command
                ));
        }

        unlink($inFile);
        return $outFile;
    }

    /**
     * Load meta information about compressed packages from global state
     *
     * @param bool wether to enforce that global state is refreshed
     */
    private function loadPackageData($forceRefresh=false)
    {
        // Make sure, statefile is read in again. It could have been changed from
        // another request, while we were waiting for the mutex lock
        if ($forceRefresh)
            Yii::app()->loadGlobalState();

        $this->_pd = Yii::app()->getGlobalState($this->getStateKey(),array());
    }

    /**
     * Replace all sripts registered at $coreScriptPosition with compressed file
     */
    private function renderCompressedPackage($name)
    {
        if(($package = $this->getCompressedInfo($name))===null)
            return;

        if(isset($package['js']))
        {
            $p = $this->coreScriptPosition;

            // Keys in scriptFiles must be equal to value to make unifyScripts work:
            $packageFiles = array_combine($package['js']['urls'],$package['js']['urls']);

            $this->scriptFiles[$p]=isset($this->scriptFiles[$p]) ?
                array_merge($packageFiles,$this->scriptFiles[$p]) : $packageFiles;

            YII_DEBUG && Yii::trace(
                sprintf(
                    "Render compressed js package '%s'\nContains:\n%s\nURLs:\n%s\nCoreScripts: %s",
                    $name,
                    implode(",\n",$package['js']['files']),
                    implode(",\n",$package['js']['urls']),
                    implode(", ",$package['js']['coreScripts'])
                ),
                'application.components.packagecompressor'
            );
        }

        if(isset($package['css']))
        {
            $cssFiles = $this->cssFiles;

            $urls = $this->cssFiles = array();

            foreach($package['css']['urls'] as $url)
                $this->cssFiles[$url] = $package['css']['media'];

            foreach($cssFiles as $url => $media)
                $this->cssFiles[$url] = $media;

            YII_DEBUG && Yii::trace(
                sprintf(
                    "Render compressed css package '%s'\nContains:\n%s\nURLs:\n%s",
                    $name,
                    implode(",\n",$package['css']['files']),
                    implode(",\n",$package['css']['urls'])
                ),
                'application.components.packagecompressor'
            );
        }
    }

    /**
     * Remove any registered core script and packages if we have it in the package to prevent publishing
     *
     * @param string $name of package
     */
    private function unregisterPackagedCoreScripts($package)
    {
        if (($info = $this->getCompressedInfo($package))===null || !isset($info['js']))
            return;

        // Remove the package itself from the coreScripts or it would
        // still render the uncompressed script files
        unset($this->coreScripts[$package]);

        // Also remove the coreScripts contained in the package
        foreach($info['js']['coreScripts'] as $name) {
            unset($this->coreScripts[$name]);
            unset($this->_registeredPackages[$name]);
        }
    }

    /**
     * Save meta information about compressed packages to global state
     */
    private function savePackageData()
    {
        Yii::app()->setGlobalState($this->getStateKey(),$this->_pd);

        // We want to be sure that global state is written immediately. Default would be onEndRequest.
        Yii::app()->saveGlobalState();
    }

    /**
     * Stores meta information about compressed package to cache and global state
     *
     * @param mixed Array of format array('file'=>...,'urls'=>...) or null to reset
     */
    private function setCompressedInfo($name,$value)
    {
        if($this->_pd===null)
            $this->loadPackageData();

        if($value!==null)
            $this->_pd[$name]=$value;
        elseif(isset($this->_pd[$name]))
            unset($this->_pd[$name]);

        $this->savePackageData();
    }
}
